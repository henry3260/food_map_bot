<?php

namespace App\Http\Controllers;

use App\Http\Controllers\RestaurantController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use LINE\Clients\MessagingApi\Api\MessagingApiApi;
use LINE\Clients\MessagingApi\Configuration;
use GuzzleHttp\Client;

class LineController extends Controller
{
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
                    } else {
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
                
                // 處理 postback 回傳
                if ($event['type'] === 'postback' && isset($event['postback']['data'])) {
                    $data = $event['postback']['data'];
                    Log::info("使用者點選 postback: " . $data);

                    parse_str($data, $params);
                    if (is_array($params) && isset($params['action'])) {
                        if ($params['action'] === 'search') {
                            if ($params['by'] === 'area') {
                                // Handle area option directly
                                $postData = [
                                    'replyToken' => $replyToken,
                                    'messages' => [
                                        [
                                            'type' => 'text',
                                            'text' => '請輸入您想搜尋的地區（例如：台北市、中山區）'
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
                                // Handle type option directly
                                $postData = [
                                    'replyToken' => $replyToken,
                                    'messages' => [
                                        [
                                            'type' => 'text',
                                            'text' => '請輸入您想搜尋的餐廳類型（例如：火鍋、壽司、義大利麵）'
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
                                
                                // Also send the location request
                                $locationData = [
                                    'replyToken' => $replyToken,
                                    'messages' => [
                                        [
                                            'type' => 'text',
                                            'text' => '請直接傳送你的位置資訊，我們會根據你的位置推薦附近的餐廳！ 🍽️',
                                            'quickReply' => [
                                                'items' => [
                                                    [
                                                        'type' => 'action',
                                                        'action' => [
                                                            'type' => 'location',
                                                            'label' => '傳送位置'
                                                        ]
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ];
                                
                                // Note: We can't send two replies to the same replyToken
                                // This second message would need to be handled differently in production
                                
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
}