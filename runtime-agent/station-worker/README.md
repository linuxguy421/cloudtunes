# CloudTunes station worker

This image runs exactly one Ices source process for one CloudTunes station. It
is designed to be created and supervised by the future runtime agent; it is
not a standalone Compose service and is not wired into the live deployment yet.

## Properties

- Ices runs in the foreground as the unprivileged `ices` user.
- Station ID, metadata, Icecast destination, source username, and mount are
  supplied at container creation time.
- The mount is validated as `/stations/{STATION_ID}.ogg`.
- The source password is read from a mounted secret file and never accepted
  through an environment variable.
- Media and playlist directories are external mounts at `/media` and
  `/playlist`; no demo music is copied into the image.
- An empty playlist is generated from Ogg files already present in `/media`.
  An empty media directory is a startup error rather than silently streaming
  generated demo audio.
- Every playlist item is inspected with `ffprobe` before Ices starts. The
  current playback profile accepts Ogg containers containing exactly one
  stereo Vorbis audio stream, with a sample rate from 8 kHz through 192 kHz.
  The Ices encoder emits the configured output format (44.1 kHz stereo by
  default).
- Files with an `.ogg` extension but a different container or codec (such as
  Opus-in-Ogg), mono tracks, corrupt files, and unsupported playlists are
  rejected with a specific startup error.
- Upload-time conversion is not implemented yet. Original uploads are not
  modified by the station worker.
- The process receives SIGTERM directly and exits in the foreground.

## Required environment

| Variable | Purpose |
| --- | --- |
| `STATION_ID` | Immutable positive numeric database ID |
| `STATION_NAME` | Stream metadata name |
| `STATION_MOUNT` | Must equal `/stations/{STATION_ID}.ogg` |
| `SOURCE_USERNAME` | Mount-specific Icecast source account |
| `SOURCE_PASSWORD_FILE` | Path to mounted source credential file |
| `ICECAST_HOST` | Internal Icecast hostname |
| `ICECAST_PORT` | Icecast listener port |
| `ICECAST_STATUS_URL` | Icecast status JSON URL, used by health check |

Optional environment: `STATION_GENRE`, `STATION_DESCRIPTION`,
`PLAYBACK_MODE` (`random` or sequential), `AUDIO_BITRATE`,
`AUDIO_SAMPLE_RATE`, and `AUDIO_CHANNELS`.

## Expected mounts

- `/media`: station-owned Ogg media, read-only in the worker.
- `/playlist`: station-owned playlist data, writable so the entrypoint can
  generate a playlist when one has not been supplied.
- `/run/secrets/{station-source-secret}`: read-only station-specific source
  credential.

The runtime agent must create isolated per-station storage and must not mount
another station's media or playlist directory into this container.

## Build and test

From the repository root:

```bash
docker build -t cloudtunes-station-worker:dev runtime-agent/station-worker
python3 -m unittest discover -s runtime-agent/station-worker -p 'test_*.py' -v
```

The image deliberately has no Docker socket, Compose dependency, host port
publication, default station, or bundled media. The runtime agent will own
container lifecycle and health observation.

## Isolated station-worker integration test

The worker can be exercised against a disposable Icecast instance without
starting, stopping, or mounting any of the primary CloudTunes services or
volumes. The test Compose project uses a private internal network, publishes
no host ports, and reads test media and secrets from a directory outside Git.

Build both images first:

```bash
docker build -t cloudtunes-icecast:latest docker-icecast
docker build -t cloudtunes-station-worker:dev runtime-agent/station-worker
```

Prepare a test directory. Place at least one stereo Ogg Vorbis file in its
`media` subdirectory (for example, copy an existing `test-tone.ogg`). Generate
disposable credentials; the source password is shared between the test
Icecast instance and this single worker because the baked-in Icecast
configuration uses its global `source` account:

```bash
TEST_DIR="${CLOUDTUNES_WORKER_TEST_DIR:-/tmp/cloudtunes-worker-test}"
mkdir -p "$TEST_DIR/media" "$TEST_DIR/secrets"

openssl rand -hex 32 > "$TEST_DIR/secrets/source_password"
cp "$TEST_DIR/secrets/source_password" "$TEST_DIR/secrets/icecast_source_password"
openssl rand -hex 32 > "$TEST_DIR/secrets/relay_password"
openssl rand -hex 32 > "$TEST_DIR/secrets/admin_password"
chmod 600 "$TEST_DIR/secrets/"*
```

If your Ogg file is elsewhere, copy it into `$TEST_DIR/media/`. Then validate
and start the isolated test:

```bash
docker compose -p cloudtunes-worker-test \
  -f runtime-agent/station-worker/compose.test.yaml config --quiet

docker compose -p cloudtunes-worker-test \
  -f runtime-agent/station-worker/compose.test.yaml up -d --wait

docker compose -p cloudtunes-worker-test \
  -f runtime-agent/station-worker/compose.test.yaml ps

docker compose -p cloudtunes-worker-test \
  -f runtime-agent/station-worker/compose.test.yaml logs --tail=100 station-worker

docker compose -p cloudtunes-worker-test \
  -f runtime-agent/station-worker/compose.test.yaml exec icecast \
  wget -qO- http://127.0.0.1:8000/status-json.xsl
```

The expected live source mount is `/stations/1.ogg`. The worker health check
reports healthy only while Icecast's status JSON contains that mount. To test
worker recovery without restarting Icecast:

```bash
docker compose -p cloudtunes-worker-test \
  -f runtime-agent/station-worker/compose.test.yaml restart station-worker

docker compose -p cloudtunes-worker-test \
  -f runtime-agent/station-worker/compose.test.yaml ps
```

Stop only the test project when finished:

```bash
docker compose -p cloudtunes-worker-test \
  -f runtime-agent/station-worker/compose.test.yaml down --remove-orphans
```

Do not add production secrets, test credentials, or media files to Git. This
test validates the worker against the current global Icecast source account;
it does not yet validate per-station Icecast authentication or runtime-agent
provisioning. Those are later integration steps.
