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

## Build

From the repository root:

```bash
docker build -t cloudtunes-station-worker:dev runtime-agent/station-worker
```

The image deliberately has no Docker socket, Compose dependency, host port
publication, default station, or bundled media. The runtime agent will own
container lifecycle and health observation.
