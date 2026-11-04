<?php

namespace App\Runtime;

use App\Models\Station;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

class HttpStationRuntimeProvider implements StationRuntimeProvider
{
    /**
     * @throws JsonException
     */
    public function reconcile(Station $station, string $operationId): array
    {
        $baseUrl = rtrim((string) config('cloudtunes.runtime.controller_url'), '/');
        $secret = (string) config('cloudtunes.runtime.shared_secret');

        if ($baseUrl === '' || $secret === '') {
            throw new RuntimeException('Station runtime controller is not configured.');
        }

        $path = '/v1/stations/'.rawurlencode((string) $station->getKey()).'/reconcile';
        $generation = (int) $station->runtime_generation;
        $payload = [
            'operation_id' => $operationId,
            'station' => [
                'id' => (string) $station->getKey(),
                'name' => $station->name,
                'slug' => $station->slug,
                'mount_path' => $station->mount_path,
                'playback_mode' => $station->playback_mode,
                'desired_state' => $station->desired_state,
                'runtime_generation' => $generation,
            ],
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $canonical = implode("\n", [
            $timestamp,
            'POST',
            $path,
            hash('sha256', $body),
        ]);
        $signature = hash_hmac('sha256', $canonical, $secret);

        $response = Http::timeout((int) config('cloudtunes.runtime.timeout', 20))
            ->withHeaders([
                'Accept' => 'application/json',
                'X-CloudTunes-Timestamp' => $timestamp,
                'X-CloudTunes-Signature' => $signature,
            ])
            ->withBody($body, 'application/json')
            ->post($baseUrl.$path);

        $response->throw();
        $result = $response->json();

        if (! is_array($result)
            || ! ($result['accepted'] ?? false)
            || ! is_string($result['operation_id'] ?? null)
            || ! hash_equals($operationId, $result['operation_id'])
            || ! is_int($result['runtime_generation'] ?? null)
            || $result['runtime_generation'] !== $generation) {
            throw new UnexpectedValueException('Runtime controller returned an invalid operation acknowledgement.');
        }

        return [
            'accepted' => true,
            'operation_id' => $result['operation_id'],
            'runtime_generation' => $result['runtime_generation'],
            'message' => is_string($result['message'] ?? null) ? $result['message'] : null,
        ];
    }
}
