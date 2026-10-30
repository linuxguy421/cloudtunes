<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Station;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $stations = Station::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $stationCount = Station::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->count();

        return view('dashboard', [
            'user' => $user,
            'stations' => $stations,
            'stationCount' => $stationCount,
            'stationLimit' => $user->station_limit,
        ]);
    }
}
