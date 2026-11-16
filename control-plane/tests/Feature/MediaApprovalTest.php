<?php

namespace Tests\Feature;

use App\Jobs\PublishMediaItem;
use App\Models\MediaItem;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::except(['api/v1/*']);
        Storage::fake('local');
        Storage::fake('music');
    }

    protected function tearDown(): void
    {
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::flushState();

        parent::tearDown();
    }

    public function test_station_owner_can_approve_pending_media_and_queue_publication(): void
    {
        Queue::fake();
        [$owner, $station, $media] = $this->makeMedia();

        $response = $this->actingAs($owner)->postJson(
            "/api/v1/stations/{$station->id}/media/{$media->id}/approve",
        );

        $response->assertAccepted()
            ->assertJsonPath('data.status', MediaItem::STATUS_PUBLISHING);

        $this->assertDatabaseHas('media_items', [
            'id' => $media->id,
            'status' => MediaItem::STATUS_PUBLISHING,
            'approved_by' => $owner->id,
        ]);

        $this->assertNotNull($media->fresh()->approved_at);
        Queue::assertPushed(PublishMediaItem::class, fn (PublishMediaItem $job): bool => $job->mediaItemId === $media->id);
    }

    public function test_non_owner_cannot_approve_station_media(): void
    {
        [, $station, $media] = $this->makeMedia();
        $other = User::query()->create([
            'name' => 'Other User',
            'email' => 'other@example.test',
            'password' => 'a-long-test-password',
        ]);

        $this->actingAs($other)->postJson(
            "/api/v1/stations/{$station->id}/media/{$media->id}/approve",
        )->assertForbidden();

        $this->assertSame(MediaItem::STATUS_PENDING, $media->fresh()->status);
    }

    public function test_publisher_copies_media_verifies_checksum_and_marks_ready_idempotently(): void
    {
        [, , $media] = $this->makeMedia(MediaItem::STATUS_PUBLISHING);
        $sourceContents = 'known test audio bytes';
        Storage::disk('local')->put($media->storage_key, $sourceContents);
        $media->forceFill([
            'sha256' => hash('sha256', $sourceContents),
            'file_size_bytes' => strlen($sourceContents),
        ])->save();

        $job = new PublishMediaItem($media->id);
        $job->handle();
        $job->handle();

        $target = "stations/{$media->station_id}/media/{$media->id}.ogg";
        Storage::disk('music')->assertExists($target);
        $this->assertSame($sourceContents, Storage::disk('music')->get($target));
        $this->assertSame(MediaItem::STATUS_READY, $media->fresh()->status);
        $this->assertNotNull($media->fresh()->published_at);
    }

    public function test_failed_publication_records_error_and_can_be_reapproved(): void
    {
        [, $station, $media] = $this->makeMedia(MediaItem::STATUS_PUBLISHING);
        (new PublishMediaItem($media->id))->failed(new \RuntimeException('source file missing'));

        $this->assertDatabaseHas('media_items', [
            'id' => $media->id,
            'status' => MediaItem::STATUS_FAILED,
            'publication_error' => 'source file missing',
        ]);

        Queue::fake();
        $owner = $station->owner;
        $this->actingAs($owner)->postJson(
            "/api/v1/stations/{$station->id}/media/{$media->id}/approve",
        )->assertAccepted()->assertJsonPath('data.status', MediaItem::STATUS_PUBLISHING);

        Queue::assertPushed(PublishMediaItem::class);
    }

    private function makeMedia(string $status = MediaItem::STATUS_PENDING): array
    {
        $owner = User::query()->create([
            'name' => 'Station Owner',
            'email' => 'owner@example.test',
            'password' => 'a-long-test-password',
        ]);

        $station = Station::query()->create([
            'user_id' => $owner->id,
            'name' => 'Test Radio',
            'slug' => 'test-radio',
            'status' => Station::STATUS_DRAFT,
            'desired_state' => Station::DESIRED_STOPPED,
            'playback_mode' => 'random',
            'mount_path' => '/stations/1.ogg',
        ]);

        $media = $station->mediaItems()->create([
            'original_filename' => 'track.ogg',
            'storage_key' => "stations/{$station->id}/media/test.ogg",
            'mime_type' => 'audio/ogg',
            'file_size_bytes' => 0,
            'sha256' => hash('sha256', ''),
            'status' => $status,
            'metadata' => ['artist' => 'Test Artist', 'title' => 'Test Track'],
        ]);

        return [$owner, $station, $media];
    }
}
