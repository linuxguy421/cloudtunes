<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\IcecastNowPlaying;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class PlayerController extends Controller
{
    public function show(IcecastNowPlaying $nowPlaying): View
    {
        return view('player', [
            'streamUrl' => rtrim((string) config('cloudtunes.icecast_public_url'), '/')
                .(string) config('cloudtunes.icecast_mount'),
            'nowPlaying' => $nowPlaying->current(),
        ]);
    }

    public function status(IcecastNowPlaying $nowPlaying): JsonResponse
    {
        return response()->json($nowPlaying->current());
    }
}
