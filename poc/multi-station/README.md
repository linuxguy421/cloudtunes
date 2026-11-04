# CloudTunes multi-station Ices proof of concept

This is an **isolated test stack**. It does not modify the primary `compose.yaml`,
the existing Icecast/Ices services, Laravel, or any existing named volumes.

It starts:
- One disposable Icecast 2.4.x server with mount-specific source accounts.
- Independent Ices 2.0.3 workers.
- Separate bind-mounted media and playlist directories for each station.

The Icecast listener is bound to `127.0.0.1:18400` by default. It does not use
the production Icecast port or production source credentials.

## Start

From the repository root, choose a free local port. For example, if 18400 is
already occupied:

```bash
export POC_ICECAST_PORT=18401
sh ./poc/multi-station/up.sh
```

Keep that export in the same shell for subsequent Compose commands. Compose
does not remember an environment variable that was supplied only to a previous
command.

The script creates three random local source secrets if they do not already exist,
then builds and starts the POC. Secrets are ignored by Git and have mode 0600.

If a station's media directory is empty, its worker generates a short Ogg Vorbis
test tone. Each station uses a different frequency. To use your own test media,
stop the POC, put Ogg Vorbis files in the corresponding
`poc/multi-station/data/<station>/` directory, and remove that station's
generated `playlists/<station>/playlist.txt` file before starting again. Only
station-local files are mounted into each worker.

## Verify

```bash
docker compose -f poc/multi-station/compose.yaml ps
docker compose -f poc/multi-station/compose.yaml logs -f
```

Both default workers should become healthy. Open these URLs in separate browser
tabs or audio players (replace 18401 if you selected another port):

- Alpha: http://127.0.0.1:18401/station-alpha.ogg
- Bravo: http://127.0.0.1:18401/station-bravo.ogg

Icecast status JSON is available at:

http://127.0.0.1:18401/status-json.xsl

To prove worker independence, stop Alpha:

```bash
docker compose -f poc/multi-station/compose.yaml stop station-alpha
```

Bravo should remain healthy and continue streaming. Restart Alpha with:

```bash
docker compose -f poc/multi-station/compose.yaml start station-alpha
```

## Dynamic mount reload test (Icecast 2.4.x)

**Verified with Icecast 2.4.4:** a new mount-specific source account can be added
to the running disposable Icecast process and activated with `SIGHUP`, without
restarting Icecast or changing the stream start times of existing stations.

The optional Gamma worker is in the `dynamic-test` Compose profile, so normal
POC startup does not start it.

For an already-running POC, first ensure the port variable is exported in the
current shell:

```bash
export POC_ICECAST_PORT=18401
```

Create Gamma's credential locally if it does not exist:

```bash
if [ ! -s poc/multi-station/secrets/gamma_source_password ]; then
  (umask 077; od -An -N32 -tx1 /dev/urandom | tr -d ' \\n' > poc/multi-station/secrets/gamma_source_password)
fi
chmod 600 poc/multi-station/secrets/gamma_source_password
```

Capture the Icecast container start time and existing stream start timestamps
from `http://127.0.0.1:18401/status-json.xsl` (adjust the port if necessary).
Then add Gamma's mount to the running POC server and signal Icecast to reread
its configuration:

```bash
sh ./poc/multi-station/add-gamma-mount.sh
```

The helper copies the Gamma secret into the disposable Icecast container,
backs up its generated config in `/run`, adds only the Gamma mount entry,
atomically replaces the config, and sends `SIGHUP` to the Icecast process. It
does not recreate or restart the Icecast container. It does not touch the
primary CloudTunes Icecast service.

Build and start only the opt-in Gamma worker. The `--no-deps` flag is
important: it prevents Compose from trying to recreate Icecast as a dependency.
Keep `POC_ICECAST_PORT` exported so Compose retains the selected host port.

```bash
docker compose -f poc/multi-station/compose.yaml build station-gamma
docker compose --profile dynamic-test -f poc/multi-station/compose.yaml up -d --no-deps station-gamma
docker compose --profile dynamic-test -f poc/multi-station/compose.yaml ps
docker compose --profile dynamic-test -f poc/multi-station/compose.yaml logs --tail=50 station-gamma icecast
```

Success criteria:

- Gamma becomes healthy and `/station-gamma.ogg` appears in Icecast status JSON.
- Alpha and Bravo remain active throughout the reload.
- Their `stream_start_iso8601` values do not change.
- The Icecast container's `StartedAt` value and restart count do not change.
- Gamma authenticates with its own `gamma-source` credential.

The test has also verified independent worker recovery: killing Gamma's Ices
process caused only the Gamma container to restart; Alpha and Bravo kept their
original stream timestamps, and Icecast had no restart.

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

This POC proves that independent Ices workers, station-specific credentials,
mount points, dynamic mount registration, and worker recovery work together
against a disposable Icecast 2.4.4 server.

It does not yet implement Laravel-driven provisioning or validate changes
against the existing primary Icecast service. The production integration must
preserve the same isolation and must be tested separately before any existing
Icecast/Ices configuration or runtime is changed.
