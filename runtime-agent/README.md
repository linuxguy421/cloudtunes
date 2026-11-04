# CloudTunes runtime agent

The runtime agent is the separate execution boundary between the runtime
controller and station infrastructure. It is deliberately separate from the
Laravel application and the runtime controller.

## Current phase: authenticated intent polling

The initial agent image:

- Polls the controller's authenticated `GET /v1/agent/intents` endpoint.
- Uses the same timestamped HMAC-SHA256 request contract as Laravel.
- Validates station IDs, immutable mount paths, operation UUIDs, generations,
  station metadata, and desired state before accepting an intent.
- Persists the latest desired intent per station in its own SQLite database.
- Keeps intents pending until a future executor acknowledges successful
  reconciliation. A newer generation replaces an older pending generation.
- Runs as an unprivileged user and has no Docker socket or host mounts.

**This phase does not create containers, modify Icecast configuration, mount
media, or create source credentials.** The log output is a reconciliation
inbox, not evidence that a station is running.

## Configuration

| Variable | Purpose |
| --- | --- |
| `RUNTIME_CONTROLLER_URL` | Internal controller base URL |
| `CLOUDTUNES_RUNTIME_SHARED_SECRET` | Shared HMAC secret |
| `RUNTIME_AGENT_DB` | SQLite path (default `/data/runtime-agent.sqlite3`) |
| `RUNTIME_AGENT_POLL_INTERVAL` | Poll interval in seconds (default 5; minimum 2) |
| `LOG_LEVEL` | Python logging level |

## Local tests

From the repository root:

```sh
python3 -m unittest discover -s runtime-agent/tests -p 'test_*.py' -v
docker build -t cloudtunes-runtime-agent:dev runtime-agent
```

The next phase will add an executor behind a narrow Docker API boundary,
station-specific credentials and storage, Icecast mount reconciliation, and
observed health reporting. The executor will be integration-tested against
the disposable multi-station POC before it is connected to the live stack.
