# CloudTunes runtime controller

This service is the private, authenticated boundary between the Laravel queue
worker and station runtime orchestration. It is not exposed on a host port.

## Current scope

The API currently validates and durably records ordered station runtime intents
in its own SQLite operation ledger. It does not yet create or stop Ices workers,
change Icecast configuration, or report runtime health. Those operations belong
to the next implementation slice.

The controller intentionally has no Docker socket in this phase. This keeps
the initial API and ordering implementation unprivileged while its request
validation and durable idempotency behavior are tested.

## Authentication

Requests use the CloudTunes HMAC contract:

- X-CloudTunes-Timestamp: Unix timestamp, within 60 seconds of controller time.
- X-CloudTunes-Signature: lowercase hex HMAC-SHA256 over timestamp, method,
  URL path, and SHA-256 of the exact request body.

The secret is supplied as CLOUDTUNES_RUNTIME_SHARED_SECRET. The Laravel
queue worker and this service receive the secret; the public Laravel web
container does not.

## Ordering and idempotency

Every accepted station start/stop intent carries a monotonically increasing
runtime_generation and an operation UUID.

- Older generations are rejected.
- Same generation + same UUID + same exact body is an idempotent retry.
- Same generation assigned to a different operation is a conflict.
- A newer generation supersedes older desired state, even if intermediate
  queue deliveries were missed.

SQLite uses BEGIN IMMEDIATE so concurrent requests cannot both win the
same station generation. The operation ledger and current-intent table are
stored under /data and survive container replacement through a named volume.

## Local development

Set a private shared secret in the environment before starting the stack:

    export CLOUDTUNES_RUNTIME_SHARED_SECRET="$(openssl rand -hex 32)"
    docker compose -f compose.control-plane.dev.yaml up -d --build runtime-controller

The controller listens on port 8090 inside the Compose network. Its health
endpoint is http://runtime-controller:8090/healthz.
