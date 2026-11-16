<?php

namespace App\\Http\\Controllers\\Api\\V1;

use App\\Http\\Controllers\\Controller;
use App\\Models\\MediaItem;
use App\\Models\\Station;
use App\\Services\\Media\\InvalidMediaFileException;
use App\\Services\\Media\\MediaInspector;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Gate;
use Illuminate\\Support\\Facades\\Storage;
use Illuminate\\Support\\Str;
use Illuminate\\Validation\\ValidationException;
use RuntimeException;
use Throwable;

class MediaUploadController extends Controller
{
    private const MAX_UPLOAD_KILOBYTES = 102400;

    public function store(Request $request, Station $station, MediaInspector $inspector): JsonResponse
    {
        Gate::authorize('update', $station);

        $validated = $request->validate([
            'file' => ['required', 'file', 'extensions:ogg', 'max:'.self::MAX_UPLOAD_KILOBYTES],
        ]);

        $file = $validated['file'];

        try {
            $inspection = $inspector->inspect($file->getRealPath());
        } catch (InvalidMediaFileException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $sha256 = hash_file('sha256', $file->getRealPath());
        if (! is_string($sha256)) {
            throw new RuntimeException('Unable to calculate the uploaded file checksum.');
        }

        $duplicate = $station->mediaItems()->where('sha256', $sha256)->first();
        if ($duplicate !== null) {
            return response()->json([
                'message' => 'This audio file has already been uploaded to this station.',
                'data' => ['id' => $duplicate->id],
            ], 409);
        }

        $originalFilename = basename(str_replace('\\\\', '/', $file->getClientOriginalName()));
        $originalFilename = Str::limit($originalFilename, 255, '');
        $storageKey = $file->storeAs(
            'stations/'.$station->getKey().'/media',
            Str::uuid().'.ogg',
            'local',
        );

        if (! is_string($storageKey)) {
            throw new RuntimeException('Unable to store the uploaded audio file.');
        }

        try {
            $mediaItem = $station->mediaItems()->create([
                'original_filename' => $originalFilename,
                'storage_key' => $storageKey,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'file_size_bytes' => $file->getSize(),
                'sha256' => $sha256,
                'status' => MediaItem::STATUS_PENDING,
                'metadata' => $inspection,
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storageKey);
            throw $exception;
        }

        return response()->json([
            'data' => [
                'id' => $mediaItem->id,
                'original_filename' => $mediaItem->original_filename,
                'status' => $mediaItem->status,
                'file_size_bytes' => $mediaItem->file_size_bytes,
                'metadata' => $mediaItem->metadata,
            ],
        ], 201);
    }
}
