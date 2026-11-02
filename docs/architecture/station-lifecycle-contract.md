# Station lifecycle contract

**Status:** Phase 1 implementation contract

This document defines the database-side lifecycle contract used by the CloudTunes control plane. It does not claim to start or stop Ices processes; actual runtime reconciliation is a later phase.

## Desired state

The station's `desired_state` is the user's requested outcome:

- `running`: the controller should provision/start the station and keep it available.
- `stopped`: the controller should ensure no playback worker is running.

## Observed state

The station's `status` is the latest state reported by the trusted runtime controller:

| State | Meaning |
|---|---|
| `draft` | Created, never started |
| `provisioning` | Runtime resources are being prepared |
| `starting` | Playback worker is starting |
| `connecting` | Worker is attempting to connect to Icecast |
| `live` | Worker and expected Icecast source/mount are healthy |
| `degraded` | Runtime exists but a health check is failing |
| `stopping` | Stop has been requested; cleanup is pending |
| `stopped` | Runtime is confirmed stopped |
| `failed` | Runtime operation failed and requires reconciliation or intervention |

Legacy `running` and `error` values remain readable during the transition from the earlier station model.

## Service contract

`App\\Services\\StationLifecycleService` provides:

- `requestStart(Station, actorUserId)`: transactionally sets desired state to `running`, moves observed status to `provisioning`, and writes a `start_requested` event.
- `requestStop(Station, actorUserId)`: transactionally sets desired state to `stopped`; active stations move to `stopping`. Draft/stopped requests are no-ops.
- `reportObservedState(Station, state, message, operationId)`: accepts observations from the trusted runtime controller, updates health/error fields, and writes a `runtime_state_changed` event.

All state-changing operations lock the station row and use a database transaction. Repeated start/stop requests are idempotent. A stale controller cannot report an active state after stop has been requested, or report stopped while the desired state is running.

## Audit events

Every accepted intent or runtime observation is written to `station_lifecycle_events`. Events include station ID, optional actor user ID, operation UUID, event type, previous/next state, safe result metadata, message, and timestamp. Actor deletion preserves event history by setting the actor reference to null.

Do not put passwords, source credentials, arbitrary process arguments, or secret-bearing configuration into event messages/results.

## Security and runtime boundary

The web/API layer must authorize start/stop using the station policy before calling the service. The public-facing Laravel container must not receive unrestricted Docker socket access. A later trusted controller will consume desired state and report observed state.

A successful `requestStart` means only that the start intent was persisted. It does not mean the station is live. The station is live only after runtime health checks confirm the worker, Icecast source, expected mount, and flowing audio.
