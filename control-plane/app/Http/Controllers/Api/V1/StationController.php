<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Station;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;

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

        $station = $this->createStationWithSlugRetry(
            $request->user()->getAuthIdentifier(),
            $validated['name'],
            $baseSlug,
        );

        return response()->json(['data' => $this->present($station)], 201);
    }

    private function createStationWithSlugRetry(int|string $userId, string $name, string $baseSlug): Station
    {
        // The existence check handles ordinary duplicate names. The unique
        // constraint remains authoritative if concurrent requests race.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $slug = $this->nextAvailableSlug($baseSlug);

            try {
                return Station::query()->create([
                    'user_id' => $userId,
                    'name' => $name,
                    'slug' => $slug,
                    'status' => Station::STATUS_DRAFT,
                    'desired_state' => Station::DESIRED_STOPPED,
                    'playback_mode' => 'random',
                    'mount_path' => '/' . $slug . '.ogg',
                ]);
            } catch (QueryException $exception) {
                if (! $this->isSlugOrMountCollision($exception) || $attempt === 4) {
                    throw $exception;
                }
            }
        }

        throw new LogicException('Station slug retry loop exited unexpectedly.');
    }

    private function nextAvailableSlug(string $baseSlug): string
    {
        $slug = Str::limit($baseSlug, 70, '');
        $suffix = 2;

        while (Station::query()->where('slug', $slug)->exists()) {
            $tail = '-' . $suffix++;
            $slug = Str::limit($baseSlug, 80 - strlen($tail), '') . $tail;
        }

        return $slug;
    }

    private function isSlugOrMountCollision(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $message = strtolower($exception->getMessage());

        $isUniqueViolation = $sqlState === '23505' || $sqlState === '23000';
        $isSlugOrMountConstraint = str_contains($message, 'stations_slug_unique')
            || str_contains($message, 'stations_mount_path_unique')
            || str_contains($message, 'stations.slug')
            || str_contains($message, 'stations.mount_path');

        return $isUniqueViolation && $isSlugOrMountConstraint;
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
