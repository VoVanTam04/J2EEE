<?php

namespace App\Services;

use App\Models\ChatUser;
use App\Models\ChatMessage;
use App\Models\Deal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class DealChatService
{
    protected $chatGPTService;
    protected $qdrantService;
    protected $apiKey;
    protected $apiUrl = 'https://api.openai.com/v1/chat/completions';

    public function __construct(ChatGPTService $chatGPTService, QdrantService $qdrantService)
    {
        $this->chatGPTService = $chatGPTService;
        $this->qdrantService = $qdrantService;
        $this->apiKey = config('services.openai.api_key');
    }

    public function chat(string $message, string $userId, string $userName, string $locale = 'vi')
    {
        try {
            $chatUser = ChatUser::firstOrCreate(
                ['user_id' => $userId],
                [
                    'user_name' => $userName,
                    'is_approved' => false
                ]
            );

            $recentMessages = ChatMessage::where('chat_user_id', $chatUser->id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->reverse();

            $analysisResult = $this->processIntentAndSearch($message, $recentMessages, $locale);
            
            if (!empty($analysisResult['is_vague'])) {
                $vagueMessages = [
                    'vi' => 'Chào bạn, VJP Connect hiện đang có rất nhiều cơ hội giao thương mới. Để mình tìm đúng deal bạn cần nhất, bạn có thể chia sẻ thêm về lĩnh vực (VD: Nông sản, IT...), quốc gia hoặc loại đối tác bạn đang tìm kiếm được không?',
                    'en' => 'Hello! VJP Connect currently has many new trade opportunities. To find the best match for you, could you share more about your industry (e.g., Agriculture, IT...), target country, or the type of partner you are looking for?',
                    'ja' => 'こんにちは！VJP Connectには現在、多くの新しいビジネスチャンスがあります。最適な案件を見つけるために、関心のある業界（例：農業、ITなど）、国、またはお探しのパートナーの種類について教えていただけますか？'
                ];
                
                $vagueReply = $vagueMessages[$locale] ?? $vagueMessages['en'];

                $finalPayload = [
                    'question' => [$locale => $message],
                    'response' => [$locale => $vagueReply]
                ];

                ChatMessage::create([
                    'chat_user_id' => $chatUser->id,
                    'question' => json_encode($finalPayload['question'], JSON_UNESCAPED_UNICODE),
                    'response' => json_encode($finalPayload['response'], JSON_UNESCAPED_UNICODE),
                    'is_approved' => false,
                    'is_displayed' => false
                ]);

                return [
                    'success' => true,
                    'message' => $finalPayload
                ];
            }

            $contextData = $analysisResult['context'];
            $refinedIntent = $analysisResult['refined_intent'];
            $allRagDealIds = $analysisResult['deal_ids'] ?? [];

            $finalResponse = $this->generateResponse($refinedIntent, $contextData, $chatUser, $locale, $recentMessages, $message, $allRagDealIds);

            $filteredDealIds = $finalResponse['filtered_deal_ids'] ?? [];
            $finalMessageStr = $finalResponse['response'][$locale] ?? '';
            
            $chatMessage = ChatMessage::create([
                'chat_user_id' => $chatUser->id,
                'question' => json_encode($finalResponse['question'], JSON_UNESCAPED_UNICODE),
                'response' => json_encode($finalResponse['response'], JSON_UNESCAPED_UNICODE), 
                'deal_ids' => !empty($filteredDealIds) ? json_encode($filteredDealIds) : null,
                'is_approved' => false,
                'is_displayed' => false
            ]);

            if (!empty($filteredDealIds)) {
                 $frontendUrl = config('app.frontend_url');
                 $dealCount = count($filteredDealIds);
                 
                 $linkMessages = [
                     'vi' => "\n\n👉 **[Nhấn vào đây để xem toàn bộ danh sách {$dealCount} Deal phù hợp]({$frontendUrl}/vi/deals?chat_session={$chatMessage->id})**",
                     'en' => "\n\n👉 **[Click here to view the full list of {$dealCount} matching Deals]({$frontendUrl}/en/deals?chat_session={$chatMessage->id})**",
                     'ja' => "\n\n👉 **[適合する全 {$dealCount} 件の案件リストはこちらをクリック]({$frontendUrl}/ja/deals?chat_session={$chatMessage->id})**"
                 ];
                 $linkMessage = $linkMessages[$locale] ?? $linkMessages['en'];
                 $finalMessageStr .= $linkMessage;
                 
                 $finalResponse['response'][$locale] = $finalMessageStr;
                 $chatMessage->response = json_encode($finalResponse['response'], JSON_UNESCAPED_UNICODE);
                 $chatMessage->save();
            }

            return [
                'success' => true,
                'message' => $finalResponse
            ];

        } catch (\Exception $e) {
            Log::error('DealChatService Error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());

            $errorMessages = [
                'vi' => 'Hệ thống đang bận xử lý, vui lòng thử lại sau giây lát.',
                'en' => 'The system is currently busy, please try again in a moment.',
                'ja' => 'システムが混み合っております。しばらく経ってからもう一度お試しください。'
            ];
            
            $reply = $errorMessages[$locale] ?? $errorMessages['en'];

            return [
                'success' => false,
                'message' => [
                    'question' => [$locale => $message],
                    'response' => [$locale => $reply]
                ]
            ];
        }
    }

    protected function processIntentAndSearch($userMessage, $recentMessages, $locale)
    {
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_deals',
                    'description' => 'Search for business deals. Support general queries (VAGUE_QUERY for unclear requests) and specific keyword searches.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'keyword' => [
                                'type' => 'string',
                                'description' => 'Core keywords to search for (industry, product, business type, etc.).',
                            ],
                            'location' => [
                                'type' => 'string',
                                'description' => 'Country or location extracted from the user message (e.g. "Japan", "Vietnam", "Nhat Ban"). ONLY set if the user explicitly mentions a location. Leave empty otherwise.',
                            ],
                            'industry' => [
                                'type' => 'string',
                                'description' => 'Industry or field extracted from the user message (e.g. "Nong san", "IT", "Agriculture", "Det may"). ONLY set if the user mentions a specific industry. Leave empty otherwise.',
                            ],
                            'demand_type' => [
                                'type' => 'integer',
                                'description' => 'Deal type: 
                                                1 = Find Partners (tìm đối tác / パートナー探し)
                                                2 = Find Goods/Sources (tìm nguồn hàng / 仕入れ先探し). 
                                                ONLY set if explicitly mentioned.',
                            ],
                            'level' => [
                                'type' => 'integer',
                                'description' => 'Priority level: 
                                                1 = Normal (bình thường, thông thường / 通常)
                                                2 = Urgent (gấp, khẩn cấp / 緊急). 
                                                ONLY set if the user explicitly mentions priority. Leave empty otherwise.',
                            ],
                        ],
                        'required' => ['keyword'],
                    ],
                ],
            ]
        ];

        $formattedHistory = $this->formatHistoryForAi($recentMessages, $locale);

        $systemMsg = [
            'role' => 'system', 
            'content' => 'You are an intelligent deal search assistant for VJP Connect platform.
            Your PRIMARY job is to search for business deals using the "search_deals" tool.

            RULES:
            1. Analyze the User Message AND Chat History to understand the user\'s true intent.
            2. If the user asks for deals, products, trade opportunities, or mentions ANY industry/keyword (e.g. "gạo", "xuất khẩu", "may mặc", "công nghệ"), you MUST call the "search_deals" tool.
            3. If the user asks a follow-up ("Japanese one?", "loại của Nhật"), use the HISTORY to infer the full keyword (e.g., "Japanese Rice") and call "search_deals".
            4. If the user asks generally without specifics ("any deals?", "có deal gì mới", "tìm deal"), use keyword "VAGUE_QUERY" to indicate the query is too vague.
            5. ONLY reply directly if the user is merely saying hello ("hi", "xin chào") without asking for anything.
            6. For filter fields (location, industry, demand_type, level): extract ONLY if user explicitly states them. Do NOT guess. Leave empty if unsure.'
        ];

        $messages = array_merge([$systemMsg], $formattedHistory, [['role' => 'user', 'content' => $userMessage]]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(40)->post($this->apiUrl, [
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
        ]);

        $responseBody = $response->json();
        $messageData = $responseBody['choices'][0]['message'] ?? [];
        
        $refinedIntent = $userMessage; 
        $contextData = "";
        $dealIds = [];
        $isVague = false;

        if (isset($messageData['tool_calls'])) {
            $toolCall = $messageData['tool_calls'][0];
            if ($toolCall['function']['name'] === 'search_deals') {
                $args = json_decode($toolCall['function']['arguments'], true);
                
                $refinedIntent = $args['keyword'] ?? $userMessage;

                if ($refinedIntent === 'VAGUE_QUERY') {
                    $isVague = true;
                } else {
                    $filters = [];
                    if (!empty($args['location']))              $filters['location']    = $args['location'];
                    if (!empty($args['industry']))              $filters['industry']    = $args['industry'];
                    if (isset($args['demand_type']) && $args['demand_type'] !== '') $filters['demand_type'] = (int)$args['demand_type'];
                    if (isset($args['level']) && $args['level'] !== '')             $filters['level']       = (int)$args['level'];

                    Log::info("RAG Search triggered. Keyword: {$refinedIntent} | Filters: " . json_encode($filters, JSON_UNESCAPED_UNICODE));

                    $searchData = $this->performRagSearch($refinedIntent, 10, $locale, $filters);
                    
                    $contextData = $searchData['context'] ?? "";
                    $dealIds = $searchData['deal_ids'] ?? [];
                }
            }
        }

        return [
            'context' => $contextData,
            'deal_ids' => $dealIds,
            'refined_intent' => $refinedIntent,
            'is_vague' => $isVague
        ];
    }

    protected function performRagSearch($keyword, $limit, $locale = 'vi', array $filters = [])
    {
        $embedding = Cache::remember("embedding_" . md5($keyword), 86400, function () use ($keyword) {
            return $this->chatGPTService->getEmbedding($keyword);
        });
        
        if (empty($embedding)) {
            Log::warning("RAG Search: Không thể tạo embedding cho keyword: {$keyword}");
            return ['context' => "", 'deal_ids' => []]; 
        }

        // BƯỚC 1: LẤY TỐI ĐA 10 DEAL (Gác cổng bằng threshold)
        // Lấy đúng tối đa 10 deal. Nhờ threshold 0.30, nếu chỉ có 3 deal liên quan, nó sẽ chỉ lấy 3 deal chứ không vét rác bù vào.
        $fetchLimit = 10; 
        $results = $this->qdrantService->search($embedding, $fetchLimit, 0.30);
        
        if (empty($results)) {
            Log::info("RAG Search: Không có deal nào liên quan đến keyword: {$keyword}");
            return ['context' => "", 'deal_ids' => []];
        }

        // BƯỚC 2: CHẤM ĐIỂM ĐỀU = 1 CHO TẤT CẢ CÁC FILTER
        foreach ($results as &$result) {
            $payload = $result['payload'] ?? [];
            $boostScore = 0;

            if (!empty($filters['location']) && !empty($payload['location'])) {
                if (stripos($payload['location'], $filters['location']) !== false) $boostScore += 1;
            }

            if (!empty($filters['industry']) && !empty($payload['industry'])) {
                if (stripos($payload['industry'], $filters['industry']) !== false) $boostScore += 1;
            }

            if (isset($filters['demand_type']) && isset($payload['demand_type'])) {
                if ((int)$payload['demand_type'] === (int)$filters['demand_type']) $boostScore += 1;
            }

            if (isset($filters['level']) && isset($payload['level'])) {
                if ((int)$payload['level'] === (int)$filters['level']) $boostScore += 1;
            }

            $result['boost_score'] = $boostScore;
        }
        unset($result);

        // BƯỚC 3: SẮP XẾP THEO SỐ LƯỢNG FILTER ĐÚNG
        usort($results, function ($a, $b) {
            if ($a['boost_score'] !== $b['boost_score']) {
                return $b['boost_score'] <=> $a['boost_score']; 
            }
            return $b['score'] <=> $a['score']; 
        });

        // TẤT CẢ CÁC DEAL (TỐI ĐA 10) SẼ ĐƯỢC ĐƯA VÀO LINK SESSION MÀ KHÔNG BỊ BỎ SÓT
        $allDealIds = array_map(fn($item) => $item['payload']['deal_id'], $results);
        $idString = implode(',', $allDealIds);
        
        $deals = Deal::with(['postable.profile.company.companyCategory.category'])
            ->whereIn('id', $allDealIds) 
            ->orderByRaw("FIELD(id, {$idString})") 
            ->get();
            
        if ($deals->isEmpty()) {
            return ['context' => "", 'deal_ids' => []];
        }

        // BƯỚC 4: LỌC RA CÁC DEAL XUẤT SẮC NHẤT ĐỂ IN RA MÀN HÌNH CHAT
        // Logic thông minh bảo vệ bot: Lấy số filter user thực sự nhập.
        $providedFiltersCount = count($filters);
        
        // Nếu user nhập >= 3 filter, ép điểm phải >= 3. 
        // Nếu user lười nhập ít hơn, hạ tiêu chuẩn xuống bằng số lượng họ nhập.
        $requiredScore = $providedFiltersCount >= 3 ? 3 : $providedFiltersCount;

        $chatDealIds = [];
        foreach ($results as $res) {
            if ($res['boost_score'] >= $requiredScore) {
                $chatDealIds[] = $res['payload']['deal_id'];
            }
        }
        
        // Cắt tối đa 5 deal in ra chat (để tránh màn hình dài lê thê)
        $chatDealIds = array_slice($chatDealIds, 0, 5);

        // Lấy dữ liệu thực cho context của GPT
        $chatDeals = $deals->filter(function($deal) use ($chatDealIds) {
            return in_array($deal->id, $chatDealIds);
        });

        return [
            'context' => $this->formatDealsToContext($chatDeals, $locale), 
            'deal_ids' => $allDealIds // Đẩy TOÀN BỘ 10 cái IDs ra để nhét vào cái link Session
        ];
    }

    protected function formatDealsToContext($deals, $locale = 'vi')
    {
        $dealsData = [];
        foreach ($deals as $deal) {
            $title = $deal->translations[$locale]['title'] ?? ($deal->translations['en']['title'] ?? 'No Title');
            
            $industry = $deal->translations[$locale]['specialize'] ?? null;
            if (!$industry && $deal->postable?->profile?->company) {
                $category = $deal->postable->profile->company->companyCategory?->category;
                if ($category) {
                    $industry = $category->translations[$locale]['name'] ?? ($category->translations['en']['name'] ?? 'General');
                }
            }
            $industry = $industry ?? ($locale === 'vi' ? 'Chung' : ($locale === 'ja' ? '一般' : 'General'));

            $typeMap = [
                1 => ['vi' => 'Tìm đối tác', 'en' => 'Find Partners', 'ja' => 'パートナー探し'],
                2 => ['vi' => 'Tìm nguồn hàng', 'en' => 'Find Goods Sources', 'ja' => '仕入れ先探し']
            ];
            $type = $typeMap[$deal->demand][$locale] ?? ($locale === 'vi' ? 'Khác' : ($locale === 'ja' ? 'その他' : 'Other'));

            $location = $deal->translations[$locale]['address']
                ?? ($deal->translations['en']['address']
                ?? ($deal->country ?? 'N/A'));

            $start = $deal->start_date ? $deal->start_date->format('Y/m/d') : null;
            $end = $deal->end_date ? $deal->end_date->format('Y/m/d') : null;
            $created = $deal->created_at->format('Y/m/d');

            if ($locale === 'vi') {
                $time = ($start && $end) ? "$start đến $end" : $created;
            } elseif ($locale === 'ja') {
                $time = ($start && $end) ? "$start 〜 $end" : $created;
            } else {
                $time = ($start && $end) ? "$start to $end" : $created;
            }

            $description = strip_tags($deal->translations[$locale]['description'] ?? ($deal->translations['en']['description'] ?? ''));

            $dealsData[] = [
                'id' => $deal->id,
                'title' => $title,
                'industry' => $industry,
                'type' => $type,
                'location' => $location,
                'time' => $time,
                'description' => $description,
                'link' => config('app.frontend_url') . "/{$locale}/deals/{$deal->id}"
            ];
        }
        return json_encode($dealsData, JSON_UNESCAPED_UNICODE);
    }

    protected function generateResponse($refinedIntent, $contextData, $chatUser, $locale, $recentMessages = null, $originalMessage = null, $allRagDealIds = [])
    {
        $langNameMap = [
            'vi' => 'Vietnamese',
            'en' => 'English',
            'ja' => 'Japanese'
        ];
        $targetLang = $langNameMap[$locale] ?? 'English';

        $messages = [
            [
                'role'    => 'system',
                'content' => "You are a friendly trade consultant for VJP Connect. Answer in {$targetLang}.

You will receive a list of deals that have ALREADY been selected and ranked by the search engine.
Your job is ONLY to present them in a friendly, helpful manner. 

RULES:
1. Format ALL provided deals into the 'items' array. Do NOT skip or filter any deal.
2. Present deals in the EXACT ORDER given (already ranked by relevance).
3. Write a warm, short greeting referencing the user's request.
4. Write a short, encouraging closing.
5. For Description: summarize to 1-2 concise sentences (max 150 characters).
6. Label translations: Vietnamese → (Lĩnh vực, Loại, Địa điểm, Thời gian, Mô tả) | English → (Industry, Type, Location, Time, Description) | Japanese → (業界, 種類, 場所, 時間, 説明).
7. Never hallucinate or add deals not in the provided data.",
            ]
        ];

        if ($recentMessages && $recentMessages->count() > 0) {
             $miniHistory = $this->formatHistoryForAi($recentMessages->slice(-2), $locale);
             $messages = array_merge($messages, $miniHistory);
        }

        // Trường hợp 1: Thất bại toàn tập (Qdrant không tìm được gì cả, Link cũng không có)
        if (empty($allRagDealIds)) {
            $noResultMessages = [
                'vi' => 'Rất tiếc, hiện tại VJP Connect chưa có deal phù hợp với yêu cầu của bạn. Bạn có thể thử tìm với từ khóa khác hoặc mô tả chi tiết hơn nhé!',
                'en' => 'Sorry, VJP Connect does not have any matching deals for your request at the moment. Please try different keywords or describe your needs in more detail.',
                'ja' => '申し訳ありませんが、現在VJP Connectにはご要望に合う案件がございません。別のキーワードでお試しいただくか、詳しくお聞かせください。',
            ];
            return [
                'question'          => [$locale => $originalMessage ?? $refinedIntent],
                'response'          => [$locale => $noResultMessages[$locale] ?? $noResultMessages['en']],
                'filtered_deal_ids' => [],
            ];
        }

        // Trường hợp 2: Có deal nhét vào Link, nhưng không có deal nào đủ xuất sắc để in ra Chat
        if (empty($contextData) && !empty($allRagDealIds)) {
            $linkOnlyMessages = [
                'vi' => 'Tôi đã tìm thấy một số deal có liên quan đến yêu cầu của bạn. Tuy nhiên, chúng chưa đáp ứng đầy đủ các tiêu chí khắt khe mà bạn đưa ra. Bạn hãy nhấn vào link bên dưới để xem danh sách chi tiết nhé!',
                'en' => 'I found some deals related to your request. However, they might not fully match all your strict criteria. Please click the link below to view the detailed list!',
                'ja' => 'ご要望に関連する案件をいくつか見つけましたが、すべての条件を満たしているわけではありません。以下のリンクをクリックして詳細リストをご覧ください！',
            ];
            return [
                'question'          => [$locale => $originalMessage ?? $refinedIntent],
                'response'          => [$locale => $linkOnlyMessages[$locale] ?? $linkOnlyMessages['en']],
                'filtered_deal_ids' => $allRagDealIds, // Trả 10 ids ra để nối Link
            ];
        }

        // Trường hợp 3: Chạy bình thường (Có deal tốt in ra chat + nhét đủ vào Link)
        $contextDealsArray = json_decode($contextData, true) ?? [];
        $top5Deals = array_slice($contextDealsArray, 0, 5);
        $top5Json = json_encode($top5Deals, JSON_UNESCAPED_UNICODE);

        // Đổ toàn bộ $allRagDealIds (10 deal) để truyền ra ngoài nối Link
        $filteredDealIds = $allRagDealIds;

        $content = "User request: {$refinedIntent}\n\nDeals to format:\n{$top5Json}";
        $messages[] = ['role' => 'user', 'content' => $content];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(40)->post($this->apiUrl, [
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
            'temperature' => 0.5,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'deal_response_schema',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string', 'description' => 'Friendly greeting acknowledging the user\'s request.'],
                            'items' => [
                                'type' => 'array',
                                'description' => 'Format ALL provided deals into this array. Do NOT skip, filter, or re-order any deal. Present them exactly in the given order.',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'title_with_link' => ['type' => 'string', 'description' => 'Markdown link format: [Deal Title](link from data). Use the exact link from the "link" field.'],
                                        'details' => [
                                            'type' => 'array',
                                            'description' => 'Deal attributes in this exact order: 1. Industry, 2. Type, 3. Location, 4. Time, 5. Description. Translate labels to target language. For Description: summarize to max 150 chars.',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'label' => ['type' => 'string'],
                                                    'value' => ['type' => 'string'],
                                                ],
                                                'required' => ['label', 'value'],
                                                'additionalProperties' => false,
                                            ],
                                        ],
                                    ],
                                    'required' => ['title_with_link', 'details'],
                                    'additionalProperties' => false,
                                ],
                            ],
                            'closing' => ['type' => 'string', 'description' => 'Short, friendly closing message.'],
                        ],
                        'required' => ['message', 'items', 'closing'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ]);

        $responseData = $response->json();
        $responseContent = $responseData['choices'][0]['message']['content'] ?? '{}';

        Log::info('GPT Format Response: ' . $responseContent);
        $parsedResponse = json_decode($responseContent, true);

        $intro   = $parsedResponse['message'] ?? '';
        $items   = $parsedResponse['items']   ?? [];
        $closing = $parsedResponse['closing'] ?? '';

        // Build Markdown string
        $itemStrings = [];
        foreach ($items as $item) {
            $lines = ['- ' . $item['title_with_link']];
            foreach ($item['details'] as $detail) {
                $lines[] = '  - **' . $detail['label'] . '**: ' . $detail['value'];
            }
            $itemStrings[] = implode("\n", $lines);
        }

        $finalResponseStr = '';
        if ($intro)              $finalResponseStr .= $intro . "\n\n";
        if (!empty($itemStrings)) $finalResponseStr .= implode("\n\n", $itemStrings);
        if ($closing)            $finalResponseStr .= "\n\n" . $closing;

        return [
            'question'          => [$locale => $originalMessage ?? $refinedIntent],
            'response'          => [$locale => trim($finalResponseStr)],
            'filtered_deal_ids' => $filteredDealIds, // Bao gồm 10 IDs cho cái Link
        ];
    }

    protected function formatHistoryForAi($messages, $locale) {
        $history = [];
        foreach ($messages as $chat) {
            $q = json_decode($chat->question, true);
            $r = json_decode($chat->response, true);
            
            $qContent = is_array($q) ? ($q[$locale] ?? ($q['en'] ?? reset($q))) : $chat->question;
            $rContent = is_array($r) ? ($r[$locale] ?? ($r['en'] ?? reset($r))) : $chat->response;

            if (is_array($rContent)) $rContent = json_encode($rContent, JSON_UNESCAPED_UNICODE);

            $history[] = ['role' => 'user', 'content' => (string)$qContent];
            $history[] = ['role' => 'assistant', 'content' => (string)$rContent];
        }
        return $history;
    }
}
