# Docker Compose deployment

This is the initial Compose deployment path. The existing Kubernetes manifests remain available and are not changed by this setup.

## Requirements

- Docker Engine
- Docker Compose v2 (docker compose)
- Enough disk space to build the existing images and store your music

## First start

1. Put at least one supported audio file in docker-ices/data/station_0/ before building. The current Ices image processes this directory during image build; the directory is ignored by Git so your music is not committed.
2. Copy the sample environment file and adjust it for your host:

    cp .env.example .env

3. If listeners will connect from another computer, set ICECAST_PUBLIC_URL in .env to a URL reachable from those clients, such as http://192.0.2.10:8000. Replace the example address with your Docker host's actual LAN/DNS address.
4. Validate and start the stack:

    docker compose config
    docker compose up --build -d
    docker compose ps

The first build may take a while because the existing frontend image compiles Nginx and installs its PHP dependencies.

## Endpoints

- Web interface: http://localhost:8080 (or your configured WEB_PORT)
- Icecast status: http://localhost:8000/status-json.xsl (or your configured ICECAST_PORT)
- Audio stream: http://localhost:8000/default0.ogg

Use the Docker host's address instead of localhost when connecting from another device. The browser connects directly to Icecast, so ICECAST_PUBLIC_URL must be reachable from the listener's browser.

Memcached and the Ices control port are not published on the host. Services communicate over the private Compose network using service DNS.

## Data and configuration persistence

Compose creates named volumes for Icecast configuration, Ices configuration, and the generated playlist. Music is kept in the named music-data volume. The first time that volume is created, Docker initializes it from the built image's /data directory, which is why you must add music before the first startup/build.

Named volumes survive container recreation and image rebuilds. Do not use docker compose down -v unless you deliberately want to delete these volumes and their contents.

To add more music after the first startup, copy it into the running Ices container's persistent music directory and regenerate the playlist:

    docker compose cp /path/to/music/. ices:/data/station_0/
    docker compose exec ices /etc/ices/init_songs.sh
    docker compose exec ices /etc/ices/process_songs.sh
    docker compose exec ices /etc/ices/update_songs.sh
    docker compose restart ices

The current song-processing scripts are inherited from the original image and have limited error handling. This initial Compose phase does not redesign that workflow.

## Common operations

    # View service status
    docker compose ps

    # Follow logs
    docker compose logs -f

    # Rebuild and recreate services after source changes
    docker compose up --build -d

    # Stop services but keep data
    docker compose down

Do not use docker compose down -v for routine updates or shutdowns; it removes the named volumes.

## Scope and known limitations

This is the first deployment foundation, not the final management workflow. The existing Dockerfiles retain their current base images and build steps. The Icecast/Ices configuration still contains the existing source password, and the external Last.fm integration retains its existing configuration. Those concerns are planned for the configuration/reliability phase. The frontend now accepts Compose service URLs through environment variables while retaining Kubernetes service-name defaults when those variables are not set.
