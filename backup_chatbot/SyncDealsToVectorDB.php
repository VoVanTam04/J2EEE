<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Deal;
use App\Services\ChatGPTService;
use App\Services\QdrantService;

class SyncDealsToVectorDB extends Command
{
    protected $signature = 'vector:sync-deals';
    protected $description = 'Sync all deals to Qdrant Vector DB';

    public function handle(ChatGPTService $chatGPTService, QdrantService $qdrantService)
    {
        $this->info('Starting sync to Qdrant...');
        
        // Ensure collection exists
        $this->info('Checking/Creating Collection...');
        $qdrantService->createCollection();

        $deals = Deal::query()->where('is_display', true)->get();
        $this->info('Found ' . $deals->count() . ' deals.');

        $points = [];
        $batchSize = 50;

        foreach ($deals as $deal) {
            $textParts = [];

            if (isset($deal->translations)) {
                foreach ($deal->translations as $lang => $trans) {
                    $title = $this->sanitize($trans['title'] ?? '');
                    $companyName = $this->sanitize($trans['company_name'] ?? '');
                    $specialize = $this->sanitize($trans['specialize'] ?? '');
                    
                    $description = html_entity_decode(strip_tags($trans['description'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $description = mb_substr(trim(preg_replace('/\s+/', ' ', $description)), 0, 300, 'UTF-8');
                    if ($title) {
                        $langText = "{$title}";
                        if ($companyName) $langText .= " ({$companyName})";
                        if ($specialize) $langText .= " - Ngành: {$specialize}";
                        if ($description) $langText .= ". {$description}";
                        
                        $textParts[] = $langText;
                    }
                }
            }
            $text = implode(". ", $textParts);
            // Build multilingual strings for all payload fields (vi | en | ja)
            $ml = function (string $field) use ($deal): string {
                $parts = array_filter([
                    $deal->translations['vi'][$field] ?? '',
                    $deal->translations['en'][$field] ?? '',
                    $deal->translations['ja'][$field] ?? '',
                ]);
                return implode(' | ', array_unique($parts));
            };

            $payload = [
                'deal_id'      => (int)$deal->id,
                'title'        => $this->sanitize($ml('title')),
                'company_name' => $this->sanitize($ml('company_name')),
                'location'     => $this->sanitize($ml('address')),   // address từ translations
                'industry'     => $this->sanitize($ml('specialize')),
                'demand_type'  => (int)$deal->demand,
                'level'        => (int)$deal->priority,
                'text_preview' => mb_substr($text, 0, 200, 'UTF-8'),
            ];

            $this->info("Generating embedding for Deal #{$deal->id}...");
            $embedding = $chatGPTService->getEmbedding($text);

            if (!empty($embedding)) {
                $points[] = [
                    'id' => (int)$deal->id, 
                    'vector' => $embedding,
                    'payload' => $payload
                ];
            }


            // Batch upsert
            if (count($points) >= $batchSize) {
                $this->info('Upserting batch of ' . count($points) . '...');
                $result = $qdrantService->upsert($points);
                if (!$result) {
                    $this->error('Failed to upsert batch.');
                } else {
                    $this->info('Batch upserted successfully.');
                }
                $points = [];
            }
        }

        if (!empty($points)) {
            $this->info('Upserting remaining batch of ' . count($points) . '...');
            $result = $qdrantService->upsert($points);
            if (!$result) {
                $this->error('Failed to upsert remaining batch.');
            } else {
                $this->info('Remaining batch upserted successfully.');
            }
        }

        $this->info('Sync complete.');
    }
    private function sanitize($string)
    {
        if (is_string($string)) {
            // Remove control characters
            $string = preg_replace('/[\x00-\x1F\x7F]/u', '', $string);
            
            // Ensure UTF-8
            if (!mb_check_encoding($string, 'UTF-8')) {
                $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
            }
        }
        return $string;
    }
}
