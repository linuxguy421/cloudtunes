@extends('layouts.app')

@section('title', 'Listen')

@section('content')
<main class="container player-page">
    <p class="muted" style="margin:0 0 .5rem">CLOUDTUNES RADIO</p>
    <h1>Listen live.</h1>
    <p class="lede">The music keeps playing. Stay a while.</p>

    <section class="player-panel panel" aria-label="Live radio player">
        <div class="player-art" id="player-art" @if ($nowPlaying['artwork_url']) style="background-image:url('{{ $nowPlaying['artwork_url'] }}')" @endif>
            <div class="player-art-overlay">
                <span class="badge {{ $nowPlaying['online'] ? 'good' : 'warn' }}" id="stream-state">
                    {{ $nowPlaying['online'] ? 'LIVE' : 'OFFLINE' }}
                </span>
                <div>
                    <p class="player-eyebrow">NOW PLAYING</p>
                    <h2 id="track-title">{{ $nowPlaying['title'] ?? 'Waiting for a track' }}</h2>
                    <p id="track-artist">{{ $nowPlaying['artist'] ?? 'CloudTunes Radio' }}</p>
                </div>
            </div>
        </div>

        <div class="player-controls">
            <audio id="cloudtunes-audio" controls preload="none">
                <source src="{{ $streamUrl }}" type="audio/ogg">
                Your browser does not support HTML audio playback.
            </audio>
            <div class="track-details" id="track-details">
                <span id="track-genre">{{ $nowPlaying['genre'] ?? 'Live radio' }}</span>
                <span id="track-bitrate">{{ $nowPlaying['bitrate'] ? $nowPlaying['bitrate'].' kbps' : '' }}</span>
            </div>
            <p class="muted player-note">Playback is delivered directly by the CloudTunes Icecast server.</p>
        </div>
    </section>
</main>

<style>
    .player-page { max-width:900px; }
    .player-panel { overflow:hidden; padding:0; }
    .player-art { min-height:360px; aspect-ratio:16/9; display:flex; align-items:flex-end; background:radial-gradient(circle at 25% 20%,#365a83 0,transparent 42%),linear-gradient(135deg,#1a2944,#080d18); background-size:cover; background-position:center; }
    .player-art-overlay { width:100%; display:grid; gap:1rem; padding:clamp(1.25rem,4vw,2.5rem); background:linear-gradient(transparent,rgba(3,8,18,.92)); }
    .player-art-overlay h2 { margin:.3rem 0; font-size:clamp(1.5rem,4vw,2.5rem); }
    .player-art-overlay p { margin:.15rem 0; }
    .player-eyebrow { font-size:.75rem; letter-spacing:.16em; font-weight:750; }
    .player-controls { padding:1.25rem clamp(1.25rem,4vw,2rem) 1.5rem; }
    .player-controls audio { width:100%; }
    .track-details { display:flex; justify-content:space-between; gap:1rem; margin-top:.75rem; color:var(--muted); font-size:.9rem; }
    .player-note { margin:.75rem 0 0; font-size:.85rem; }
    @media(max-width:600px) { .player-art { min-height:280px; aspect-ratio:4/5; } }
</style>

<script>
    (() => {
        const endpoint = @json(route('player.status'));
        const art = document.getElementById('player-art');
        const state = document.getElementById('stream-state');
        const title = document.getElementById('track-title');
        const artist = document.getElementById('track-artist');
        const genre = document.getElementById('track-genre');
        const bitrate = document.getElementById('track-bitrate');

        async function refreshNowPlaying() {
            try {
                const response = await fetch(endpoint, { headers: { 'Accept': 'application/json' } });
                if (!response.ok) return;
                const track = await response.json();

                state.textContent = track.online ? 'LIVE' : 'OFFLINE';
                state.className = 'badge ' + (track.online ? 'good' : 'warn');
                title.textContent = track.title || 'Waiting for a track';
                artist.textContent = track.artist || 'CloudTunes Radio';
                genre.textContent = track.genre || 'Live radio';
                bitrate.textContent = track.bitrate ? track.bitrate + ' kbps' : '';

                if (track.artwork_url) {
                    art.style.backgroundImage = 'url("' + track.artwork_url.replace(/["\\\\]/g, '') + '")';
                } else {
                    art.style.backgroundImage = '';
                }
            } catch (_) {
                // A failed metadata refresh must not interrupt audio playback.
            }
        }

        window.setInterval(refreshNowPlaying, 15000);
    })();
</script>
@endsection
