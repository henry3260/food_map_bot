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

        Log::info('Loaded Channel Secret: ' . $secret);
        Log::info('Loaded Channel Access Token: ' . $token);

        try {
            // 建立 v8 MessagingApi 實例
            $config = Configuration::getDefaultConfiguration()->setAccessToken($token);
            $httpClient = new Client();
            $bot = new MessagingApiApi($httpClient, $config);

            Log::info('Webhook request content: ' . $request->getContent());

            $signature = $request->header('X-Line-Signature');
            if (!$signature) {
                Log::warning('Signature missing for testing');
            }

            // Parse request manually to avoid SDK issues
            $events = json_decode($request->getContent(), true)['events'] ?? [];
            
            if (empty($events)) {
                Log::info('No events received');
                return response('OK', 200);
            }

            foreach ($events as $event) {
                Log::info('收到事件: ' . json_encode($event));
                
                $replyToken = $event['replyToken'] ?? null;
                if (!$replyToken) {
                    Log::error('No reply token found in event');
                    continue;
                }

                // Handle text messages
                if ($event['type'] === 'message' && isset($event['message']['type']) && $event['message']['type'] === 'text') {
                    $userMessage = $event['message']['text'] ?? '';
                    Log::info('接收到的文字: ' . $userMessage);

                    if ($userMessage === '選單') {
                        Log::info('使用者輸入關鍵字「選單」');
                        
                        // Create menu buttons using raw JSON
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
                                                'label' => '依地區',
                                                'data' => 'action=search&by=area'
                                            ],
                                            [
                                                'type' => 'postback',
                                                'label' => '依類型',
                                                'data' => 'action=search&by=type'
                                            ],
                                            [
                                                'type' => 'postback',
                                                'label' => '熱門推薦',
                                                'data' => 'action=search&by=popular'
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ];
                        
                        // Send request directly using Guzzle
                        $client = new Client();
                        $response = $client->post('https://api.line.me/v2/bot/message/reply', [
                            'headers' => [
                                'Content-Type' => 'application/json',
                                'Authorization' => 'Bearer ' . $token
                            ],
                            'json' => $postData
                        ]);
                        
                        Log::info('Menu response: ' . $response->getBody());
                    } 
                    // 檢查是否為地區輸入
                    elseif(preg_match('/^\S+\s+\S+$/', $userMessage)){
                        $response = $this->checkArea($userMessage);
                        // 儲存使用者輸入的地區到 Cache
                        $userId = $event['source']['userId'];
                        $userData = Cache::get("line_user_{$userId}", []);
                        $userData['location']['input_area'] = $userMessage; // 你可以改成其他 key 名，例如 area_name
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

                        // Send request directly using Guzzle
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
                        
                        // Send default message
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

                    // 回傳五個餐廳
                    
                    if ($event['type'] === 'message' && isset($event['message']['type']) && $event['message']['type'] === 'location') {
                        $userId = $event['source']['userId'];
                        $lat = $event['message']['latitude'];
                        $lng = $event['message']['longitude'];
                        $replyToken = $event['replyToken'];
                        
                        $token = env('LINE_BOT_CHANNEL_ACCESS_TOKEN', 'your_channel_access_token'); // 正確的方式

                        Log::info('處理位置訊息', [
                            'user_id' => $userId,
                            'latitude' => $lat,
                            'longitude' => $lng,
                            'token_exists' => !empty($token)
                        ]);

                        try {
                            // 呼叫 Google Maps API 查詢附近餐廳
                            $restaurants = $this->getNearbyRestaurants($lat, $lng);
                            
                            if (empty($restaurants)) {
                                // 如果沒有找到餐廳，發送提示訊息
                                $postData = [
                                    'replyToken' => $replyToken,
                                    'messages' => [
                                        [
                                            'type' => 'text',
                                            'text' => '抱歉，在您附近沒有找到合適的餐廳。請嘗試其他位置或使用其他搜尋方式。'
                                        ]
                                    ]
                                ];
                            } else {
                                // 建立餐廳卡片
                                $bubbles = array_map(function ($r) {
                                    $photoRef = $r['photos'][0]['photo_reference'] ?? null;
                                    $photoUrl = $photoRef
                                        ? "https://maps.googleapis.com/maps/api/place/photo?maxwidth=600&photoreference=$photoRef&key=" . env('GOOGLE_MAPS_API_KEY')
                                        : 'https://via.placeholder.com/600x400?text=No+Image';

                                
                                    $searchQuery = urlencode($r['name'] . ' ' . ($r['vicinity'] ?? ''));
                                    $mapUrl = "https://maps.google.com/?q=" . $searchQuery;

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
                                            'contents' => [
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
                                                [
                                                    'type' => 'text', 
                                                    'text' => "地址：" . ($r['vicinity'] ?? '未知'), 
                                                    'wrap' => true, 
                                                    'size' => 'sm'
                                                ]
                                            ]
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

                                $postData = [
                                    'replyToken' => $replyToken,
                                    'messages' => [[
                                        'type' => 'flex',
                                        'altText' => '附近推薦餐廳',
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

                            Log::info('已回傳附近餐廳卡片', [
                                'restaurant_count' => count($restaurants),
                                'response_status' => $response->getStatusCode()
                            ]);
                            
                        } catch (\Exception $e) {
                            Log::error('處理位置訊息時發生錯誤: ' . $e->getMessage());
                            
                            // 發送錯誤訊息給使用者
                            $errorPostData = [
                                'replyToken' => $replyToken,
                                'messages' => [
                                    [
                                        'type' => 'text',
                                        'text' => '抱歉，查詢餐廳時發生錯誤，請稍後再試。'
                                    ]
                                ]
                            ];
                            
                            $client = new Client();
                            $client->post('https://api.line.me/v2/bot/message/reply', [
                                'headers' => [
                                    'Content-Type' => 'application/json',
                                    'Authorization' => 'Bearer ' . $token
                                ],
                                'json' => $errorPostData
                            ]);
                        }
                    }

                
                // 處理 postback 回傳
                if ($event['type'] === 'postback' && isset($event['postback']['data'])) {
                    $data = $event['postback']['data'];
                    
                    Log::info("使用者點選 postback: " . $data);

                    parse_str($data, $params);
                    if (is_array($params) && isset($params['action'])) {
                        if ($params['action'] === 'search') {
                            if ($params['by'] === 'area') {
                                // $userId = $event['source']['userId'];
                                // $userData =[
                                //     'search_type' => 'area',
                                //     'step' => 'select_area',
                                //     'timestamp' => now()->toDateTimeString(),
                                // ];

                                // $this->storeUserData($userId, $userData);

                                // $RestaurantController = new RestaurantController();

                                // // 發送地區選項訊息
                                // $RestaurantController->showAreaOptions($replyToken, $token);

                                $RestaurantController = new RestaurantController();

                                //發送位置請求
                                $userId = $event['source']['userId'];
                                $RestaurantController->shareUserInfo($userId, $token);
                                                       
                            } elseif ($params['by'] === 'type') {
                                $RestaurantController = new RestaurantController();

                                // 發送餐廳類型訊息
                                $RestaurantController->showTypeOptions($replyToken, $token);
                                
                                //發送位置請求
                                $userId = $event['source']['userId'];
                                $RestaurantController->shareUserInfo($userId, $token);

                                



                            } elseif ($params['by'] === 'popular') {
                                // Handle popular option directly
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
                        }
                    } else {
                        Log::error('Postback data解析失敗，或缺少必要的參數。');
                    }
                }
            }

            return response('OK', 200);
        } catch (\Exception $e) {
            Log::error('Error in LineController: ' . $e->getMessage());
            return response('Internal Server Error: ' . $e->getMessage(), 500);
        }
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

    public function getNearbyRestaurants($lat, $lng)
    {
    $apiKey = env('GOOGLE_MAPS_API_KEY');
    $radius = 1000;

    $url = "https://maps.googleapis.com/maps/api/place/nearbysearch/json";
    $params = [
        'location' => "$lat,$lng",
        'radius' => $radius,
        'type' => 'restaurant',
        'key' => $apiKey
    ];

    $client = new \GuzzleHttp\Client();
    $response = $client->get($url, ['query' => $params]);
    $results = json_decode($response->getBody(), true)['results'];

    // 篩選評分高的前 5 筆
    $restaurants = collect($results)
        ->filter(fn($place) => isset($place['rating']))
        ->sortByDesc('rating')
        ->take(5)
        ->values()
        ->all();

    return $restaurants;
    }

}