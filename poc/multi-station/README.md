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

The script creates three random local source secrets if they do not already exist,
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

## Dynamic mount reload test (Icecast 2.4.x)

The optional Gamma worker tests whether a new mount-specific source account can
be added to the running disposable Icecast process without restarting Alpha or
Bravo. Gamma is in the `dynamic-test` Compose profile, so the normal POC startup
does not start it.

For an already-running POC, first pull the feature-branch changes. Create the
Gamma credential locally if it does not exist:

```bash
if [ ! -s poc/multi-station/secrets/gamma_source_password ]; then
  (umask 077; od -An -N32 -tx1 /dev/urandom | tr -d ' \\n' > poc/multi-station/secrets/gamma_source_password)
fi
chmod 600 poc/multi-station/secrets/gamma_source_password
```

Capture the current Icecast container start time and Alpha/Bravo stream start
timestamps from `http://127.0.0.1:18401/status-json.xsl` (adjust the port if
you started the POC on a different one). Then add Gamma's mount to the running
POC server and signal the Icecast process to reread its configuration:

```bash
sh ./poc/multi-station/add-gamma-mount.sh
```

The helper copies the Gamma secret into the disposable Icecast container,
backs up its generated config in `/run`, adds only the Gamma mount entry,
atomically replaces the config, and sends `SIGHUP` to the Icecast process. It
does not recreate or restart the Icecast container. It does not touch the
primary CloudTunes Icecast service.

Build and start only the opt-in Gamma worker:

```bash
docker compose -f poc/multi-station/compose.yaml build station-gamma
docker compose --profile dynamic-test -f poc/multi-station/compose.yaml up -d station-gamma
docker compose -f poc/multi-station/compose.yaml ps
docker compose -f poc/multi-station/compose.yaml logs --tail=50 station-gamma icecast
```

Success criteria:

- Gamma becomes healthy and `/station-gamma.ogg` appears in Icecast status JSON.
- Alpha and Bravo remain active throughout the reload.
- Their `stream_start_iso8601` values do not change.
- The Icecast container's `StartedAt` value does not change.
- Gamma authenticates with its own `gamma-source` credential.

If Icecast logs a configuration reload error, do not start Gamma. Preserve the
logs and stop here; the existing Alpha/Bravo streams should remain on the
pre-reload in-memory configuration. This test is specifically intended to
establish the exact Icecast 2.4.x behavior before implementing Laravel
provisioning.

To stop only Gamma after the test:

```bash
docker compose --profile dynamic-test -f poc/multi-station/compose.yaml stop station-gamma
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
