<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Station;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use LogicException;

class StationController extends Controller
{
    public function index(Request $request): View
    {
        $stations = Station::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->orderByDesc('created_at')
            ->get();

        return view('stations.index', [
            'stations' => $stations,
            'canCreateStation' => Gate::allows('create', Station::class),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Station::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $baseSlug = Str::slug($validated['name']);
        if ($baseSlug === '') {
            $baseSlug = 'station';
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $slug = $this->nextAvailableSlug($baseSlug);

            try {
                Station::query()->create([
                    'user_id' => $request->user()->getAuthIdentifier(),
                    'name' => $validated['name'],
                    'slug' => $slug,
                    'status' => Station::STATUS_DRAFT,
                    'desired_state' => Station::DESIRED_STOPPED,
                    'playback_mode' => 'random',
                    'mount_path' => '/' . $slug . '.ogg',
                ]);

                return redirect()->route('stations.index')->with('status', 'Station created. Runtime playback has not been started.');
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

        return in_array($sqlState, ['23505', '23000'], true)
            && (
                str_contains($message, 'stations_slug_unique')
                || str_contains($message, 'stations_mount_path_unique')
                || str_contains($message, 'stations.slug')
                || str_contains($message, 'stations.mount_path')
            );
    }
}
