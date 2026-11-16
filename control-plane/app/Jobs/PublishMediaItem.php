<?php

namespace App\Jobs;

use App\Models\MediaItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class PublishMediaItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public readonly int $mediaItemId)
    {
    }

    public function handle(): void
    {
        $item = MediaItem::query()->find($this->mediaItemId);

        if ($item === null || $item->status !== MediaItem::STATUS_PUBLISHING) {
            return;
        }

        if (! is_string($item->sha256) || ! preg_match('/^[a-f0-9]{64}$/', $item->sha256)) {
            throw new RuntimeException('Media item does not have a valid SHA-256 checksum.');
        }

        $source = Storage::disk('local')->path($item->storage_key);
        if (! is_file($source)) {
            throw new RuntimeException('The private source media file is missing.');
        }

        if (filesize($source) !== $item->file_size_bytes || hash_file('sha256', $source) !== $item->sha256) {
            throw new RuntimeException('The private source media file failed integrity verification.');
        }

        $relativeTarget = sprintf(
            'stations/%d/media/%d.ogg',
            $item->station_id,
            $item->getKey(),
        );
        $target = Storage::disk('music')->path($relativeTarget);
        $directory = dirname($target);

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the station media directory.');
        }

        if (! is_file($target) || hash_file('sha256', $target) !== $item->sha256) {
            $temporary = $target.'.'.bin2hex(random_bytes(8)).'.tmp';

            try {
                if (! copy($source, $temporary)) {
                    throw new RuntimeException('Unable to copy media into the publication directory.');
                }

                if (filesize($temporary) !== $item->file_size_bytes || hash_file('sha256', $temporary) !== $item->sha256) {
                    throw new RuntimeException('Published media failed checksum verification.');
                }

                if (! rename($temporary, $target)) {
                    throw new RuntimeException('Unable to atomically publish the media file.');
                }

                chmod($target, 0640);
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        }

        DB::transaction(function (): void {
            $locked = MediaItem::query()->whereKey($this->mediaItemId)->lockForUpdate()->first();

            if ($locked !== null && $locked->status === MediaItem::STATUS_PUBLISHING) {
                $locked->forceFill([
                    'status' => MediaItem::STATUS_READY,
                    'published_at' => now(),
                    'publication_error' => null,
                ])->save();
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        MediaItem::query()
            ->whereKey($this->mediaItemId)
            ->where('status', MediaItem::STATUS_PUBLISHING)
            ->update([
                'status' => MediaItem::STATUS_FAILED,
                'publication_error' => mb_substr($exception->getMessage(), 0, 4000),
                'updated_at' => now(),
            ]);
    }
}
