<?php

namespace App\Http\Controllers;

use LINE\Clients\MessagingApi\Model\PushMessageRequest;
use LINE\Clients\MessagingApi\Api\MessagingApiApi;
use LINE\Clients\MessagingApi\Model\TextMessage;
use LINE\Clients\MessagingApi\Model\QuickReply;
use LINE\Clients\MessagingApi\Model\QuickReplyItem;
use LINE\Clients\MessagingApi\Model\LocationAction;
use LINE\Clients\MessagingApi\Model\ButtonsTemplate;
use LINE\Clients\MessagingApi\Model\TemplateMessage;
use LINE\Clients\MessagingApi\Model\PostbackAction;
use LINE\Clients\MessagingApi\Model\ReplyMessageRequest;

use GuzzleHttp\Client;
use LINE\Clients\MessagingApi\Configuration;

class RestaurantController extends Controller
{
    public static function sendOptionsMenu(MessagingApiApi $bot, $replyToken)
    {
        $template = new ButtonsTemplate([
            'title' => '請選擇搜尋條件',
            'text' => '您可以根據以下條件搜尋餐廳：',
            'actions' => [
                new PostbackAction(['label' => '依地區', 'data' => 'action=search&by=area']),
                new PostbackAction(['label' => '依類型', 'data' => 'action=search&by=type']),
                new PostbackAction(['label' => '熱門推薦', 'data' => 'action=search&by=popular']),
            ],
        ]);
        
        $message = new TemplateMessage([
            'altText' => '請選擇搜尋條件',
            'template' => $template,
        ]);
        
        $request = new ReplyMessageRequest([
            'replyToken' => $replyToken,
            'messages' => [$message]
        ]);
        
        $bot->replyMessage($request);
    }
    
    public static function showAreaOptions(MessagingApiApi $bot, $replyToken)
    {
        $message = new TextMessage(['text' => '請輸入您想搜尋的地區（例如：台北市、中山區）']);
        
        $request = new ReplyMessageRequest([
            'replyToken' => $replyToken,
            'messages' => [$message]
        ]);
        
        $bot->replyMessage($request);
    }
    
    public static function showTypeOptions($replyToken, $token)
    {
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

    }
    
    public static function showPopularRestaurants(MessagingApiApi $bot, $replyToken)
    {
        $message = new TextMessage(['text' => "以下是熱門推薦餐廳：\n1. 餐廳A\n2. 餐廳B\n3. 餐廳C"]);
        
        $request = new ReplyMessageRequest([
            'replyToken' => $replyToken,
            'messages' => [$message]
        ]);
        
        $bot->replyMessage($request);
    }
    
    public static function shareUserInfo($userId, $token)
    {
        $locationPostData = [
            'to' => $userId,
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
                                    'label' => '分享位置'
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        try {
            // 稍微延遲發送，避免衝突
            sleep(1);

            $client = new Client();
            $client->post('https://api.line.me/v2/bot/message/push', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $token
                ],
                'json' => $locationPostData
            ]);

            Log::info('Location request sent successfully');
        } catch (\Exception $e) {
            Log::error('Error sending location request: ' . $e->getMessage());
        }
    }
}