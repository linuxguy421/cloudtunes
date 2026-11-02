<?php

namespace Tests\\Feature;

use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Http;
use Tests\\TestCase;

class PlayerPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_is_public_and_uses_the_configured_icecast_stream(): void
    {
        config([
            'cloudtunes.icecast_public_url' => 'https://radio.example.test',
            'cloudtunes.icecast_mount' => '/default0.ogg',
            'cloudtunes.icecast_status_url' => 'http://icecast:8000/status-json.xsl',
        ]);

        Http::fake([
            'http://icecast:8000/status-json.xsl' => Http::response([
                'icestats' => [
                    'source' => [
                        'listenurl' => 'https://radio.example.test/default0.ogg',
                        'artist' => 'Test Artist',
                        'title' => 'Test Track',
                        'genre' => 'Ambient',
                        'ice-bitrate' => '128',
                    ],
                ],
            ]),
        ]);

        $this->get(route('player'))
            ->assertOk()
            ->assertSee('Listen live.')
            ->assertSee('Test Track')
            ->assertSee('Test Artist')
            ->assertSee('https://radio.example.test/default0.ogg')
            ->assertSee('LIVE');
    }

    public function test_player_remains_available_when_icecast_is_unreachable(): void
    {
        Http::fake([
            '*/status-json.xsl' => Http::response([], 503),
        ]);

        $this->get(route('player'))
            ->assertOk()
            ->assertSee('Listen live.')
            ->assertSee('OFFLINE')
            ->assertSee('Waiting for a track');
    }

    public function test_now_playing_status_endpoint_is_public(): void
    {
        Http::fake([
            '*/status-json.xsl' => Http::response([
                'icestats' => [
                    'source' => [
                        'listenurl' => 'http://radio.test/default0.ogg',
                        'artist' => 'Public Artist',
                        'title' => 'Public Track',
                    ],
                ],
            ]),
        ]);

        $this->getJson(route('player.status'))
            ->assertOk()
            ->assertJsonPath('online', true)
            ->assertJsonPath('artist', 'Public Artist')
            ->assertJsonPath('title', 'Public Track');
    }

    public function test_artwork_prefers_the_original_album_and_ignores_compilation_releases(): void
    {
        config(['cloudtunes.lastfm_api_key' => '']);

        Http::fake([
            '*/status-json.xsl' => Http::response([
                'icestats' => [
                    'source' => [
                        'listenurl' => 'http://radio.test/default0.ogg',
                        'artist' => '4 Non Blondes',
                        'title' => "What's Up",
                    ],
                ],
            ]),
            'https://musicbrainz.org/ws/2/recording/*' => Http::response([
                'recordings' => [[
                    'score' => 100,
                    'title' => "What's Up",
                    'artist-credit' => [[
                        'artist' => ['name' => '4 Non Blondes'],
                    ]],
                    'releases' => [
                        [
                            'date' => '1999-01-01',
                            'release-group' => [
                                'id' => 'compilation-release-group',
                                'primary-type' => 'Album',
                                'secondary-types' => ['Compilation'],
                                'first-release-date' => '1999',
                            ],
                        ],
                        [
                            'date' => '1992-01-01',
                            'release-group' => [
                                'id' => 'original-album-release-group',
                                'primary-type' => 'Album',
                                'secondary-types' => [],
                                'first-release-date' => '1992',
                            ],
                        ],
                    ],
                ]],
            ]),
            'https://coverartarchive.org/release-group/original-album-release-group/' => Http::response([
                'images' => [[
                    'front' => true,
                    'thumbnails' => [
                        '500' => 'http://coverartarchive.org/release/original/front-500.jpg',
                    ],
                ]],
            ]),
        ]);

        $this->getJson(route('player.status'))
            ->assertOk()
            ->assertJsonPath('online', true)
            ->assertJsonPath('artwork_url', 'https://coverartarchive.org/release/original/front-500.jpg');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'original-album-release-group'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'compilation-release-group'));
    }

    public function test_last_fm_remains_a_fallback_when_musicbrainz_has_no_match(): void
    {
        config(['cloudtunes.lastfm_api_key' => 'test-lastfm-key']);

        Http::fake([
            '*/status-json.xsl' => Http::response([
                'icestats' => [
                    'source' => [
                        'listenurl' => 'http://radio.test/default0.ogg',
                        'artist' => 'Fallback Artist',
                        'title' => 'Fallback Track',
                    ],
                ],
            ]),
            'https://musicbrainz.org/ws/2/recording/*' => Http::response(['recordings' => []]),
            'https://ws.audioscrobbler.com/2.0/*' => Http::response([
                'track' => [
                    'album' => [
                        'image' => [
                            ['#text' => 'https://images.example.test/large.jpg'],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->getJson(route('player.status'))
            ->assertOk()
            ->assertJsonPath('artwork_url', 'https://images.example.test/large.jpg');
    }
}
