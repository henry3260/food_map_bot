<?php
// food-guide/routes/api.php
use App\Http\Controllers\LineBotController;

Route::post('/line/webhook', [LineBotController::class, 'handle']);