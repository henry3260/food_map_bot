<?php
// food-guide/routes/web.php
// routes/web.php
use App\Http\Controllers\RestaurantController;
use App\Http\Controllers\LineBotController;

Route::get('/', function () {
    return "Hello";  // 或者自定義的首頁視圖
});

Route::get('/search', [RestaurantController::class, 'search']);


Route::prefix('api')->group(function () {
    require base_path('routes/api.php');
});