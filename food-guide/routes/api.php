<?php
// food-guide/routes/api.php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LineBotController;

Route::post('/line/webhook', [LineBotController::class, 'handle']);


Route::post('/line/chat', [LineBotController::class, 'index']);
