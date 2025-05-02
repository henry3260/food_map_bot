<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LINE\LINEBot;
use LINE\LINEBot\Constant\HTTPHeader;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;

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
                $text = data_get($event, 'message.text');
                $replyToken = data_get($event, 'replyToken');

                if (empty($replyToken)) {
                    Log::info('No replyToken in event: ' . json_encode($event));
                    continue;
                }

                try {
                    $response = $bot->replyText($replyToken, "我是機器人 好好教我你想幹嘛的訊息..." . $text . " 繼續講解吧！");
                    if ($response->isSucceeded()) {
                        Log::info('Reply succeeded for text: ' . $text);
                    } else {
                        Log::error('Reply failed: ' . $response->getHTTPStatus() . ' ' . $response->getRawBody());
                    }
                } catch (\Exception $e) {
                    Log::error('Reply failed: ' . $e->getMessage());
                }
            }

            return response('OK', 200);
        } catch (\Exception $e) {
            Log::error('Error in LineController: ' . $e->getMessage());
            return response('Internal Server Error: ' . $e->getMessage(), 500);
        }
    }
}