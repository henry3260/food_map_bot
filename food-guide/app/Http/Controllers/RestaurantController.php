<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;

class RestaurantController extends Controller
{
    public function search()
    {
        $location = request()->query('location', '25.0478,121.5319');
        $radius = request()->query('radius', 500);
        $keyword = request()->query('keyword', '燒烤');

        $results = $this->searchRestaurants($location, $radius, $keyword);

        if (!empty($results)) {
            return response()->json($results);
        }

        return response()->json(['error' => 'Failed to fetch data'], 500);
    }

    public function searchRestaurants($location, $radius, $keyword)
    {
        $apiKey = env('GOOGLE_MAPS_API_KEY');
        $url = "https://maps.googleapis.com/maps/api/place/nearbysearch/json?location=$location&radius=$radius&keyword=$keyword&key=$apiKey";

        $response = Http::get($url);
        if ($response->successful()) {
            return $response->json()['results'];
        }

        return [];
    }
}