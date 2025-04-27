<?php
// food-guide/routes/web.php
// routes/web.php
use App\Http\Controllers\RestaurantController;

Route::get('/search', [RestaurantController::class, 'search']);
