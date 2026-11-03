# Station runtime controller contract

## Boundary

Laravel is the control plane and PostgreSQL remains the source of truth for
station identity and desired state. The Laravel queue worker sends a typed,
authenticated reconciliation request to a separate trusted runtime controller.
The public Laravel web container must not receive the Docker socket or arbitrary
Docker API access.

The controller is responsible for provisioning and reconciling Ices workers
and their Icecast mounts. It must not accept shell commands, arbitrary image
names, container IDs, host paths, mount names, or credentials from the caller.

## Request

`POST /v1/stations/{station_id}/reconcile`

Headers:

- `X-CloudTunes-Timestamp`: Unix timestamp in seconds.
- `X-CloudTunes-Signature`: lowercase hexadecimal HMAC-SHA256.

The signature is computed over the UTF-8 bytes of:

```text
{timestamp}
POST
/v1/stations/{station_id}/reconcile
{lowercase-hex SHA256 of the exact request body}
```

The HMAC key is `CLOUDTUNES_RUNTIME_SHARED_SECRET`. The controller must
reject timestamps outside a short clock-skew window (recommended: 60 seconds),
compare signatures in constant time, and rate-limit invalid requests. The
shared secret belongs only to the queue worker and runtime controller; it must
not be injected into the public web container.

Request JSON contains an operation UUID and a station descriptor with only:
`id`, `name`, `slug`, `mount_path`, `playback_mode`, `desired_state`, and
`runtime_generation`. The generation is a monotonically increasing integer
stored on the station row. Laravel increments it transactionally whenever a
new start or stop intent is accepted; an idempotent repeat does not increment it. It contains no source password, media path, Docker resource
identifier, or executable command. The controller must validate every field
and derive all paths, container names, image names, credentials, and mount
configuration from trusted local policy.

## Response

The controller acknowledges durable intent acceptance with HTTP 202:

\`\`\`json
{
  "accepted": true,
  "operation_id": "f4d58b80-2e0a-4d0e-98c1-91ecf3cdbf34",
  "runtime_generation": 7,
  "message": "Runtime intent durably recorded."
}
\`\`\`

This acknowledgement is not a health observation. Laravel must leave the
station in its transitional state (provisioning or stopping) until a
separate trusted runtime observation confirms the worker and Icecast mount.
The controller must never report live merely because it accepted a request.

The future observation callback must be authenticated independently, include
the operation UUID and runtime generation, and be rejected by Laravel if either
does not match the latest persisted lifecycle intent. Actual health states are
limited to the values in Station::OBSERVED_STATES.

## Idempotency and ordering

The operation UUID is the idempotency key, while `runtime_generation` is the
ordering token. The controller must persist both with the latest accepted
station intent and enforce these rules atomically:

- A request with a generation lower than the stored generation is stale and
  must be rejected without changing runtime state.
- A request with the same generation and same operation UUID is a retry and
  must return the original operation result without duplicating side effects.
- A request with the same generation but a different operation UUID is a
  conflict and must be rejected.
- A request with a greater generation supersedes the prior intent, even when
  intermediate generations were never delivered to the controller.

The generation check closes a race that UUID comparison alone cannot: an older
start request may arrive after a newer stop request. Laravel also checks the
latest persisted lifecycle intent before dispatching runtime work and rejects
stale observations when recording the result. The controller's check remains
mandatory because queue delivery and HTTP requests can race with newer intents.

## Runtime isolation requirements

- One foreground Ices process/container per station.
- One station-specific source account and unique Icecast mount per station.
- A worker receives only its own source credential and station media/playlist
  storage.
- The Icecast config is rendered from trusted templates, validated, replaced
  atomically, and reloaded with `SIGHUP`; never use the Icecast 2.5-only
  `/admin/reloadconfig` endpoint on the current 2.4.x service.
- Do not recursively change ownership of user media directories.
- Do not restart the shared Icecast process for ordinary station create/start/
  stop operations.
- Do not mount an unrestricted Docker socket into the public Laravel web
  container. The controller's Docker API permissions must be constrained to
  the minimum required operations.

## Current implementation status

The Laravel-side HMAC client, typed provider interface, queued
reconciliation job, and monotonic station runtime generation are implemented.
The private runtime-controller API now verifies HMAC timestamps/signatures,
validates the strict station allowlist, and persists operation generations in
a SQLite ledger. It has no Docker socket and does not yet create or stop Ices
workers, update Icecast mounts, or report runtime health. Those are the next
implementation slice; until then the service durably accepts intent but does
not perform playback reconciliation.
