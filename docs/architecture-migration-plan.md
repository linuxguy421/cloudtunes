# CloudTunes Architecture Assessment and Migration Plan

**Status:** Proposal for review — no application code or deployment configuration changed by this document.  
**Scope:** Multi-user, multi-station web management foundation; preserve the current public player and stream while migrating incrementally.  
**Deployment direction:** Docker Compose is the default. Kubernetes remains a future/optional runtime provider, not a requirement for the first implementation.

## Executive recommendation

Adopt a **Laravel modular monolith for the web/control plane, with a separately run queue worker using the same application codebase**, while preserving the current PHP player as a compatibility surface during the transition.

This is a deliberate middle path between (A) adding a modern framework to the existing PHP/Nginx frontend and (B) immediately introducing a separately developed API and worker stack. Laravel supplies routing, validation, authentication, database migrations, authorization policies, queue abstractions, and test conventions. Running the web process and worker process as separate Compose services gives us operational separation without maintaining two independently versioned backends.

Do not give the web container unrestricted Docker socket access. Station lifecycle operations must go through a narrow runtime-management boundary. The first implementation should define that boundary and test it with a fake provider; the concrete dynamic station-runtime mechanism must be chosen and security-reviewed before it is enabled.

This is a proposal, not authorization to merge or deploy it. No live service or volume should be modified as part of the planning work.

## Repository findings

The assessment is based on the repository files available during this review:

- `radio-frontend/src/index.php` renders the current player, embeds JavaScript, handles theme preference, polls every 15 seconds, and hard-codes the `/default0.ogg` mount.
- `radio-frontend/src/streaminfo.php` fetches Icecast status, reads/writes Memcached, and directly emits HTML. It suppresses errors and fetches the status endpoint twice.
- `radio-frontend/src/albumart.php` combines cache state with a Last.fm artwork lookup.
- `radio-frontend/Dockerfile` is a large custom image based on PHP 8.1.4 / Alpine 3.15 and builds Nginx 1.21.6 from source. These versions and the image build need a supported-version/security review before expanding the application.
- `compose.yaml` defines one `ices` service, one `icecast` service, the legacy frontend and Memcached. Media/config/playlist data use named volumes. The frontend uses the `ICECAST_STATUS_URL` and `ICECAST_PUBLIC_URL` environment variables.
- `docker-ices/etc/ices/config/default0.xml` uses a fixed mount, `/default0.ogg`, and a playlist path. The current configuration is effectively one station per deployment.
- `docker-ices/Dockerfile` on `main` still copies `data/` into the image and invokes media scripts during build. PR #8 (`phase1/runtime-playlist-init`) proposes moving playlist generation to runtime and has been validated in an isolated Compose environment; it remains a separate review item and is not assumed merged by this plan.
- `.gitignore` ignores `.env` and `/secrets/`, but not every local/runtime artifact should be presumed safe by default. Keep secrets, media, generated playlists, and backups out of commits.

### What this means

The existing frontend is adequate as a small public player but is not a suitable place to accumulate account management, media uploads, station ownership, quotas, and process control. The issue is not that PHP is inherently unmaintainable; it is the lack of clear application layers and the one-station assumption.

## Backend options compared

### Option A — Laravel application, with web and worker processes

**Shape:** One Laravel codebase; a web/API process serves the management portal and endpoints, while a separate Compose worker runs queued jobs. Both use PostgreSQL and the same versioned application image. The existing player remains separate until its migration is justified.

**Advantages**
- Lowest conceptual and language overhead given the existing PHP application.
- Mature authentication, sessions, CSRF protection, request validation, policies, migrations, queues, rate limiting, and test tooling.
- Shared domain rules and authorization for web requests and asynchronous jobs; less risk of duplicated policy logic.
- Worker can be deployed and restarted independently without creating a second backend codebase.
- Straightforward Compose-first packaging.

**Costs/risks**
- Requires introducing Laravel conventions and rebuilding the custom legacy frontend image for the new application.
- The old player remains temporarily, so two web surfaces exist during migration.
- Laravel does not itself solve dynamic station provisioning or safe runtime isolation. That remains a separate interface and security decision.

**Assessment:** Best fit for the first control plane. Keep it modular and avoid making the Laravel web process directly control streaming processes.

