<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\PublishMediaItem;
use App\Models\MediaItem;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MediaApprovalController extends Controller
{
    public function approve(Station $station, MediaItem $mediaItem): JsonResponse
    {
        Gate::authorize('update', $station);

        if ((string) $mediaItem->station_id !== (string) $station->getKey()) {
            abort(404);
        }

        $approved = DB::transaction(function () use ($station, $mediaItem): MediaItem {
            $locked = MediaItem::query()->whereKey($mediaItem->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [
                MediaItem::STATUS_PENDING,
                MediaItem::STATUS_FAILED,
            ], true)) {
                abort(response()->json([
                    'message' => 'Only pending or failed media can be approved.',
                    'data' => ['status' => $locked->status],
                ], 409));
            }

            $locked->forceFill([
                'status' => MediaItem::STATUS_PUBLISHING,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'published_at' => null,
                'publication_error' => null,
            ])->save();

            PublishMediaItem::dispatch($locked->getKey())->afterCommit();

            return $locked;
        });

        return response()->json([
            'message' => 'Media approved and queued for publication.',
            'data' => [
                'id' => $approved->getKey(),
                'status' => $approved->status,
                'approved_at' => $approved->approved_at,
            ],
        ], 202);
    }
}
