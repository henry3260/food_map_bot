<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use LINE\LINEBot;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use LINE\LINEBot\MessageBuilder\TemplateMessageBuilder;
use LINE\LINEBot\TemplateBuilder\ButtonTemplateBuilder;
use LINE\LINEBot\TemplateBuilder\UriActionBuilder;

class LineBotController extends Controller
{
    protected $bot;

    public function __construct()
    {
        $httpClient = new CurlHTTPClient(env('LINE_BOT_CHANNEL_ACCESS_TOKEN'));
        $this->bot = new LINEBot($httpClient, ['channelSecret' => env('LINE_BOT_CHANNEL_SECRET')]);
    }

    public function handle(Request $request)
    {
        $signature = $request->header('X-Line-Signature');
        $body = $request->getContent();

        // 驗證簽名
        if (!$this->bot->validateSignature($body, env('LINE_BOT_CHANNEL_SECRET'), $signature)) {
            return response('Invalid signature', 400);
        }

        $events = $this->bot->parseEventRequest($body, $signature);

        foreach ($events as $event) {
            if ($event->isMessage()) {
                $replyToken = $event->getReplyToken();

                // 處理文字訊息
                if ($event->getMessageType() === 'text') {
                    $text = $event->getText();
                    if ($text === '搜尋餐廳') {
                        // 顯示選項按鈕
                        $this->sendOptionsMenu($replyToken);
                    }
                }

                // 處理位置訊息
                if ($event->getMessageType() === 'location') {
                    $latitude = $event->getLatitude();
                    $longitude = $event->getLongitude();
                    $location = "$latitude,$longitude";

                    // 儲存位置到 session 或資料庫（這裡簡化為直接使用）
                    // 假設使用者已經選擇了 keyword 和 radius
                    $keyword = '火鍋'; // 實際應用中應從使用者輸入或儲存中取得
                    $radius = 2000;    // 實際應用中應從使用者輸入或儲存中取得

                    // 呼叫 RestaurantController 的搜尋功能
                    $results = $this->searchRestaurants($location, $radius, $keyword);

                    // 格式化結果
                    $message = $this->formatResults($results);
                    $this->bot->replyMessage($replyToken, new TextMessageBuilder($message));
                }
            }

            // 處理按鈕點擊（Postback）
            if ($event->isPostback()) {
                $replyToken = $event->getReplyToken();
                $data = $event->getPostbackData();

                // 解析 postback 資料
                parse_str($data, $params);

                if (isset($params['action'])) {
                    if ($params['action'] === 'set_keyword') {
                        $keyword = $params['value'];
                        $this->bot->replyMessage($replyToken, new TextMessageBuilder("已選擇關鍵字：$keyword，請分享您的位置以進行搜尋。"));
                    } elseif ($params['action'] === 'set_radius') {
                        $radius = $params['value'];
                        $this->bot->replyMessage($replyToken, new TextMessageBuilder("已選擇範圍：$radius 公尺，請選擇關鍵字或分享位置。"));
                    }
                }
            }
        }

        return response('OK', 200);
    }

    protected function sendOptionsMenu($replyToken)
    {
        $buttonTemplate = new ButtonTemplateBuilder(
            '餐廳搜尋選項',
            '請選擇搜尋條件或分享位置',
            null,
            [
                new UriActionBuilder('分享位置', 'line://nv/location'),
                new LINEBot\TemplateBuilder\PostbackActionBuilder('選擇關鍵字', 'action=set_keyword&value=火鍋', '火鍋'),
                new LINEBot\TemplateBuilder\PostbackActionBuilder('選擇範圍', 'action=set_radius&value=2000', '2公里'),
            ]
        );

        $templateMessage = new TemplateMessageBuilder('餐廳搜尋', $buttonTemplate);
        $this->bot->replyMessage($replyToken, $templateMessage);
    }

    protected function searchRestaurants($location, $radius, $keyword)
    {
        $apiKey = env('GOOGLE_MAPS_API_KEY');
        $url = "https://maps.googleapis.com/maps/api/place/nearbysearch/json?location=$location&radius=$radius&keyword=$keyword&key=$apiKey";

        $response = Http::get($url);
        if ($response->successful()) {
            return $response->json()['results'];
        }

        return [];
    }

    protected function formatResults($results)
    {
        if (empty($results)) {
            return '找不到符合條件的餐廳！';
        }

        $message = "搜尋結果：\n";
        foreach (array_slice($results, 0, 5) as $result) {
            $name = $result['name'];
            $address = $result['vicinity'] ?? '無地址';
            $message .= "名稱：$name\n地址：$address\n\n";
        }

        return $message;
    }
}