### Option B — Separate API and station worker as independently developed services

**Shape:** A backend API owns authentication, station data, and permissions. A separate worker/service owns provisioning, playlists, and playback operations; the frontend calls the API. The services communicate through a queue or authenticated internal API.

**Advantages**
- Strong conceptual boundary between user-facing requests and long-running runtime operations.
- Worker can be implemented and scaled independently if automation becomes substantial.
- Can support multiple runtimes and future hosted deployments cleanly.

**Costs/risks**
- More service contracts, deployments, observability, retries, and integration tests from day one.
- If API and worker use different stacks, domain validation and ownership rules can drift unless contracts are explicit.
- A separate worker still needs a safe way to control station processes; splitting services does not automatically make Docker control safe.

**Assessment:** Valuable when runtime automation has enough complexity to justify a distinct service. It is premature to create an independently versioned backend and worker before station lifecycle behavior is understood.

### Recommended compromise

Use Laravel as a **modular monolith for the control plane**, but run its worker as a distinct process/container. Define a stable `StationRuntimeProvider` interface so that a separately deployed runtime manager can be extracted later without changing station domain models or HTTP endpoints.

This keeps today's system small while preserving a clean extraction path. Revisit a separate runtime-manager service when the first real lifecycle implementation demonstrates its isolation, scaling, or deployment needs.

## Proposed logical architecture

```text
Browser
  ├── Existing public player (kept working during migration)
  └── Management portal
         │ HTTPS / session-authenticated requests
         ▼
Laravel web/API
  ├── Authentication and authorization policies
  ├── Station and media domain services
  ├── PostgreSQL (source of truth)
  └── Durable queue
         │
         ▼
Laravel worker
  ├── Rebuild station library / random playlist
  ├── Request lifecycle changes
  ├── Reconcile desired vs observed state
  └── Call StationRuntimeProvider
         │ narrow, authenticated control boundary
         ▼
Station runtime adapter
  ├── Initial Compose implementation (decision required)
  └── Future Kubernetes implementation (optional)
         │
         ▼
Station playback process(es) ───► Icecast ───► public audio mount(s)
```

The diagram is logical, not a final Compose file. Service names and container topology should be finalized only after a small runtime proof of concept.

### Responsibilities

- **Laravel web/API:** user requests, authentication, authorization, input validation, station/media metadata, status views. It must not mount the Docker socket or run arbitrary shell commands.
- **PostgreSQL:** authoritative station ownership, configuration, media records, quotas, lifecycle desired state, and audit history. Memcached is not a source of truth.
- **Queue/worker:** durable, retryable background work; idempotent playlist generation and reconciliation; clear failure state and logs.
- **Runtime provider:** the only application boundary allowed to request station process lifecycle changes. It accepts typed operations such as provision/start/stop/reconcile; it does not accept arbitrary command strings from the web layer.
- **Icecast:** streaming delivery and mount status. The player continues connecting to public stream URLs directly; audio bytes should not be proxied through Laravel.
- **Legacy player:** initially remains available with its current public URL and mount. Migrate metadata display separately from the management portal.

## Data model foundation

Use database migrations and foreign keys from the start. Suggested initial entities:

- **users** — account identity, invite/activation state, role, and account-level quota defaults or overrides.
- **stations** — owner user ID, display name, stable public slug/ID, status, desired state, playback policy (initially `random`), stream mount, configuration version, timestamps.
- **media_items** — station ID, storage-relative path/key, original filename, normalized metadata, media type/codec/duration when known, file size, checksum, ingest/validation state, timestamps.
- **station_runtime_state** (or equivalent status fields plus events) — observed runtime state, last successful health check, current error, last transition time. Do not treat a browser's status poll as authoritative.
- **jobs / lifecycle events** — durable requests and outcomes, retries, correlation ID, and sanitized error details. Avoid logging credentials or private file contents.
- **quota configuration** — configurable concurrent-station and storage limits, with a global default and per-user override. Enforce limits transactionally in the backend, not only in the UI.

Do not create schedule/calendar tables yet. Keep playback policy explicit (initially random) and isolate track selection behind an interface so scheduled programming can be added later without embedding schedule assumptions into station ownership or the runtime API.

### Media and storage rules

