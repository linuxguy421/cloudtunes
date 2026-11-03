# CloudTunes multi-station Ices proof of concept

This is an **isolated test stack**. It does not modify the primary `compose.yaml`,
the existing Icecast/Ices services, Laravel, or any existing named volumes.

It starts:
- One disposable Icecast 2.4.x server with two mount-specific source accounts.
- Two independent Ices 2.0.3 workers.
- Separate bind-mounted media and playlist directories for Alpha and Bravo.

The Icecast listener is bound to `127.0.0.1:18400` by default. It does not use
the production Icecast port or production source credentials.

## Start

From the repository root:

```bash
sh ./poc/multi-station/up.sh
```

The script creates two random local source secrets if they do not already exist,
then builds and starts the POC. Secrets are ignored by Git and have mode 0600.

If a station's media directory is empty, its worker generates a short Ogg Vorbis
test tone. Alpha and Bravo use different frequencies. To use your own test media,
stop the POC, put Ogg Vorbis files in `poc/multi-station/data/alpha/` and
`poc/multi-station/data/bravo/`, and remove the generated
`playlists/<station>/playlist.txt` files before starting again. Only
station-local files are mounted into each worker.

## Verify

```bash
docker compose -f poc/multi-station/compose.yaml ps
docker compose -f poc/multi-station/compose.yaml logs -f
```

Both workers should become healthy. Open these URLs in separate browser tabs or
audio players:

- Alpha: http://127.0.0.1:18400/station-alpha.ogg
- Bravo: http://127.0.0.1:18400/station-bravo.ogg

Icecast status JSON is available at:

http://127.0.0.1:18400/status-json.xsl

To prove worker independence, stop Alpha:

```bash
docker compose -f poc/multi-station/compose.yaml stop station-alpha
```

Bravo should remain healthy and continue streaming. Restart Alpha with:

```bash
docker compose -f poc/multi-station/compose.yaml start station-alpha
```

## Stop and cleanup

```bash
sh ./poc/multi-station/down.sh
```

This stops and removes only the POC containers and network. It does **not** remove
the local secrets, media, playlists, or any Docker volumes. Do not add `-v` to
the down command.

## Important scope

This POC deliberately uses its own Icecast instance. It proves that independent
Ices workers, station credentials, mount points, and worker lifecycle work
together without changing the live Icecast configuration. It does not yet prove
safe dynamic mount provisioning on the existing Icecast 2.4.4 server. That is a
separate integration step after this test passes.
