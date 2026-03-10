<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QdrantService
{
    protected $url;
    protected $collection;

    public function __construct()
    {
        // Qdrant HTTP URL
        $this->url = config('services.qdrant.host');
        $this->collection = config('services.qdrant.collection');
    }

    /**
     * Create collection if not exists
     */
    public function createCollection(int $vectorSize = 1536)
    {
        $url = "{$this->url}/collections/{$this->collection}";
        
        try {
            // Check if exists
            $response = Http::get($url);
            if ($response->successful()) {
                return true; 
            }

            // Create
            $response = Http::put($url, [
                'vectors' => [
                    'size' => $vectorSize,
                    'distance' => 'Cosine'
                ]
            ]);

            if (!$response->successful()) {
                Log::error('Qdrant Create Collection Error: ' . $response->body());
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Qdrant Create Collection Exception: ' . $e->getMessage());
            echo "Qdrant Error: " . $e->getMessage() . "\n"; // Print to console for debugging
            return false;
        }
    }

    /**
     * Upsert points (vectors)
     * @param array $points Array of ['id' => int/string, 'vector' => [], 'payload' => []]
     */
    public function upsert(array $points)
    {
        // Ensure collection exists first (optional, but safe)
        // $this->createCollection();

        $url = "{$this->url}/collections/{$this->collection}/points?wait=true";

        try {
            $response = Http::put($url, [
                'points' => $points
            ]);

            if (!$response->successful()) {
                Log::error('Qdrant Upsert Error: ' . $response->body());
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Qdrant Upsert Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Search points
     * @param array $vector Query vector
     * @param int $limit
     */
    /**
     * Search points
     * @param array $vector Query vector
     * @param int $limit
     */
    public function search(array $vector, int $limit = 5, float $scoreThreshold = null)
    {
        $url = "{$this->url}/collections/{$this->collection}/points/search";

        try {
            $payload = [
                'vector' => $vector,
                'limit' => $limit,
                'with_payload' => true 
            ];

            // Gắn Threshold mặc định an toàn
            if ($scoreThreshold === null) {
                $scoreThreshold = 0.30; 
            }
            $payload['score_threshold'] = $scoreThreshold;


            $response = Http::post($url, $payload);

            if (!$response->successful()) {
                Log::error('Qdrant Search Error: ' . $response->body());
                return [];
            }

            return $response->json()['result'] ?? [];
        } catch (\Exception $e) {
            Log::error('Qdrant Search Exception: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Delete points by Filter or ID
     * @param array $ids
     */
    public function delete(array $ids)
    {
        $url = "{$this->url}/collections/{$this->collection}/points/delete?wait=true";

        try {
            $response = Http::post($url, [
                'points' => $ids
            ]);

            if (!$response->successful()) {
                Log::error('Qdrant Delete Error: ' . $response->body());
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Qdrant Delete Exception: ' . $e->getMessage());
            return false;
        }
    }
}