- Store media in persistent storage, never in an application image or ephemeral container layer.
- Store a database-relative key/path, not an arbitrary host path supplied by a user.
- Upload flow should stream to a temporary location, enforce file-size and allowed-format limits, validate the media, calculate a checksum, then atomically mark the media ready.
- Removing a media record and deleting its bytes should be an explicit, auditable operation with clear error handling. Never run the existing mutating media scripts against the only copy of a library.
- Station playlists are generated artifacts; the database/media library is authoritative. Playlist generation must be atomic and must not overwrite a working playlist if no eligible tracks are available.

## API and authorization shape

Start with a small versioned API, even if the UI is server-rendered initially:

- `GET /api/v1/stations` — stations visible to the authenticated user.
- `POST /api/v1/stations` — create a station after quota and validation checks.
- `GET /api/v1/stations/{station}` / `PATCH ...` — read/update only stations the caller owns or is authorized to administer.
- `POST /api/v1/stations/{station}/start` and `POST .../stop` — enqueue desired-state changes; return an operation/job identifier rather than pretending the operation completed synchronously.
- `GET /api/v1/stations/{station}/status` — report desired and observed state, current track when available, last health check, and recent sanitized error.
- `GET/POST/DELETE /api/v1/stations/{station}/media` — list/upload/remove station media with ownership checks and limits.

Every station/media lookup must enforce authorization in the backend, not rely on hidden UI controls or guessable IDs. Include CSRF protection for cookie-authenticated state changes, request validation, rate limits, safe upload handling, and audit events for lifecycle and destructive media operations.

The API should not expose Icecast source credentials, internal service URLs, host paths, arbitrary container IDs, or raw runtime errors.

## Station runtime decision — required before implementing real start/stop

Compose does not provide a native declarative resource per user-created station. The project must choose how a station maps to playback processes before claiming dynamic multi-station support.

Evaluate two implementation choices with a small isolated proof of concept:

1. **Managed station processes in a dedicated runtime container:** simpler Compose deployment and no Docker socket, but stronger process/resource isolation and fault containment need careful design.
2. **One playback container per station:** clearer isolation and resource limits, but dynamic provisioning requires a runtime manager. Do not expose the raw Docker socket to Laravel; if a Docker API proxy is used, tightly restrict endpoints and methods, run it only on the internal network, and threat-model the fact that container control can be host-equivalent authority. A future Kubernetes provider can implement the same interface.

Acceptance criteria for this decision: create/start/stop/delete a test station; recover after runtime-manager restart; enforce station-specific media/config/mount boundaries; prove one station cannot read another's files or credentials; no host socket in the web container; and no change to the live station. Do not choose the mechanism solely for convenience before this test.

## Phased migration plan

### Phase 0 — Stabilize and establish a safe baseline

- Keep PR #8 (runtime playlist initialization) as its own reviewable change; review and merge it only by explicit maintainer decision. Do not mix it into an architecture-document PR.
- Reconcile the outstanding deployment/hostname branches with the intended base before implementation. Do not assume `main`, `feat/compose-deployment`, and `phase1/runtime-playlist-init` contain identical Compose configuration.
- Record a baseline for the existing public player, Icecast mount, stream byte flow, and current named volumes. Do not recreate live services or delete/rename volumes.
- Review the frontend image's PHP, Alpine, Nginx and build dependencies for supported versions; do not combine this audit with a stream-runtime redesign.
- Confirm that local secrets, `.env`, music, backups, and generated playlists remain untracked and protected.

**Exit gate:** a repeatable isolated test environment and documented stream/volume baseline; no live changes.

### Phase 1 — Add the application foundation beside the legacy frontend

- Create a new Laravel application directory and supported PHP image using reproducible, pinned dependencies.
- Add PostgreSQL and application/worker services to a dedicated development/test Compose overlay first, with separate named volumes and non-conflicting ports. Do not replace the live Compose stack.
- Add migrations for users, stations, media items, lifecycle state, and quota settings; add model relationships, validation, authorization policies, and tests.
- Use a durable database-backed queue initially if that is sufficient for expected job volume; avoid adding Redis solely by habit. Reassess queue backend after measuring needs.
- Implement a fake runtime provider and queue tests so domain behavior can be verified without starting or stopping real streaming processes.

