# Laravel public player

The Laravel control plane includes a public player at `/player`. Listening does not require an account; station management remains authenticated. The legacy `radio-frontend` container and its files are retained as a fallback while this integration is tested.

## Development configuration

The isolated control-plane Compose stack can read an Icecast endpoint published on the Docker host. It does not join the live radio Compose network and does not modify Icecast or Ices.

Configure these values in `compose.control-plane.dev.yaml` or its environment:

- `ICECAST_STATUS_URL`: server-side Icecast status endpoint.
- `ICECAST_PUBLIC_URL`: public URL used by the listener's browser.
- `ICECAST_MOUNT`: mount to play and inspect (default `/default0.ogg`).
- `LASTFM_API_KEY`: optional fallback artwork provider key.

The public stream URL is sent to the listener's browser. The status URL is fetched server-side by Laravel. Do not set the public URL to an internal Docker service name.

## Artwork lookup

Artwork resolution follows this order:

1. MusicBrainz recording search using the Icecast artist and title.
2. Exact normalized title and artist-credit matching.
3. Prefer the earliest album release group and exclude compilation, live, remix, and soundtrack release groups.
4. Retrieve the front-cover thumbnail from the Cover Art Archive.
5. Fall back to Last.fm `track.getInfo` if MusicBrainz/Cover Art Archive cannot provide a suitable image.

Positive and negative artwork lookups are cached for 12 hours. MusicBrainz requests include a descriptive User-Agent and are made only on cache misses. If metadata providers or the cache are unavailable, the live player and audio stream remain available.

## Behavior and boundaries

- The audio element streams directly from Icecast; Laravel does not proxy or buffer audio.
- Laravel polls Icecast status for the configured mount and exposes metadata at `/player/status`.
- Metadata refreshes every 15 seconds. A metadata or artwork failure does not stop audio playback.
- The player is public. The dashboard and station management routes continue to require authentication.
- This first integration targets the existing default mount. Per-station player selection and runtime provisioning are separate work.
- The legacy player container is intentionally untouched and should remain available as a rollback option.

## Validation

Run the Laravel feature suite in the isolated development stack and verify:

1. The player loads while Icecast is online and offline.
2. Browser playback reaches the configured public stream URL.
3. Track metadata changes after the next Icecast source update.
4. MusicBrainz/Cover Art Archive returns original-album artwork where available.
5. Last.fm fallback works when MusicBrainz has no suitable cover.
6. The legacy player remains functional as a rollback option.
