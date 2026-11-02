<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class IcecastNowPlaying
{
    private const NO_ARTWORK = '__cloudtunes_no_artwork__';

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
            // The public player should remain available even if Icecast is temporarily unreachable.
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
        if ($artist === null || $title === null) {
            return null;
        }

        $cacheKey = 'cloudtunes.track-artwork.v2.'.sha1($this->normalize($artist).'|'.$this->normalize($title));

        try {
            $result = Cache::remember($cacheKey, now()->addHours(12), function () use ($artist, $title): string {
                return $this->musicBrainzArtwork($artist, $title)
                    ?? $this->lastFmArtwork($artist, $title)
                    ?? self::NO_ARTWORK;
            });

            return $result === self::NO_ARTWORK ? null : $result;
        } catch (Throwable) {
            // Cache/provider failures must not make an otherwise healthy stream appear offline.
            return null;
        }
    }

    /**
     * Find the recording, then prefer its earliest non-compilation album release group.
     */
    private function musicBrainzArtwork(string $artist, string $title): ?string
    {
        try {
            $response = Http::acceptJson()
                ->withUserAgent('CloudTunes/1.0 (https://github.com/linuxguy421/cloudtunes)')
                ->timeout(5)
                ->get('https://musicbrainz.org/ws/2/recording/', [
                    'query' => 'recording:"'.$this->escapeLucene($title).'" AND artist:"'.$this->escapeLucene($artist).'"',
                    'fmt' => 'json',
                    'limit' => 10,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $recordings = $response->json('recordings', []);
            if (! is_array($recordings)) {
                return null;
            }

            $groups = [];

            foreach ($recordings as $recording) {
                if (! is_array($recording)
                    || $this->normalize((string) ($recording['title'] ?? '')) !== $this->normalize($title)
                    || (int) ($recording['score'] ?? 0) < 90
                    || ! $this->artistCreditMatches($recording['artist-credit'] ?? [], $artist)) {
                    continue;
                }

                foreach (($recording['releases'] ?? []) as $release) {
                    $group = is_array($release) ? ($release['release-group'] ?? null) : null;
                    if (! is_array($group) || empty($group['id']) || ($group['primary-type'] ?? null) !== 'Album') {
                        continue;
                    }

                    $secondaryTypes = array_map('strtolower', $group['secondary-types'] ?? []);
                    if (array_intersect($secondaryTypes, ['compilation', 'live', 'remix', 'soundtrack']) !== []) {
                        continue;
                    }

                    $groups[$group['id']] = [
                        'id' => $group['id'],
                        'date' => $group['first-release-date'] ?? $release['date'] ?? '9999',
                    ];
                }
            }

            if ($groups === []) {
                return null;
            }

            uasort($groups, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

            foreach ($groups as $group) {
                $artwork = $this->coverArtForReleaseGroup($group['id']);
                if ($artwork !== null) {
                    return $artwork;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function coverArtForReleaseGroup(string $releaseGroupId): ?string
    {
        try {
            $response = Http::acceptJson()
                ->withUserAgent('CloudTunes/1.0 (https://github.com/linuxguy421/cloudtunes)')
                ->timeout(5)
                ->get('https://coverartarchive.org/release-group/'.$releaseGroupId.'/');

            if (! $response->successful()) {
                return null;
            }

            $images = $response->json('images', []);
            if (! is_array($images)) {
                return null;
            }

            foreach ($images as $image) {
                if (! is_array($image) || ($image['front'] ?? false) !== true) {
                    continue;
                }

                $url = $image['thumbnails']['500'] ?? $image['thumbnails']['250'] ?? $image['image'] ?? null;
                if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                    // CAA metadata historically contains http URLs; use HTTPS for the browser.
                    return preg_replace('/^http:\/\//i', 'https://', $url);
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function lastFmArtwork(string $artist, string $title): ?string
    {
        $apiKey = (string) config('cloudtunes.lastfm_api_key');
        if ($apiKey === '') {
            return null;
        }

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
    }

    private function artistCreditMatches(mixed $credit, string $artist): bool
    {
        if (! is_array($credit)) {
            return false;
        }

        $names = [];
        foreach ($credit as $entry) {
            if (is_array($entry) && is_array($entry['artist'] ?? null)) {
                $names[] = (string) ($entry['artist']['name'] ?? '');
            }
        }

        return in_array($this->normalize($artist), array_map(fn (string $name): string => $this->normalize($name), $names), true);
    }

    private function escapeLucene(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
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
