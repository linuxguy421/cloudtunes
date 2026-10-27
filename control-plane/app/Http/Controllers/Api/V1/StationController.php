<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class StationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $stations = Station::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->orderBy('id')
            ->get()
            ->map(fn (Station $station): array => $this->present($station));

        return response()->json(['data' => $stations]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Station::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $baseSlug = Str::slug($validated['name']);
        if ($baseSlug === '') {
            $baseSlug = 'station';
        }

        $slug = Str::limit($baseSlug, 70, '');
        $suffix = 2;
        while (Station::query()->where('slug', $slug)->exists()) {
            $tail = '-' . $suffix++;
            $slug = Str::limit($baseSlug, 80 - strlen($tail), '') . $tail;
        }

        $station = Station::query()->create([
            'user_id' => $request->user()->getAuthIdentifier(),
            'name' => $validated['name'],
            'slug' => $slug,
            'status' => Station::STATUS_DRAFT,
            'desired_state' => Station::DESIRED_STOPPED,
            'playback_mode' => 'random',
            'mount_path' => '/' . $slug . '.ogg',
        ]);

        return response()->json(['data' => $this->present($station)], 201);
    }

    private function present(Station $station): array
    {
        return [
            'id' => $station->id,
            'name' => $station->name,
            'slug' => $station->slug,
            'status' => $station->status,
            'desired_state' => $station->desired_state,
            'playback_mode' => $station->playback_mode,
            'mount_path' => $station->mount_path,
            'created_at' => $station->created_at,
        ];
    }
}
