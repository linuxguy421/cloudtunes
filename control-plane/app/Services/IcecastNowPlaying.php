<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class IcecastNowPlaying
{
    /**
     * Read the configured Icecast mount and resolve its current track artwork.
     *
     * @return array{online: bool, artist: ?string, title: ?string, genre: ?string, bitrate: ?string, artwork_url: ?string}
     */
    public function current(): array
    {
        $empty = [
            'online' => false,
            'artist' => null,
            'title' => null,
            'genre' => null,
            'bitrate' => null,
            'artwork_url' => null,
        ];

        try {
            $response = Http::acceptJson()
                ->timeout(3)
                ->get((string) config('cloudtunes.icecast_status_url'));

            if (! $response->successful()) {
                return $empty;
            }

            $sources = $response->json('icestats.source');

            if (! is_array($sources)) {
                return $empty;
            }

            $source = $this->selectMount($sources, (string) config('cloudtunes.icecast_mount'));

            if ($source === null) {
                return $empty;
            }

            $artist = $this->nullableString($source['artist'] ?? null);
            $title = $this->nullableString($source['title'] ?? null);
            $genre = $this->nullableString($source['genre'] ?? null);
            $bitrate = $this->nullableString($source['ice-bitrate'] ?? null);

            return [
                'online' => true,
                'artist' => $artist,
                'title' => $title,
                'genre' => $genre,
                'bitrate' => $bitrate,
                'artwork_url' => $this->artwork($artist, $title),
            ];
        } catch (Throwable) {
            // The public player should remain available even if Icecast or the
            // optional artwork provider is temporarily unreachable.
            return $empty;
        }
    }

    private function selectMount(array $sources, string $mount): ?array
    {
        if (array_is_list($sources)) {
            foreach ($sources as $source) {
                if (is_array($source) && $this->matchesMount($source, $mount)) {
                    return $source;
                }
            }

            return null;
        }

        // Icecast emits a single source as an object and multiple sources as a list.
        return $this->matchesMount($sources, $mount) ? $sources : null;
    }

    private function matchesMount(array $source, string $mount): bool
    {
        if (isset($source['mount']) && $source['mount'] === $mount) {
            return true;
        }

        $listenUrl = $source['listenurl'] ?? null;

        return is_string($listenUrl) && parse_url($listenUrl, PHP_URL_PATH) === $mount;
    }

    private function artwork(?string $artist, ?string $title): ?string
    {
        $apiKey = (string) config('cloudtunes.lastfm_api_key');

        if ($apiKey === '' || $artist === null || $title === null) {
            return null;
        }

        $cacheKey = 'cloudtunes.track-artwork.'.sha1(mb_strtolower($artist.'|'.$title));

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($apiKey, $artist, $title): ?string {
            try {
                $response = Http::timeout(3)->get('https://ws.audioscrobbler.com/2.0/', [
                    'method' => 'track.getInfo',
                    'api_key' => $apiKey,
                    'format' => 'json',
                    'artist' => $artist,
                    'track' => $title,
                ]);

                if (! $response->successful()) {
                    return null;
                }

                $images = $response->json('track.album.image', []);

                if (! is_array($images)) {
                    return null;
                }

                foreach (array_reverse($images) as $image) {
                    $url = is_array($image) ? ($image['#text'] ?? null) : null;

                    if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                        return $url;
                    }
                }
            } catch (Throwable) {
                return null;
            }

            return null;
        });
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
