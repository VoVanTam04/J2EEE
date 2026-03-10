<?php

namespace App\Observers;

use App\Models\Deal;
use App\Services\ChatGPTService;
use App\Services\QdrantService;
use Illuminate\Support\Facades\Log;

class DealObserver
{
    protected $chatGPTService;
    protected $qdrantService;

    public function __construct(ChatGPTService $chatGPTService, QdrantService $qdrantService)
    {
        $this->chatGPTService = $chatGPTService;
        $this->qdrantService = $qdrantService;
    }

    /**
     * Handle the Deal "created" event.
     */
    public function created(Deal $deal): void
    {
        if ($deal->is_display) {
            $this->syncToQdrant($deal);
        }
    }

    /**
     * Handle the Deal "updated" event.
     */
    public function updated(Deal $deal): void
    {
        // If is_display changed from true to false, delete from Qdrant
        if ($deal->wasChanged('is_display') && !$deal->is_display) {
            $this->deleteFromQdrant($deal);
            return;
        }

        // If is_display is true (either changed to true or stayed true), sync/update
        if ($deal->is_display) {
            $this->syncToQdrant($deal);
        }
    }

    /**
     * Handle the Deal "deleted" event.
     */
    public function deleted(Deal $deal): void
    {
        $this->deleteFromQdrant($deal);
    }

    /**
     * Handle the Deal "restored" event.
     */
    public function restored(Deal $deal): void
    {
        if ($deal->is_display) {
            $this->syncToQdrant($deal);
        }
    }

    /**
     * Handle the Deal "force deleted" event.
     */
    public function forceDeleted(Deal $deal): void
    {
        $this->deleteFromQdrant($deal);
    }

    protected function syncToQdrant(Deal $deal)
    {
        try {
            
            $textParts = [];

            if (isset($deal->translations)) {
                foreach ($deal->translations as $lang => $trans) {
                    $title = $this->sanitize($trans['title'] ?? '');
                    $companyName = $this->sanitize($trans['company_name'] ?? '');
                    $specialize = $this->sanitize($trans['specialize'] ?? '');
                    
                    // Giải mã thực thể HTML (như &nbsp; thành khoảng trắng) và bỏ tag
                    $description = html_entity_decode(strip_tags($trans['description'] ?? ''), ENT_QUOTES, 'UTF-8');
                    
                    // Giới hạn description để không bị loãng vector (chỉ lấy phần cốt lõi)
                    $description = mb_substr(trim(preg_replace('/\s+/', ' ', $description)), 0, 300, 'UTF-8');

                    // Bỏ hết các từ khóa rườm rà như "Title:", "Description:"
                    // Ghép thành câu tự nhiên (Ví dụ: "Tương ớt Chinsu, công ty Masan, chuyên Nông sản. Mô tả: Vị cay bùng nổ...")
                    if ($title) {
                        $langText = "{$title}";
                        if ($companyName) $langText .= " ({$companyName})";
                        if ($specialize) $langText .= " - Ngành: {$specialize}";
                        if ($description) $langText .= ". {$description}";
                        
                        $textParts[] = $langText;
                    }
                }
            }

            // Nối 3 ngôn ngữ lại bằng dấu chấm câu rõ ràng.
            $text = implode(". ", $textParts);

            // In ra log để em tận mắt thấy chuỗi văn bản sạch sẽ như thế nào trước khi đi gọi OpenAI
            Log::info("Clean Text for Embedding Deal #{$deal->id}: " . $text);
            
            // Build multilingual strings for all payload fields (vi | en | ja)
            // Dùng closure để tránh lặp code
            $ml = function (string $field) use ($deal): string {
                $parts = array_filter([
                    $deal->translations['vi'][$field] ?? '',
                    $deal->translations['en'][$field] ?? '',
                    $deal->translations['ja'][$field] ?? '',
                ]);
                return implode(' | ', array_unique($parts));
            };

            // Add payload (metadata) — tất cả text fields lưu cả 3 ngôn ngữ
            $payload = [
                'deal_id'      => (int)$deal->id,
                'title'        => $this->sanitize($ml('title')),
                'company_name' => $this->sanitize($ml('company_name')),
                'location'     => $this->sanitize($ml('address')),   // address từ translations, không phải $deal->country
                'industry'     => $this->sanitize($ml('specialize')),
                'demand_type'  => (int)$deal->demand,
                'level'        => (int)$deal->priority,
                'text_preview' => mb_substr($text, 0, 200, 'UTF-8'),
            ];

            Log::info("DealObserver: Generating embedding for Deal #{$deal->id}...");
            $embedding = $this->chatGPTService->getEmbedding($text);

            if (!empty($embedding)) {
                $points = [
                    [
                        'id' => (int)$deal->id,
                        'vector' => $embedding,
                        'payload' => $payload
                    ]
                ];
                
                $this->qdrantService->upsert($points);
                Log::info("DealObserver: Upserted Deal #{$deal->id} to Qdrant.");
            }
        } catch (\Exception $e) {
            Log::error("DealObserver Error (Sync): " . $e->getMessage());
        }
    }

    protected function deleteFromQdrant(Deal $deal)
    {
        try {
            $this->qdrantService->delete([(int)$deal->id]);
            Log::info("DealObserver: Deleted Deal #{$deal->id} from Qdrant.");
        } catch (\Exception $e) {
            Log::error("DealObserver Error (Delete): " . $e->getMessage());
        }
    }

    private function sanitize($string)
    {
        if (is_string($string)) {
            $string = preg_replace('/[\x00-\x1F\x7F]/u', '', $string);
            if (!mb_check_encoding($string, 'UTF-8')) {
                $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
            }
        }
        return $string;
    }
}
