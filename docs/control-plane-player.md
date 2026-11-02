# Laravel public player

The Laravel control plane now includes a public player at `/player`. Listening does not require an account; station management remains authenticated. The legacy `radio-frontend` container and its files are retained as a fallback while this integration is tested.

## Development configuration

The isolated control-plane Compose stack can read an Icecast endpoint published on the Docker host. It does not join the live radio Compose network and does not modify Icecast or Ices.

Defaults in `compose.control-plane.dev.yaml`:

- `ICECAST_STATUS_URL=http://host.docker.internal:8000/status-json.xsl`
- `ICECAST_PUBLIC_URL=http://localhost:8000`
- `ICECAST_MOUNT=/default0.ogg`
- `LASTFM_API_KEY=` (optional)

Override these values in the shell or an appropriate local Compose environment file when the Icecast host port or mount differs. On Linux, Compose adds the `host.docker.internal:host-gateway` mapping to the control-plane container.

The public stream URL is sent to the listener's browser. The status URL is fetched server-side by Laravel. Do not set the public URL to an internal Docker service name.

Example:

```bash
ICECAST_PUBLIC_URL=https://radio.example.com \
ICECAST_STATUS_URL=http://host.docker.internal:8000/status-json.xsl \
ICECAST_MOUNT=/default0.ogg \
LASTFM_API_KEY=your_lastfm_api_key \
docker compose -f compose.control-plane.dev.yaml up --build -d
```

Then open `http://localhost:18080/player`.

## Behavior and boundaries

- The audio element streams directly from Icecast; Laravel does not proxy or buffer audio.
- Laravel polls Icecast status for the configured mount and exposes metadata at `/player/status`.
- Track artwork lookup is optional and uses Last.fm's `track.getInfo` endpoint. Without a key or matching artwork, the player uses its built-in background treatment.
- Metadata refreshes every 15 seconds. A metadata or artwork failure does not stop audio playback.
- The player is public. The dashboard and station management routes continue to require authentication.
- This first integration targets the existing default mount. Per-station player selection and runtime provisioning are separate work.
- The legacy player container is intentionally untouched and should remain available until the Laravel player has been validated against the actual stream.

## Validation still required

Run the Laravel feature suite in the isolated development stack and verify:

1. The player loads while Icecast is online and offline.
2. Browser playback reaches the configured public stream URL.
3. Track metadata changes after the next Icecast source update.
4. Artwork lookup works with a configured Last.fm API key and degrades gracefully without one.
5. The legacy player remains functional as a rollback option.
