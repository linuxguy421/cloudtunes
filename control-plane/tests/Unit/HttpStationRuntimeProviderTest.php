<?php

namespace Tests\Unit;

use App\Models\Station;
use App\Runtime\HttpStationRuntimeProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class HttpStationRuntimeProviderTest extends TestCase
{
    public function test_reconcile_sends_only_allowlisted_station_fields_with_hmac_signature(): void
    {
        config([
            'cloudtunes.runtime.controller_url' => 'http://runtime-controller:8090',
            'cloudtunes.runtime.shared_secret' => 'test-shared-secret',
            'cloudtunes.runtime.timeout' => 20,
        ]);

        Http::fake([
            'http://runtime-controller:8090/*' => Http::response([
                'accepted' => true,
                'observed_state' => Station::STATUS_LIVE,
                'message' => 'Station connected.',
            ], 200),
        ]);

        $station = new Station([
            'name' => 'Night Drive',
            'slug' => 'night-drive',
            'mount_path' => '/night-drive.ogg',
            'playback_mode' => 'random',
            'desired_state' => Station::DESIRED_RUNNING,
            'runtime_generation' => 7,
            'configuration' => ['private' => 'must-not-be-sent'],
        ]);
        $station->setAttribute($station->getKeyName(), 42);
        $operationId = (string) Str::uuid();

        $result = (new HttpStationRuntimeProvider())->reconcile($station, $operationId);

        $this->assertSame(Station::STATUS_LIVE, $result['observed_state']);

        Http::assertSent(function (Request $request) use ($operationId): bool {
            $timestamp = $request->header('X-CloudTunes-Timestamp')[0] ?? '';
            $path = '/v1/stations/42/reconcile';
            $body = $request->body();
            $canonical = implode("\n", [
                $timestamp,
                'POST',
                $path,
                hash('sha256', $body),
            ]);

            return $request->url() === 'http://runtime-controller:8090'.$path
                && $request->method() === 'POST'
                && hash_equals(
                    hash_hmac('sha256', $canonical, 'test-shared-secret'),
                    $request->header('X-CloudTunes-Signature')[0] ?? '',
                )
                && data_get($request->data(), 'operation_id') === $operationId
                && data_get($request->data(), 'station.desired_state') === Station::DESIRED_RUNNING
                && data_get($request->data(), 'station.runtime_generation') === 7
                && ! array_key_exists('configuration', $request->data()['station'] ?? []);
        });
    }
}
