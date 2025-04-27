<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurants', function (Blueprint $table) {
            $table->id();
            $table->string('name');         // 店名
            $table->string('address');      // 地址
            $table->float('rating');        // 評分
            $table->string('google_map_url'); // Google 地圖連結
            $table->timestamps();           // 建立時間、更新時間
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurants');
    }
};
