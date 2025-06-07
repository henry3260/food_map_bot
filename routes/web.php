<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LineController;

Route::get('/', function () {
    return '這是我的專案首頁';
});


Route::post('/line/chat', [LineController::class, 'index']);