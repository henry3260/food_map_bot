<?php

namespace App\Http\Controllers;

use App\Http\Controllers\RestaurantController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

use LINE\Clients\MessagingApi\Api\MessagingApiApi;
use LINE\Clients\MessagingApi\Configuration;
use GuzzleHttp\Client;

class LineController extends Controller
{
    private $areaData;

    public function __construct()
    {
        $this->areaData = config('areaData');
    }

    public function index(Request $request)
    {
        $secret = env('LINE_BOT_CHANNEL_SECRET', 'your_channel_secret');
        $token = env('LINE_BOT_CHANNEL_ACCESS_TOKEN', 'your_channel_access_token');

        Log::info('載入頻道密鑰: ' . $secret);
        Log::info('載入頻道存取權杖: ' . $token);

        try {
            // 建立 v8 MessagingApi 實例
            $config = Configuration::getDefaultConfiguration()->setAccessToken($token);
            $httpClient = new Client();
            $bot = new MessagingApiApi($httpClient, $config);

            Log::info('Webhook 請求內容: ' . $request->getContent());

            $signature = $request->header('X-Line-Signature');
            if (!$signature) {
                Log::warning('缺少簽章，用於測試');
            }

            // 手動解析請求以避免 SDK 問題
            $events = json_decode($request->getContent(), true)['events'] ?? [];
            
            if (empty($events)) {
                Log::info('未收到任何事件');
                return response('OK', 200);
            }

            foreach ($events as $event) {
                Log::info('收到事件: ' . json_encode($event));
                
                $replyToken = $event['replyToken'] ?? null;
                $userId = $event['source']['userId'] ?? null;
                
                if (!$replyToken) {
                    Log::error('事件中未找到回覆權杖');
                    continue;
                }

                // 處理文字訊息
                if ($event['type'] === 'message' && isset($event['message']['type']) && $event['message']['type'] === 'text') {
                    $userMessage = $event['message']['text'] ?? '';
                    Log::info('接收到的文字: ' . $userMessage);

                    // 獲取使用者狀態
                    $userData = $this->getUserData($userId);
                    $userStep = $userData['step'] ?? null;

                    if ($userMessage === '選單') {
                        Log::info('使用者輸入關鍵字「選單」');
                        
                        // 使用原始 JSON 建立選單按鈕
                        $postData = [
                            'replyToken' => $replyToken,
                            'messages' => [
                                [
                                    'type' => 'template',
                                    'altText' => '請選擇搜尋條件',
                                    'template' => [
                                        'type' => 'buttons',
                                        'title' => '請選擇搜尋條件',
                                        'text' => '您可以根據以下條件搜尋餐廳：',
                                        'actions' => [
                                            [
                                                'type' => 'postback',
                                                'label' => '依價錢',
                                                'data' => 'action=search&by=price'
                                            ],
                                            [
                                                'type' => 'postback',
                                                'label' => '依類型',
                                                'data' => 'action=search&by=type'
                                            ],
                                            [
                                                'type' => 'postback',
                                                'label' => '依距離',
                                                'data' => 'action=search&by=distance'
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ];
                        
                        // 使用 Guzzle 直接發送請求
                        $client = new Client();
                        $response = $client->post('https://api.line.me/v2/bot/message/reply', [
                            'headers' => [
                                'Content-Type' => 'application/json',
                                'Authorization' => 'Bearer ' . $token
                            ],
                            'json' => $postData
                        ]);
                        
                        Log::info('選單回應: ' . $response->getBody());
                    }
                    // 處理價格範圍輸入
                    elseif ($userStep === 'waiting_price_range') {
                        Log::info('使用者輸入價格範圍: ' . $userMessage);
                        
                        if ($this->isValidPriceRange($userMessage)) {
                            // 解析並儲存價格範圍
                            $priceRange = $this->parsePriceRange($userMessage);
                            $userData['price_range'] = $priceRange;
                            $userData['step'] = 'price_set';
                            $this->storeUserData($userId, $userData);
                            
                            // 發送確認訊息並請求位置
                            $postData = [
                                'replyToken' => $replyToken,
                                'messages' => [
                                    [
                                        'type' => 'text',
                                        'text' => "價格範圍設定完成：NT$ {$priceRange['min']} - NT$ {$priceRange['max']}\n\n請分享您的位置，讓我為您推薦附近符合價格範圍的餐廳。",
                                        'quickReply' => [
                                            'items' => [
                                                [
                                                    'type' => 'action',
                                                    'action' => [
                                                        'type' => 'location',
                                                        'label' => '分享位置'
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ];
                        } else {
                            // 格式錯誤，重新提示
                            $postData = [
                                'replyToken' => $replyToken,
                                'messages' => [
                                    [
                                        'type' => 'text',
                                        'text' => '價格格式不正確！\n\n請使用以下格式輸入：\n• 100-500 (範圍)\n• 100~ (100元以上)\n• ~500 (500元以下)\n• 300 (大約300元)\n\n請重新輸入價格範圍：'
                                    ]
                                ]
                            ];
                        }
                        
                        $client = new Client();
                        $client->post('https://api.line.me/v2/bot/message/reply', [
                            'headers' => [
                                'Content-Type' => 'application/json',
                                'Authorization' => 'Bearer ' . $token
                            ],
                            'json' => $postData
                        ]);
                    }
                    // 檢查是否為地區輸入
                    elseif (preg_match('/^\S+\s+\S+$/', $userMessage)) {
                        $response = $this->checkArea($userMessage);
                        // 儲存使用者輸入的地區到 Cache
                        $userData = Cache::get("line_user_{$userId}", []);
                        $userData['location']['input_area'] = $userMessage;
                        $userData['step'] = 'area_input';
                        Cache::put("line_user_{$userId}", $userData, now()->addMinutes(30));
                        
                        Log::info('使用者輸入地區並已儲存:', [
                            'user_id' => $userId,
                            'area' => $userMessage
                        ]);

                        $message = [
                            'type' => 'text',
                            'text' => $response['message']
                        ];
                        
                        $postData = [
                            'replyToken' => $replyToken,
                            'messages' => [$message]
                        ];

                        // 使用 Guzzle 直接發送請求
                        $client = new Client();
                        $response = $client->post('https://api.line.me/v2/bot/message/reply', [
                            'headers' => [
                                'Content-Type' => 'application/json',
                                'Authorization' => 'Bearer ' . $token
                            ],
                            'json' => $postData
                        ]);

                        Log::info('要回傳給使用者的訊息:', $message);
                    }
                    else {
                        Log::info('接收到非關鍵字訊息');
                        
                        // 發送預設訊息
                        $postData = [
                            'replyToken' => $replyToken,
                            'messages' => [
                                [
                                    'type' => 'text',
                                    'text' => '您好！請輸入「選單」來查看可用的選項。'
                                ]
                            ]
                        ];
                        
                        $client = new Client();
                        $client->post('https://api.line.me/v2/bot/message/reply', [
                            'headers' => [
                                'Content-Type' => 'application/json',
                                'Authorization' => 'Bearer ' . $token
                            ],
                            'json' => $postData
                        ]);
                    }
                }

                // 處理位置訊息
                if ($event['type'] === 'message' && isset($event['message']['type']) && $event['message']['type'] === 'location') {
                    $lat = $event['message']['latitude'];
                    $lng = $event['message']['longitude'];
                    
                    Log::info('處理位置訊息', [
                        'user_id' => $userId,
                        'latitude' => $lat,
                        'longitude' => $lng
                    ]);

                    // 獲取使用者設定的價格範圍
                    $userData = $this->getUserData($userId);
                    $priceRange = $userData['price_range'] ?? null;
                    $restaurantType = $userData['restaurant_type'] ?? null;
                    
                    // 呼叫 Google Maps API 查詢附近餐廳
                    $restaurants = $this->getNearbyRestaurants($lat, $lng, $priceRange, $restaurantType);
                    
                    if (empty($restaurants)) {
                        $postData = [
                            'replyToken' => $replyToken,
                            'messages' => [
                                [
                                    'type' => 'text',
                                    'text' => '抱歉，在您附近沒有找到符合條件的餐廳。請嘗試其他位置或調整搜尋條件。'
                                ]
                            ]
                        ];
                    } else {
                        // 建立餐廳卡片
                        $bubbles = array_map(function ($r) use ($priceRange) {
                            $photoRef = $r['photos'][0]['photo_reference'] ?? null;
                            $photoUrl = $photoRef
                                ? "https://maps.googleapis.com/maps/api/place/photo?maxwidth=600&photoreference=$photoRef&key=" . env('GOOGLE_MAPS_API_KEY')
                                : 'https://via.placeholder.com/600x400?text=No+Image';

                            $searchQuery = urlencode($r['name'] . ' ' . ($r['vicinity'] ?? ''));
                            $mapUrl = "https://maps.google.com/?q=" . $searchQuery;

                            $priceText = '';
                            if ($priceRange) {
                                $priceText = "價位：NT$ {$priceRange['min']} - NT$ {$priceRange['max']}";
                            }

                            return [
                                'type' => 'bubble',
                                'hero' => [
                                    'type' => 'image',
                                    'url' => $photoUrl,
                                    'size' => 'full',
                                    'aspectRatio' => '20:13',
                                    'aspectMode' => 'cover'
                                ],
                                'body' => [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'spacing' => 'sm',
                                    'contents' => array_filter([
                                        [
                                            'type' => 'text', 
                                            'text' => $r['name'], 
                                            'wrap' => true, 
                                            'weight' => 'bold', 
                                            'size' => 'md'
                                        ],
                                        [
                                            'type' => 'text', 
                                            'text' => "評分：" . ($r['rating'] ?? 'N/A'), 
                                            'size' => 'sm'
                                        ],
                                        $priceText ? [
                                            'type' => 'text', 
                                            'text' => $priceText, 
                                            'size' => 'sm',
                                            'color' => '#ff5551'
                                        ] : null,
                                        [
                                            'type' => 'text', 
                                            'text' => "地址：" . ($r['vicinity'] ?? '未知'), 
                                            'wrap' => true, 
                                            'size' => 'sm'
                                        ]
                                    ])
                                ],
                                'footer' => [
                                    'type' => 'box',
                                    'layout' => 'horizontal',
                                    'contents' => [[
                                        'type' => 'button',
                                        'style' => 'link',
                                        'height' => 'sm',
                                        'action' => [
                                            'type' => 'uri',
                                            'label' => '查看地圖',
                                            'uri' => $mapUrl
                                        ]
                                    ]],
                                    'flex' => 0
                                ]
                            ];
                        }, $restaurants);

                        $carousel = [
                            'type' => 'carousel',
                            'contents' => $bubbles
                        ];

                        $altText = $priceRange 
                            ? "符合價格範圍 NT$ {$priceRange['min']}-{$priceRange['max']} 的推薦餐廳"
                            : '附近推薦餐廳';

                        $postData = [
                            'replyToken' => $replyToken,
                            'messages' => [[
                                'type' => 'flex',
                                'altText' => $altText,
                                'contents' => $carousel
                            ]]
                        ];
                    }

                    // 發送訊息
                    $client = new Client();
                    $response = $client->post('https://api.line.me/v2/bot/message/reply', [
                        'headers' => [
                            'Content-Type' => 'application/json',
                            'Authorization' => 'Bearer ' . $token
                        ],
                        'json' => $postData
                    ]);

                    Log::info('已回傳餐廳卡片', [
                        'restaurant_count' => count($restaurants),
                        'response_status' => $response->getStatusCode()
                    ]);
                }

                // 處理 postback 回傳
                if ($event['type'] === 'postback' && isset($event['postback']['data'])) {
                    $data = $event['postback']['data'];
                    
                    Log::info("使用者點選 postback: " . $data);

                    parse_str($data, $params);
                    if (is_array($params) && isset($params['action'])) {
                        if ($params['action'] === 'search') {
                            if ($params['by'] === 'price') {
                                Log::info('使用者選擇依價錢搜尋');
    
                                // 設定使用者狀態
                                $userData = $this->getUserData($userId) ?? [];
                                $userData['step'] = 'waiting_price_input';
                                $userData['search_type'] = 'price';
                                $this->storeUserData($userId, $userData);
                                
                                // 建立價格輸入介面
                                $postData = [
                                    'replyToken' => $replyToken,
                                    'messages' => [
                                        [
                                            'type' => 'flex',
                                            'altText' => '請選擇價格範圍',
                                            'contents' => [
                                                'type' => 'bubble',
                                                'body' => [
                                                    'type' => 'box',
                                                    'layout' => 'vertical',
                                                    'spacing' => 'sm',
                                                    'contents' => [
                                                        [
                                                            'type' => 'button',
                                                            'action' => [
                                                                'type' => 'postback',
                                                                'label' => '100-300元 (平價)',
                                                                'data' => 'action=set_price&min=100&max=300'
                                                            ],
                                                            'style' => 'primary',
                                                            'color' => '#28a745'
                                                        ],
                                                        [
                                                            'type' => 'button',
                                                            'action' => [
                                                                'type' => 'postback',
                                                                'label' => '300-600元 (中等)',
                                                                'data' => 'action=set_price&min=300&max=600'
                                                            ],
                                                            'style' => 'primary',
                                                            'color' => '#ffc107'
                                                        ],
                                                        [
                                                            'type' => 'button',
                                                            'action' => [
                                                                'type' => 'postback',
                                                                'label' => '600元以上 (高檔)',
                                                                'data' => 'action=set_price&min=600&max=9999'
                                                            ],
                                                            'style' => 'primary',
                                                            'color' => '#dc3545'
                                                        ]
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ];
                                
                                $client = new Client();
                                $client->post('https://api.line.me/v2/bot/message/reply', [
                                    'headers' => [
                                        'Content-Type' => 'application/json',
                                        'Authorization' => 'Bearer ' . $token
                                    ],
                                    'json' => $postData
                                ]);
                            } elseif ($params['by'] === 'type') {
                                Log::info('使用者選擇依類型搜尋');
    
                                // 設定使用者狀態為等待類型輸入
                                $userData = $this->getUserData($userId) ?? [];
                                $userData['step'] = 'waiting_restaurant_type';
                                $userData['search_type'] = 'type';
                                $this->storeUserData($userId, $userData);
                                
                                // 建立餐廳類型選擇介面
                                $postData = [
                                    'replyToken' => $replyToken,
                                    'messages' => [
                                        [
                                            'type' => 'flex',
                                            'altText' => '請選擇餐廳類型',
                                            'contents' => [
                                                'type' => 'bubble',
                                                'header' => [
                                                    'type' => 'box',
                                                    'layout' => 'vertical',
                                                    'contents' => [
                                                        [
                                                            'type' => 'text',
                                                            'text' => '請選擇餐廳類型',
                                                            'weight' => 'bold',
                                                            'size' => 'lg',
                                                            'align' => 'center'
                                                        ]
                                                    ]
                                                ],
                                                'body' => [
                                                    'type' => 'box',
                                                    'layout' => 'vertical',
                                                    'spacing' => 'sm',
                                                    'contents' => [
                                                        [
                                                            'type' => 'button',
                                                            'action' => [
                                                                'type' => 'postback',
                                                                'label' => '🍜 中式料理',
                                                                'data' => 'action=set_type&type=chinese&keyword=中式料理'
                                                            ],
                                                            'style' => 'primary',
                                                            'color' => '#ff6b6b'
                                                        ],
                                                        [
                                                            'type' => 'button',
                                                            'action' => [
                                                                'type' => 'postback',
                                                                'label' => '🍣 日式料理',
                                                                'data' => 'action=set_type&type=japanese&keyword=日式料理'
                                                            ],
                                                            'style' => 'primary',
                                                            'color' => '#4ecdc4'
                                                        ],
                                                        [
                                                            'type' => 'button',
                                                            'action' => [
                                                                'type' => 'postback',
                                                                'label' => '🍕 西式料理',
                                                                'data' => 'action=set_type&type=western&keyword=西式料理'
                                                            ],
                                                            'style' => 'primary',
                                                            'color' => '#45b7d1'
                                                        ],
                                                        [
                                                            'type' => 'button',
                                                            'action' => [
                                                                'type' => 'postback',
                                                                'label' => '🌶️ 韓式料理',
                                                                'data' => 'action=set_type&type=korean&keyword=韓式料理'
                                                            ],
                                                            'style' => 'primary',
                                                            'color' => '#f9ca24'
                                                        ],
                                                        [
                                                            'type' => 'button',
                                                            'action' => [
                                                                'type' => 'postback',
                                                                'label' => '🍛 泰式料理',
                                                                'data' => 'action=set_type&type=thai&keyword=泰式料理'
                                                            ],
                                                            'style' => 'primary',
                                                            'color' => '#6c5ce7'
                                                        ],
                                                        [
                                                            'type' => 'button',
                                                            'action' => [
                                                                'type' => 'postback',
                                                                'label' => '✏️ 自訂類型',
                                                                'data' => 'action=custom_type'
                                                            ],
                                                            'style' => 'secondary'
                                                        ]
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ];
                                
                                $client = new Client();
                                $client->post('https://api.line.me/v2/bot/message/reply', [
                                    'headers' => [
                                        'Content-Type' => 'application/json',
                                        'Authorization' => 'Bearer ' . $token
                                    ],
                                    'json' => $postData
                                ]);
                            }

                             elseif ($params['action'] === 'custom_type') {
                                // 使用者選擇自訂餐廳類型
                                $userData = $this->getUserData($userId) ?? [];
                                $userData['step'] = 'waiting_custom_type_input';
                                $this->storeUserData($userId, $userData);
                                
                                $postData = [
                                    'replyToken' => $replyToken,
                                    'messages' => [
                                        [
                                            'type' => 'text',
                                            'text' => "請輸入您想搜尋的餐廳類型或關鍵字\n\n例如：\n• 火鍋\n• 燒烤\n• 咖啡廳\n• 牛排\n• 素食\n• 小籠包"
                                        ]
                                    ]
                                ];
                                
                                $client = new Client();
                                $client->post('https://api.line.me/v2/bot/message/reply', [
                                    'headers' => [
                                        'Content-Type' => 'application/json',
                                        'Authorization' => 'Bearer ' . $token
                                    ],
                                    'json' => $postData
                                ]);
                            }

                            // 在處理文字訊息的部分，新增處理自訂類型輸入
                            elseif ($userStep === 'waiting_custom_type_input') {
                                Log::info('使用者輸入自訂餐廳類型: ' . $userMessage);
                                
                                // 儲存使用者輸入的餐廳類型
                                $userData['restaurant_type'] = [
                                    'type' => 'custom',
                                    'keyword' => $userMessage
                                ];
                                $userData['step'] = 'type_set';
                                $this->storeUserData($userId, $userData);
                                
                                // 發送確認訊息並請求位置
                                $postData = [
                                    'replyToken' => $replyToken,
                                    'messages' => [
                                        [
                                            'type' => 'text',
                                            'text' => "餐廳類型設定完成：{$userMessage}\n\n請分享您的位置，讓我為您推薦附近的{$userMessage}餐廳。",
                                            'quickReply' => [
                                                'items' => [
                                                    [
                                                        'type' => 'action',
                                                        'action' => [
                                                            'type' => 'location',
                                                            'label' => '分享位置'
                                                        ]
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ];
                                
                                $client = new Client();
                                $client->post('https://api.line.me/v2/bot/message/reply', [
                                    'headers' => [
                                        'Content-Type' => 'application/json',
                                        'Authorization' => 'Bearer ' . $token
                                    ],
                                    'json' => $postData
                                ]);

                                
                            } elseif ($params['by'] === 'distance') {
                                // 直接處理熱門選項
                                $postData = [
                                    'replyToken' => $replyToken,
                                    'messages' => [
                                        [
                                            'type' => 'text',
                                            'text' => "以下是熱門推薦餐廳：\n1. 餐廳A\n2. 餐廳B\n3. 餐廳C"
                                        ]
                                    ]
                                ];
                                
                                $client = new Client();
                                $client->post('https://api.line.me/v2/bot/message/reply', [
                                    'headers' => [
                                        'Content-Type' => 'application/json',
                                        'Authorization' => 'Bearer ' . $token
                                    ],
                                    'json' => $postData
                                ]);
                            }
                        } elseif ($params['action'] === 'set_price') {
                            $userData = $this->getUserData($userId) ?? [];
                            $userData['price_range'] = [
                                'min' => (int)$params['min'],
                                'max' => (int)$params['max'],
                                'type' => 'preset'
                            ];
                            $userData['step'] = 'price_set';
                            $this->storeUserData($userId, $userData);
                            
                            $priceText = $params['max'] == 9999 
                                ? "NT$ {$params['min']}元以上"
                                : "NT$ {$params['min']} - NT$ {$params['max']}元";

                            // 呼叫新的輔助函式來建立整合後的確認訊息
                            $confirmationMessage = $this->buildConfirmationMessage($userData);

                            // 發送確認訊息並請求位置
                            $postData = [
                                'replyToken' => $replyToken,
                                'messages' => [
                                    [
                                        'type' => 'text',
                                        'text' => $confirmationMessage,
                                        'quickReply' => [
                                            'items' => [
                                                [
                                                    'type' => 'action',
                                                    'action' => [
                                                        'type' => 'location',
                                                        'label' => '分享位置'
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ];
                            
                            $client = new Client();
                            $client->post('https://api.line.me/v2/bot/message/reply', [
                                'headers' => [
                                    'Content-Type' => 'application/json',
                                    'Authorization' => 'Bearer ' . $token
                                ],
                                'json' => $postData
                            ]);
                        }
                        // 在處理 postback 的部分，新增以下處理邏輯
                            elseif ($params['action'] === 'set_type') {
                                // 使用者選擇了預設的餐廳類型
                                $userData = $this->getUserData($userId) ?? [];
                                $userData['restaurant_type'] = [
                                    'type' => $params['type'],
                                    'keyword' => $params['keyword']
                                ];
                                $userData['step'] = 'type_set';
                                $this->storeUserData($userId, $userData);
                                
                                 // 呼叫新的輔助函式來建立整合後的確認訊息
                                $confirmationMessage = $this->buildConfirmationMessage($userData);

                                // 發送確認訊息並請求位置
                                $postData = [
                                    'replyToken' => $replyToken,
                                    'messages' => [
                                        [
                                            'type' => 'text',
                                            'text' => $confirmationMessage,
                                            'quickReply' => [
                                                'items' => [
                                                    [
                                                        'type' => 'action',
                                                        'action' => [
                                                            'type' => 'location',
                                                            'label' => '分享位置'
                                                        ]
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ];
                                
                                $client = new Client();
                                $client->post('https://api.line.me/v2/bot/message/reply', [
                                    'headers' => [
                                        'Content-Type' => 'application/json',
                                        'Authorization' => 'Bearer ' . $token
                                    ],
                                    'json' => $postData
                                ]);
                            }
                        
                        elseif ($params['action'] === 'input_price') {
                            $userData = $this->getUserData($userId) ?? [];
                            $userData['step'] = 'waiting_' . $params['type'] . '_price';
                            $userData['price_input_type'] = $params['type'];
                            $this->storeUserData($userId, $userData);
                            
                            $priceType = $params['type'] === 'min' ? '最低價' : '最高價';
                            
                            $postData = [
                                'replyToken' => $replyToken,
                                'messages' => [
                                    [
                                        'type' => 'text',
                                        'text' => "請輸入{$priceType}金額（只需輸入數字，例如：100）："
                                    ]
                                ]
                            ];
                            
                            $client = new Client();
                            $client->post('https://api.line.me/v2/bot/message/reply', [
                                'headers' => [
                                    'Content-Type' => 'application/json',
                                    'Authorization' => 'Bearer ' . $token
                                ],
                                'json' => $postData
                            ]);
                        }
                    } else {
                        Log::error('Postback 資料解析失敗，或缺少必要的參數。');
                    }
                }
            }

            return response('OK', 200);
        } catch (\Exception $e) {
            Log::error('LineController 錯誤: ' . $e->getMessage());
            return response('內部伺服器錯誤: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 驗證價格範圍格式
     */
    private function isValidPriceRange($input)
    {
        $patterns = [
            '/^\d+\-\d+$/',     // 100-500
            '/^\d+~$/',         // 100~
            '/^~\d+$/',         // ~500
            '/^\d+$/'           // 300
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, trim($input))) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 解析價格範圍
     */
    private function parsePriceRange($input)
    {
        $input = trim($input);
        
        if (preg_match('/^(\d+)\-(\d+)$/', $input, $matches)) {
            // 範圍格式：100-500
            return [
                'min' => (int)$matches[1],
                'max' => (int)$matches[2],
                'type' => 'range'
            ];
        } elseif (preg_match('/^(\d+)~$/', $input, $matches)) {
            // 以上格式：100~
            return [
                'min' => (int)$matches[1],
                'max' => 9999,
                'type' => 'min_only'
            ];
        } elseif (preg_match('/^~(\d+)$/', $input, $matches)) {
            // 以下格式：~500
            return [
                'min' => 0,
                'max' => (int)$matches[1],
                'type' => 'max_only'
            ];
        } elseif (preg_match('/^(\d+)$/', $input, $matches)) {
            // 固定價格：300
            $price = (int)$matches[1];
            return [
                'min' => max(0, $price - 100),
                'max' => $price + 100,
                'type' => 'approximate'
            ];
        }
        
        return null;
    }

    public function checkArea($userInput)
    {
        // 猜解使用者輸入
        [$city, $district] = explode(' ', $userInput);

        // 檢查城市與地區是否存在
        if (isset($this->areaData[$city]) && in_array($district, $this->areaData[$city])) {
            return [
                'status' => 'success',
                'message' => "輸入成功: $city $district 存在於資料中"
            ];
        } else {
            return [
                'status' => 'error',
                'message' => "輸入錯誤: $city $district 不存在於資料中"
            ];
        }
    }
    
    public function storeUserData($userId, $userData)
    {
        $key = "line_user_{$userId}";
        Cache::put($key, $userData, now()->addMinutes(30)); // 存 30 分鐘
        
        Log::info("存儲使用者資料", [
            'user_id' => $userId,
            'key' => $key,
            'data' => $userData
        ]);
    }

    public function getUserData($userId)
    {
        $key = "line_user_{$userId}";
        return Cache::get($key);
    }

    public function getNearbyRestaurants($lat, $lng, $priceRange = null, $restaurantType = null)
{
    $apiKey = env('GOOGLE_MAPS_API_KEY');
    $radius = 3000; // 稍微擴大搜尋範圍，以獲取更多原始資料來篩選

    $url = "https://maps.googleapis.com/maps/api/place/nearbysearch/json";
    $params = [
        'location' => "$lat,$lng",
        'radius' => $radius,
        'type' => 'restaurant',
        'key' => $apiKey,
    ];

    // 關鍵字仍然要傳給 Google，讓它做初步篩選
    if ($restaurantType && !empty($restaurantType['keyword'])) {
        $params['keyword'] = $restaurantType['keyword'];
    }
    
    // 為了拿到足夠的資料來篩選，我們先不使用 rankby=prominence
    // 而是採用預設的距離排序，並在後續自己處理排序
    // Log::info('Google Places API 搜尋參數:', $params);

    $client = new \GuzzleHttp\Client();
    try {
        $response = $client->get($url, ['query' => $params]);
        $results = json_decode($response->getBody(), true)['results'];
        Log::info("Google API 原始回傳了 " . count($results) . " 筆餐廳資料");

    } catch (\Exception $e) {
        Log::error('Google Places API 請求失敗: ' . $e->getMessage());
        return []; // 發生錯誤時回傳空陣列
    }

    $filteredRestaurants = [];

    // 【核心邏輯】如果使用者有指定類型，則進行嚴格的二次篩選
    if ($restaurantType && !empty($restaurantType['keyword'])) {
        $keyword = $restaurantType['keyword'];
        
        // 為了比對更準確，可以移除通用的「料理」二字
        // 例如，讓「日式料理」變成「日式」，更容易匹配餐廳名稱
        $baseKeyword = str_replace('料理', '', $keyword);

        foreach ($results as $place) {
            // 使用 mb_stripos 進行不分大小寫的字串比對 (適用於中英日文)
            // 檢查餐廳名稱是否包含我們的關鍵字
            if (isset($place['name']) && mb_stripos($place['name'], $baseKeyword) !== false) {
                $filteredRestaurants[] = $place;
            }
        }
        Log::info("使用關鍵字 '{$baseKeyword}' 篩選後，剩下 " . count($filteredRestaurants) . " 筆符合條件的餐廳");
    } else {
        // 如果沒有指定類型，就使用所有回傳結果
        $filteredRestaurants = $results;
    }

    // 最後，從篩選過的結果中，根據評分和評論數進行排序，選出最好的 5 家
    $restaurants = collect($filteredRestaurants)
        ->filter(fn($place) => isset($place['rating']) && isset($place['user_ratings_total']) && $place['user_ratings_total'] > 5) // 過濾掉評論數太少的
        ->sortByDesc(fn($place) => $place['rating'] * log($place['user_ratings_total'] + 1)) // 使用加權評分排序
        ->take(5)
        ->values()
        ->all();

    return $restaurants;
}

    /**
     * 將價格範圍轉換為 Google Places API 的 price_level
     * Google Places API price_level: 0-4 (0=免費, 1=便宜, 2=中等, 3=昂貴, 4=非常昂貴)
     */
    private function convertPriceRangeToPriceLevel($priceRange)
    {
        if (!$priceRange) return null;
        
        $min = $priceRange['min'];
        $max = $priceRange['max'];
        
        // 根據台灣餐廳價格分級
        $levels = [];
        
        if ($min <= 200) $levels[] = 1;      // 便宜 (0-200)
        if ($min <= 500 && $max >= 200) $levels[] = 2;  // 中等 (200-500)
        if ($min <= 1000 && $max >= 500) $levels[] = 3; // 昂貴 (500-1000)
        if ($max >= 1000) $levels[] = 4;     // 非常昂貴 (1000+)
        
        if (empty($levels)) return null;
        
        return [
            'min' => min($levels),
            'max' => max($levels)
        ];
    }

private function buildConfirmationMessage($userData)
{
    $conditions = [];

    // 檢查是否有價格條件
    if (isset($userData['price_range'])) {
        $min = $userData['price_range']['min'];
        $max = $userData['price_range']['max'];

        if (isset($userData['price_range']['type']) && $userData['price_range']['type'] === 'preset') {
            // 處理預設按鈕的價格
            $priceText = ($max == 9999) ? "NT$ {$min} 元以上" : "NT$ {$min} - NT$ {$max} 元";
        } else {
            // 處理使用者手動輸入的價格
            $priceText = "約 NT$ {$min} - {$max} 元";
        }
        $conditions[] = "價格：" . $priceText;
    }

    // 檢查是否有類型條件
    if (isset($userData['restaurant_type']['keyword'])) {
        $conditions[] = "類型：" . $userData['restaurant_type']['keyword'];
    }

    // 如果沒有任何條件，回傳預設訊息
    if (empty($conditions)) {
        return "請分享您的位置，讓我為您推薦附近的餐廳。";
    }

    // 組合所有條件
    $header = "搜尋條件已更新！目前設定：\n";
    $body = "• " . implode("\n• ", $conditions);
    $footer = "\n\n請分享您的位置開始搜尋，或繼續設定其他條件。";

    return $header . $body . $footer;
}


}




