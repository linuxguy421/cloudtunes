<?php

namespace Tests\\Feature;

use App\\Models\\MediaItem;
use App\\Models\\Station;
use App\\Models\\User;
use App\\Services\\Media\\InvalidMediaFileException;
use App\\Services\\Media\\MediaInspector;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Http\\UploadedFile;
use Illuminate\\Support\\Facades\\Storage;
use Mockery;
use Tests\\TestCase;

class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // This API uses the browser session and web CSRF middleware.
        \\Illuminate\\Foundation\\Http\\Middleware\\ValidateCsrfToken::except(['api/v1/*']);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        \\Illuminate\\Foundation\\Http\\Middleware\\ValidateCsrfToken::flushState();

        parent::tearDown();
    }

    public function test_owner_can_upload_valid_ogg_and_media_is_stored_as_pending(): void
    {
        [$owner, $station] = $this->makeStation();
        $this->mockInspector($this->validInspection());

        $response = $this->actingAs($owner)->postJson(
            "/api/v1/stations/{$station->id}/media",
            ['file' => UploadedFile::fake()->createWithContent('song.ogg', 'valid test payload')],
        );

        $response->assertCreated()
            ->assertJsonPath('data.original_filename', 'song.ogg')
            ->assertJsonPath('data.status', MediaItem::STATUS_PENDING)
            ->assertJsonPath('data.metadata.artist', 'Example Artist')
            ->assertJsonPath('data.metadata.title', 'Example Track')
            ->assertJsonPath('data.metadata.codec', 'vorbis');

        $mediaItem = MediaItem::query()->firstOrFail();
        $this->assertSame($station->id, $mediaItem->station_id);
        $this->assertSame(hash('sha256', 'valid test payload'), $mediaItem->sha256);
        Storage::disk('local')->assertExists($mediaItem->storage_key);
    }

    public function test_upload_rejects_invalid_audio_profile_or_missing_metadata(): void
    {
        [$owner, $station] = $this->makeStation();
        $inspector = Mockery::mock(MediaInspector::class);
        $inspector->shouldReceive('inspect')
            ->once()
            ->andThrow(new InvalidMediaFileException('The audio file must contain a valid ARTIST tag.'));
        $this->app->instance(MediaInspector::class, $inspector);

        $this->actingAs($owner)->postJson(
            "/api/v1/stations/{$station->id}/media",
            ['file' => UploadedFile::fake()->createWithContent('untagged.ogg', 'test payload')],
        )->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('media_items', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_non_owner_cannot_upload_to_another_users_station(): void
    {
        [, $station] = $this->makeStation();
        $otherUser = User::query()->create([
            'name' => 'Other User',
            'email' => 'other@example.test',
            'password' => 'a-long-test-password',
        ]);

        $inspector = Mockery::mock(MediaInspector::class);
        $inspector->shouldNotReceive('inspect');
        $this->app->instance(MediaInspector::class, $inspector);

        $this->actingAs($otherUser)->postJson(
            "/api/v1/stations/{$station->id}/media",
            ['file' => UploadedFile::fake()->createWithContent('song.ogg', 'payload')],
        )->assertForbidden();

        $this->assertDatabaseCount('media_items', 0);
    }

    public function test_upload_requires_authentication(): void
    {
        [, $station] = $this->makeStation();

        $this->postJson(
            "/api/v1/stations/{$station->id}/media",
            ['file' => UploadedFile::fake()->createWithContent('song.ogg', 'payload')],
        )->assertUnauthorized();
    }

    public function test_upload_rejects_non_ogg_filename_before_inspection(): void
    {
        [$owner, $station] = $this->makeStation();
        $inspector = Mockery::mock(MediaInspector::class);
        $inspector->shouldNotReceive('inspect');
        $this->app->instance(MediaInspector::class, $inspector);

        $this->actingAs($owner)->postJson(
            "/api/v1/stations/{$station->id}/media",
            ['file' => UploadedFile::fake()->createWithContent('song.mp3', 'payload')],
        )->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('media_items', 0);
    }

    public function test_duplicate_content_is_rejected_for_the_same_station(): void
    {
        [$owner, $station] = $this->makeStation();
        $inspector = Mockery::mock(MediaInspector::class);
        $inspector->shouldReceive('inspect')->twice()->andReturn($this->validInspection());
        $this->app->instance(MediaInspector::class, $inspector);

        $upload = fn () => UploadedFile::fake()->createWithContent('same-song.ogg', 'identical audio bytes');

        $this->actingAs($owner)->postJson(
            "/api/v1/stations/{$station->id}/media",
            ['file' => $upload()],
        )->assertCreated();

        $this->actingAs($owner)->postJson(
            "/api/v1/stations/{$station->id}/media",
            ['file' => $upload()],
        )->assertStatus(409)->assertJsonPath('message', 'This audio file has already been uploaded to this station.');

        $this->assertDatabaseCount('media_items', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    private function makeStation(): array
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

        return [$owner, $station];
    }

    private function mockInspector(array $inspection): void
    {
        $inspector = Mockery::mock(MediaInspector::class);
        $inspector->shouldReceive('inspect')->once()->andReturn($inspection);
        $this->app->instance(MediaInspector::class, $inspector);
    }

    private function validInspection(): array
    {
        return [
            'container' => 'ogg',
            'codec' => 'vorbis',
            'sample_rate' => 44100,
            'channels' => 2,
            'artist' => 'Example Artist',
            'title' => 'Example Track',
        ];
    }
}
