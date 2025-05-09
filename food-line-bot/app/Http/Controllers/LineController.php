<?php

namespace App\Http\Controllers;
use App\Http\Controllers\RestaurantController;
use LINE\LINEBot\Event\MessageEvent;
use LINE\LINEBot\Event\PostbackEvent;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LINE\LINEBot;
use LINE\LINEBot\Constant\HTTPHeader;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;


class LineController extends Controller
{
    public function index(Request $request)
    {
        $secret = env('LINE_BOT_CHANNEL_SECRET', 'your_channel_secret');
        $token = env('LINE_BOT_CHANNEL_ACCESS_TOKEN', 'your_channel_access_token');

        Log::info('Loaded Channel Secret: ' . $secret);
        Log::info('Loaded Channel Access Token: ' . $token);

        try {
            $httpClient = new CurlHTTPClient($token);
            $bot = new LINEBot($httpClient, ["channelSecret" => $secret]);

            Log::info('Webhook request content: ' . $request->getContent());

            $signature = $request->header(HTTPHeader::LINE_SIGNATURE);
            if (!$signature) {
                Log::warning('Signature missing for testing');
            }

            try {
                $events = $bot->parseEventRequest($request->getContent(), $signature);
            } catch (\Exception $e) {
                Log::error('Parse event failed: ' . $e->getMessage());
                return response('Event parsing failed: ' . $e->getMessage(), 400);
            }

            if (!is_array($events)) {
                Log::error('Events is not an array: ' . json_encode($events));
                return response('Invalid events format', 400);
            }

            if (empty($events)) {
                Log::info('No events received');
                return response('OK', 200);
            }


            foreach ($events as $event) {
                // 使用者輸入關鍵字「選單」
                if ($event instanceof MessageEvent && $event->getText() === '選單') {
                    RestaurantController::sendOptionsMenu($bot, $event->getReplyToken());
                    continue; // 處理完就進下一個事件
                }
            
                // 處理 postback 回傳（例如按鈕被點擊）
                if ($event instanceof PostbackEvent) {
                    $data = $event->getPostbackData(); // 取得 postback 資料
                    Log::info("使用者點選 postback: " . $data); // 記錄使用者點選的 postback 資料
            
                    /*你可以根據 $data 的內容做不同動作
                      例如：action=search&by=area */
                    parse_str($data, $params);
                    // 檢查 parse_str 是否成功解析成陣列
                    if(is_array($params) && isset($params['action'])) {
                        if ($params['action'] === 'search') {
                        if ($params['by'] === 'area') {
                            RestaurantController::showAreaOptions($bot, $event->getReplyToken());
                        } elseif ($params['by'] === 'type') {
                            RestaurantController::showTypeOptions($bot, $event->getReplyToken());
                        } elseif ($params['by'] === 'popular') {
                            RestaurantController::showPopularRestaurants($bot, $event->getReplyToken());
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