**Exit gate:** migrations run from an empty test database; authorization tests prove one user cannot access another user's station/media; queue retries are idempotent; test Compose starts without touching live volumes.

### Phase 2 — First management portal and media library

- Build login/invite flow and station list/detail pages.
- Implement create/edit station metadata and media upload/list/remove. Keep playback runtime actions disabled or clearly marked unavailable until the runtime provider is approved.
- Enforce per-user station and storage quotas in service/database transactions; report quota failures clearly.
- Add safe upload validation, path traversal tests, file-size limits, checksum tracking, and media status.
- Keep the public player on its existing deployment and URL. Do not proxy audio through the new app.

**Exit gate:** an end-to-end test user can create a station and manage its library in an isolated environment; media and metadata persist across app-container recreation; no live stack changes.

### Phase 3 — Runtime provider proof of concept and first station automation

- Choose between managed processes and per-station containers based on the explicit isolation tests above.
- Implement only the selected adapter behind `StationRuntimeProvider`; do not allow the web/API service direct process or Docker access.
- Implement desired-state operations, idempotent retries, health/status reporting, and reconciliation after worker/runtime restarts.
- Generate random playlists from each station's eligible media; preserve the current working playlist if generation fails or the library is empty.
- Test start/stop, missing media, malformed media, runtime failure, restart/recovery, and station-to-station isolation in an isolated Compose project.

**Exit gate:** two test stations can play independently, status is observable, one station's failure does not corrupt the other, and no existing live service/volume is involved.

### Phase 4 — Incremental player integration and cutover

- Introduce a stable now-playing/status response for the player, returning JSON rather than PHP-generated fragments.
- Migrate metadata and artwork behavior behind tested services; preserve the existing player experience and public stream URL while validating the replacement.
- Replace the old player only after browser playback, artwork fallback, stream status, accessibility, and mobile layout are verified.
- Remove Memcached or legacy endpoints only after confirming no remaining consumers.

**Exit gate:** old and new player paths can be compared; cutover and rollback are documented; stream URL and persistent media remain unchanged.

### Phase 5 — Hardening and deployment options

- Add invite administration, account disablement, quota overrides, audit visibility, rate limits, backup/restore, metrics, and operational documentation.
- Add per-station CPU/memory/storage constraints and cleanup behavior with explicit data-retention semantics.
- Extract the runtime manager into an independently deployed service only if operational evidence justifies it.
- Consider a Kubernetes runtime provider behind the same interface. Compose remains the default supported deployment.
- Add scheduling only when it becomes a product priority, as a separate playback policy; do not build scheduling in these phases.

**Exit gate:** documented recovery/restore drills, security review of runtime control, automated test suite, and a reviewed migration/rollback procedure.

## Testing and operational guardrails

- Unit tests for station ownership, quotas, media validation, random selection, and lifecycle state transitions.
- Integration tests against an ephemeral PostgreSQL database and fake runtime provider.
- Compose smoke tests in a separate project name, with separate ports and explicitly named test volumes.
- Playback regression test checks Icecast status, expected mount, and bytes flowing. A byte-flow check is necessary but does not prove audio is decodable; include an audio decode check in the isolated runtime acceptance suite.
- Verify persistence after app/worker/runtime container recreation. Never use `docker compose down -v` in a test or operational procedure that could target user data.
- Keep source credentials out of API responses, frontend bundles, logs, images, and repository history. Do not expose the raw Docker socket to the web container.
- Each implementation phase gets its own branch and reviewable PR. No direct commits to `main`, no auto-merge, and no merge without explicit approval.
- Do not run migrations, rebuild/recreate live services, or alter live named volumes during development. Production cutover requires a separately approved plan and maintenance/rollback decision.

## Immediate next implementation PR after this proposal

Once this plan is reviewed, the first code PR should be **Phase 1 only**: introduce the Laravel skeleton, test-only Compose overlay, initial migrations/models/policies, and fake runtime provider. It should not add real Docker control, station process management, upload-to-live-volume behavior, or replace the current player.

The runtime provider and upload persistence should not be considered production-ready merely because the schema exists. The architecture decision about station isolation must be resolved and tested before enabling user-driven station lifecycle operations.
