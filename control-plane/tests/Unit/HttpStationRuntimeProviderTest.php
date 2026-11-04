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
    public function test_reconcile_sends_allowlisted_fields_and_verifies_hmac_acknowledgement(): void
    {
        config([
            'cloudtunes.runtime.controller_url' => 'http://runtime-controller:8090',
            'cloudtunes.runtime.shared_secret' => 'test-shared-secret',
            'cloudtunes.runtime.timeout' => 20,
        ]);

        $operationId = (string) Str::uuid();
        Http::fake([
            'http://runtime-controller:8090/*' => Http::response([
                'accepted' => true,
                'operation_id' => $operationId,
                'runtime_generation' => 7,
                'message' => 'Intent durably recorded.',
            ], 202),
        ]);

        $station = new Station([
            'name' => 'Night Drive',
            'slug' => 'night-drive',
            'mount_path' => '/stations/42.ogg',
            'playback_mode' => 'random',
            'desired_state' => Station::DESIRED_RUNNING,
            'runtime_generation' => 7,
            'configuration' => ['private' => 'must-not-be-sent'],
        ]);
        $station->setAttribute($station->getKeyName(), 42);

        $result = (new HttpStationRuntimeProvider())->reconcile($station, $operationId);

        $this->assertTrue($result['accepted']);
        $this->assertSame($operationId, $result['operation_id']);
        $this->assertSame(7, $result['runtime_generation']);

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
