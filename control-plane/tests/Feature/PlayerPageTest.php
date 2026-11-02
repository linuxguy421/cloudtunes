<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

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
}
