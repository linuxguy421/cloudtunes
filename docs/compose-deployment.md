# Docker Compose deployment

This is the initial Compose deployment path. The existing Kubernetes manifests remain available and are not changed by this setup.

## Requirements

- Docker Engine
- Docker Compose v2 (docker compose)
- Enough disk space to build the existing images and store your music

## First start

1. Put at least one `.ogg` audio file in `docker-ices/data/station_0/`. This directory is ignored by Git. The Ices image no longer copies music into the image or mutates media during the build; music lives in the persistent `music-data` volume.
2. Copy the sample environment file and adjust it for your host:

    cp .env.example .env

3. Generate local service credentials before starting Compose:

    bash scripts/init-secrets.sh

   This creates four files under `secrets/` with restrictive permissions. The directory is excluded from Git. Back up these files securely; do not paste or commit their contents. The Ices and Icecast source password files are kept identical by the initializer.
4. If listeners will connect from another computer, set ICECAST_PUBLIC_URL in .env to a URL reachable from those clients, such as http://192.0.2.10:8000. Replace the example address with your Docker host's actual LAN/DNS address.
5. Build the Ices image and seed the persistent music volume before starting the stack:

    docker compose build ices
    docker compose run --rm --no-deps -v "$PWD/docker-ices/data:/import:ro" --entrypoint /bin/sh ices -c 'mkdir -p /data/station_0 && find /import/station_0 -type f -name "*.ogg" -exec cp -n -- {} /data/station_0/ +'

   This copies `.ogg` files into the named volume without overwriting files already present. It does not transcode or alter source files. If the source directory contains no `.ogg` files, add supported files before continuing.

6. Validate and start the stack:

    docker compose config --quiet
    docker compose up --build -d
    docker compose ps

The first build may take a while because the frontend image compiles Nginx and installs its PHP dependencies. Ices generates its playlist at startup from the mounted music volume. If no `.ogg` files are found, startup fails with a clear error instead of silently using a stale playlist.

## Endpoints

- Web interface: http://localhost:8080 (or your configured WEB_PORT)
- Icecast status: http://localhost:8000/status-json.xsl (or your configured ICECAST_PORT)
- Audio stream: http://localhost:8000/default0.ogg

Use the Docker host's address instead of localhost when connecting from another device. The browser connects directly to Icecast, so ICECAST_PUBLIC_URL must be reachable from the listener's browser.

Memcached and the Ices control port are not published on the host. Services communicate over the private Compose network using service DNS.

## Data and configuration persistence

Compose creates named volumes for Icecast configuration, Ices configuration, the generated playlist, and music. Music is stored in the named `music-data` volume. Image builds do not contain music and do not run conversion, normalization, metadata rewriting, renaming, or deletion scripts against media.

Named volumes survive container recreation and image rebuilds. Do not use docker compose down -v unless you deliberately want to delete these volumes and their contents.

To add `.ogg` files after the first startup, copy them into the persistent music directory and restart Ices:

    docker compose cp /path/to/music/. ices:/data/station_0/
    docker compose restart ices

The entrypoint regenerates the playlist on every Ices container start using only `.ogg` files under `/data/station_0`. Playlist generation is deterministic, writes atomically, and leaves the previous playlist untouched if no valid files are found. It does not modify audio files.

The legacy `init_songs.sh` and `process_songs.sh` scripts remain in the image but are no longer run automatically. They can rename, convert, normalize, rewrite metadata, or delete files; do not run them against your only copy of any music. A separate reviewed change should define a safe media-import/transcoding workflow.

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

This is the first deployment foundation, not the final management workflow. This change removes media mutation from image builds and makes playlist generation a runtime operation. It does not redesign the legacy conversion/transcoding workflow, change Ices process supervision, or migrate existing named volumes. Existing volumes are preserved, but the next Ices container start rebuilds `default0.txt` from `.ogg` files present in `music-data`. Back up persistent data before deployment changes and verify the stream after recreating Ices.

The Compose configuration expects file-backed local secrets for the Icecast source, relay, and admin credentials and the Ices source credential. Run the initializer above before starting. These local files are not encrypted at rest; protect the host and backups. Existing named configuration volumes can differ from repository files, so the secret-rendering change must be validated against a backup in an isolated deployment before a live rollout. The external Last.fm integration retains its existing configuration. The frontend now accepts Compose service URLs through environment variables while retaining Kubernetes service-name defaults when those variables are not set.


## Audio player dependency

The frontend uses the Video.js 10 audio-neutral preset from a version-pinned jsDelivr CDN bundle. This avoids installing browser-player packages during the PHP image build and removes the deprecated Plyr Yarn dependency that failed under the image's Node.js 16 runtime. The browser must be able to reach jsDelivr to load the player UI; the underlying Icecast audio URL must also be reachable from the listener's browser.
