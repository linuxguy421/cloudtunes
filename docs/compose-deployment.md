# Docker Compose deployment

The primary Compose deployment runs the Laravel control plane/player alongside the existing Icecast and Ices services. The old `radio-frontend` and Memcached services are no longer part of the Compose runtime.

## Requirements

- Docker Engine
- Docker Compose v2 (`docker compose`)
- Enough disk space to build the Icecast, Ices, and Laravel images and store your music

## First start

1. Put at least one supported audio file in `docker-ices/data/station_0/` before the first creation of the `music-data` volume.
2. Copy the sample environment file:

   ```sh
   cp .env.example .env
   ```

3. Generate the Laravel application key and a strong database password:

   ```sh
   php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
   ```

   Put the generated value in `CONTROL_PLANE_APP_KEY` in `.env`. Generate a separate long random value for `CONTROL_PLANE_DB_PASSWORD`. Keep both values stable and back them up securely; changing the application key invalidates existing encrypted sessions.
4. Generate the Icecast/Ices credential files:

   ```sh
   bash scripts/init-secrets.sh
   ```

5. Set `ICECAST_PUBLIC_URL` to a URL reachable from listener browsers. For remote listeners, do not use `localhost`; use the server's DNS name or public IP. If you use HTTPS for the Laravel site, configure a TLS reverse proxy in front of the control-plane port and set `SESSION_SECURE_COOKIE=true`.
6. Validate and start:

   ```sh
   docker compose config --quiet
   docker compose up --build -d
   docker compose ps
   ```

   The migration job runs before the Laravel web and worker containers start.

## Endpoints

- Laravel player and control plane: `http://localhost:8080/player` (or your configured `CONTROL_PLANE_PORT`)
- Laravel health endpoint: `http://localhost:8080/up`
- Icecast status: `http://localhost:8000/status-json.xsl` (or your configured `ICECAST_PORT`)
- Audio stream: `http://localhost:8000/default0.ogg`

The Laravel container reads Icecast status over the private Compose network at `http://icecast:8000/status-json.xsl`. Listener browsers connect directly to Icecast using `ICECAST_PUBLIC_URL`; Laravel does not proxy audio.

## Database and persistent data

The control plane uses a dedicated PostgreSQL 16 service and the named `control-plane-db-data` volume. Laravel sessions, cache, and queued jobs are stored in that database. The `control-plane-storage` volume persists Laravel storage.

The first start creates an empty control-plane database. If you want to retain users and station records from the isolated development stack, migrate them before running the primary migration job:

```sh
# 1. Back up the isolated development database (source).
docker compose -f compose.control-plane.dev.yaml exec -T control-plane-db \
  pg_dump -U cloudtunes -d cloudtunes_control_plane -Fc \
  > cloudtunes-control-plane-dev.dump

# 2. Start only the new primary database; do not start the app/migration job yet.
docker compose up -d control-plane-db

# 3. Restore into the new, empty primary database (target).
docker compose exec -T control-plane-db \
  pg_restore --no-owner --no-privileges -U cloudtunes \
  -d cloudtunes_control_plane < cloudtunes-control-plane-dev.dump

# 4. Start the full stack. The migration job will see the restored migration history.
docker compose up --build -d
```

Keep the dump until the primary deployment is verified and backed up. If the primary database has already had migrations or application data written to it, stop and take a fresh backup before attempting any restore; do not blindly restore over it. Do not point two PostgreSQL containers at the same data directory, and do not delete either database volume during this transition.

Icecast configuration, music, Ices playlists, and Ices configuration continue to use their existing named volumes. Named volumes survive container recreation and image rebuilds. Never use `docker compose down -v` for routine updates or shutdowns; it deletes persistent data.

## Common operations

```sh
# View service status
docker compose ps

# Follow application and radio logs
docker compose logs -f control-plane control-plane-worker icecast ices

# Apply application updates and pending migrations
docker compose up --build -d

# Stop services while preserving all named volumes
docker compose down
```

## Legacy frontend retirement

The legacy `radio-frontend` service and its Memcached dependency have been removed from the primary Compose runtime. Their source and Kubernetes manifests remain in the repository temporarily as rollback/reference material; they are not started by `docker compose up`.

Do not remove the legacy source tree until the Laravel player has been validated in the primary deployment and the rollback window has passed.

## Operational notes

- The Laravel player is public; dashboard and station management require authentication.
- Public registration is disabled by default. Use the documented bootstrap procedure to create/promote the initial administrator.
- The Laravel application currently uses PHP's built-in HTTP server in this Compose setup. Put it behind a TLS-terminating reverse proxy for any internet-facing deployment; a production PHP-FPM/web-server arrangement is a later hardening step.
- This Compose transition does not provision or start per-user Icecast stations. The player still targets the configured mount, defaulting to `/default0.ogg`.
- Do not combine this primary Compose file with `compose.control-plane.dev.yaml`; that file is an isolated development/test stack with a separate database.
