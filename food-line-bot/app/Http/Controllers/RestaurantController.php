<?php

namespace App\Http\Controllers;

use LINE\LINEBot;
use LINE\LINEBot\MessageBuilder\TemplateMessageBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder;
use LINE\LINEBot\TemplateActionBuilder\PostbackTemplateActionBuilder;

class RestaurantController extends Controller
{
    public static function sendOptionsMenu(LINEBot $bot, $replyToken)
    {
        $buttons = new ButtonTemplateBuilder(
            "請選擇搜尋條件",
            "您可以根據以下條件搜尋餐廳：",
            null,
            [
                new PostbackTemplateActionBuilder("依地區", "action=search&by=area"),
                new PostbackTemplateActionBuilder("依類型", "action=search&by=type"),
                new PostbackTemplateActionBuilder("熱門推薦", "action=search&by=popular")
            ]
        );
    
        $template = new TemplateMessageBuilder("請選擇搜尋條件", $buttons);
    
        $bot->replyMessage($replyToken, $template);
    }

    public static function showAreaOptions(LINEBot $bot, $replyToken)
    {
        $bot->replyText($replyToken, "請輸入您想搜尋的地區（例如：台北市、中山區）");
    }

    public static function showTypeOptions(LINEBot $bot, $replyToken)
    {
        $bot->replyText($replyToken, "請輸入您想搜尋的餐廳類型（例如：火鍋、壽司、義大利麵）");
    }

    public static function showPopularRestaurants(LINEBot $bot, $replyToken)
    {
        // 這邊可以改成查詢熱門餐廳的結果
        $bot->replyText($replyToken, "以下是熱門推薦餐廳：\n1. 餐廳A\n2. 餐廳B\n3. 餐廳C");
    }
    
}
