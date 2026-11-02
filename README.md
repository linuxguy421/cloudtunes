# CloudTunes

CloudTunes is a self-hosted internet radio platform. The primary Docker Compose deployment runs Icecast for streaming, Ices for playlist playback, and a Laravel control plane for the public player and station-management UI.

## Quick start

1. Place supported audio files in `docker-ices/data/station_0/` before first startup.
2. Configure the environment and credentials:

   ```sh
   cp .env.example .env
   # Set CONTROL_PLANE_APP_KEY and CONTROL_PLANE_DB_PASSWORD in .env.
   bash scripts/init-secrets.sh
   ```

3. Start the stack:

   ```sh
   docker compose config --quiet
   docker compose up --build -d
   ```

4. Open the public player at `http://localhost:8080/player`.

For remote listeners, set `ICECAST_PUBLIC_URL` in `.env` to a hostname or IP reachable by their browsers. The browser streams directly from Icecast; Laravel supplies now-playing metadata and artwork.

See [the Compose deployment guide](docs/compose-deployment.md) for configuration, persistent data, database migration, and operational details.

## Services

- **Laravel control plane** — public player, authentication, dashboard, and station management.
- **Icecast** — serves the audio stream and status endpoint.
- **Ices** — plays the configured station playlist.

The legacy PHP/Nginx player source remains temporarily in `radio-frontend/` for rollback/reference purposes but is not part of the primary Compose runtime.

## Data safety

Compose uses named volumes for Icecast configuration, music, Ices playlists/configuration, PostgreSQL, and Laravel storage. Routine updates and shutdowns preserve these volumes. Do not run `docker compose down -v` unless you intentionally want to delete all persistent data.
