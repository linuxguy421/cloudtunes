# CloudTunes runtime agent

The runtime agent is the separate execution boundary between the runtime
controller and station infrastructure. It is deliberately separate from the
Laravel application and the runtime controller.

## Current phase: authenticated polling and dry-run reconciliation

The agent:

- Polls the controller's authenticated `GET /v1/agent/intents` endpoint.
- Uses the timestamped HMAC-SHA256 request contract shared with Laravel.
- Validates station IDs, immutable mount paths, operation UUIDs, generations,
  station metadata, and desired state before accepting an intent.
- Persists the latest desired intent per station in its own SQLite database.
- Calculates a deterministic action (`start`, `stop`, or `no-op`) from the
  desired state and its locally simulated station state.
- Persists the plan and simulated state transactionally. Repeated delivery of
  the same generation does not execute the plan a second time.
- Rejects stale generations and prevents a superseded intent from being
  planned.
- Runs as an unprivileged user with no Docker socket or host infrastructure
  mounts.

**This phase is simulation only.** A `DRY-RUN` log entry is not evidence that
an Icecast mount or station worker exists, and the simulated state is not
reported to Laravel as observed runtime health. No containers are created or
removed, no Icecast configuration is changed, and no media or credentials are
mounted.

The local intent lifecycle currently uses `pending` and `planned`. The plan
and simulated station state survive agent restarts. Failure, in-progress, and
applied states belong to the future infrastructure executor; dry-run plans are
deliberately not marked as actually applied.

## Configuration

| Variable | Purpose |
| --- | --- |
| `RUNTIME_CONTROLLER_URL` | Internal controller base URL |
| `CLOUDTUNES_RUNTIME_SHARED_SECRET` | Shared HMAC secret |
| `RUNTIME_AGENT_DB` | SQLite path (default `/data/runtime-agent.sqlite3`) |
| `RUNTIME_AGENT_POLL_INTERVAL` | Poll interval in seconds (default 5; minimum 2) |
| `LOG_LEVEL` | Python logging level |

## Local tests and image build

From the repository root:

```sh
python3 -m unittest discover -s runtime-agent/tests -p 'test_*.py' -v
docker build -t cloudtunes-runtime-agent:dev runtime-agent
```

## Run against the isolated control-plane development stack

The optional `compose.runtime-agent.dev.yaml` overlay adds only the polling
agent and its dedicated named volume. It does not modify the root production
Compose file or attach a Docker socket.

Use the same shared secret that was supplied when the development runtime
controller and Laravel queue worker were started. Do not generate a new secret
for the agent alone.

```sh
docker compose -f compose.control-plane.dev.yaml \
  -f compose.runtime-agent.dev.yaml up -d --build runtime-agent
docker compose -f compose.control-plane.dev.yaml \
  -f compose.runtime-agent.dev.yaml logs --tail=80 runtime-agent
```

A running stack with no station intents will report zero pending plans. When a
station intent is accepted by the controller, the agent should log a
`DRY-RUN` action and resulting simulated state. This verifies the planning
logic only; it does not provision or stop a real station.

## Next phase

The next phase will replace the simulated state with observed infrastructure
state behind a narrowly scoped Docker API boundary. It will add safe
in-progress/applied/failed transitions, station-specific credentials and
storage, Icecast mount reconciliation, and observed health reporting. The
infrastructure executor will be integration-tested against the disposable
multi-station POC before it is connected to the live stack.
