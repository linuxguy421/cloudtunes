# CloudTunes Multi-Tenant Platform Architecture and Migration Plan

**Status:** Proposed; architecture review only  
**Scope:** Self-hosted Docker Compose first, hosted multi-tenant service as a supported future deployment, Kubernetes optional  
**Implementation status:** No application code or runtime configuration is changed by this document

## 1. Product direction

CloudTunes will evolve from a single-station streaming stack into a multi-user platform. Each user can own and operate multiple radio stations through an administrative web portal. A station has independent configuration, music, playlists, playback lifecycle, stream mount, and operational status.

Initial account creation is invite-only. Public registration may be enabled later through an explicit deployment/platform setting. The platform must enforce configurable per-user quotas for concurrent active stations and stored music.

Preserve the PHP/Nginx frontend, Icecast, and Ices where practical. Do not replace a component merely because the current deployment has defects; first establish testable reliability criteria and evaluate alternatives only against those criteria.

## 2. Repository baseline and observed constraints

The current repository has:
- A PHP/Nginx frontend under `radio-frontend/`, including `streaminfo.php` for Icecast metadata.
- An Icecast image/configuration under `docker-icecast/`.
- An Ices playback image, configuration, and song-processing scripts under `docker-ices/`.
- A Memcached service under `docker-memcached/`.
- Kubernetes manifests in service-specific deployment directories.
- An open Compose deployment/player pull request: [PR #2](https://github.com/linuxguy421/cloudtunes/pull/2).

The root README describes a build-time music workflow: put music in `docker-ices/data/station_0/`, generate a playlist during image build, and launch the services. This ties user data and generated runtime state to image construction.

The Compose deployment guide on PR #2 describes named volumes for music, playlists, Ices configuration, and Icecast configuration. Mounting a named volume over a configuration directory hides the configuration copied into the image; rebuilding the image does not update files already in that volume. This is the source of configuration drift observed during troubleshooting.

The PR also states that Docker builds and live playback/persistence tests have not yet been run from its integration session. It retains the existing source password and legacy image build steps. Treat it as useful deployment work, not as proof of production readiness.

The existing repository does not yet define a relational user/station model, a trusted station lifecycle API, tenant isolation, or quota enforcement. These are architectural additions, not assumed existing capabilities.

## 3. Design principles

1. **Station is a first-class resource.** It is represented by a stable ID and persisted state, not inferred from directory names or hand-edited XML.
2. **Tenant isolation is enforced server-side.** Every station, media, playlist, and lifecycle operation is authorized against the authenticated owner/tenant. Hiding UI controls is not authorization.
3. **Desired and observed state are distinct.** For example, desired state may be `running`, while observed state may be `starting`, `live`, `degraded`, `stopped`, or `failed`.
4. **The database is the source of truth for application metadata and desired configuration.** Filesystem paths, worker processes, Icecast mounts, and caches are derived runtime resources.
5. **Persistent user data is not baked into images.** Images contain code and default templates; music and user-managed state live in durable storage.
6. **Configuration lifecycle is explicit.** Defaults, deployment configuration, station settings, and secrets have documented precedence and migration behavior.
7. **The web application does not run arbitrary shell commands or accept raw Ices XML.** A narrowly scoped controller owns process lifecycle operations.
8. **Compose and Kubernetes share a deployment contract.** They may use different storage and orchestration adapters but must preserve the same station semantics and security rules.
9. **Reliability is tested end to end.** A running container is not proof that a source is connected or audio is reaching listeners.
10. **Small, reviewable changes only.** Work occurs on feature/documentation branches and is proposed through pull requests. Never commit directly to `main` or auto-merge.

## 4. Target logical architecture

### 4.1 Components

- **Administrative web UI:** Existing PHP/Nginx frontend retained initially; adds authentication, station dashboard, station settings, media/playlist management, and status views.
- **Application/API layer:** Validates input, enforces ownership and quotas, manages invitations, persists desired state, and exposes station lifecycle operations.
- **PostgreSQL:** Authoritative store for users, invitations, stations, configuration, media metadata, playlists, quotas, and audit events.
- **Station controller/reconciler:** Trusted internal component that compares desired station state with actual worker/stream state and performs controlled start, stop, restart, and recovery actions.
- **Playback workers:** Ices initially, run in the foreground under a clearly defined supervisor/controller. Each active station receives its own independent playback context. The initial implementation must prove whether separate Ices processes with generated per-station configs are reliable before scaling out.
- **Icecast:** Initially evaluate one shared Icecast service with a unique, server-generated mount per station. Confirm source authentication, mount uniqueness, concurrent-source behavior, and failure isolation in tests. Keep the option to isolate Icecast instances later.
- **Music storage:** Durable storage, partitioned by opaque station IDs. Local named volumes are suitable for initial Compose deployment; hosted deployments may require shared/object storage and explicit storage adapters.
- **Memcached:** Optional cache only. It must not store the only copy of station state, user data, or quotas.
- **Observability:** Structured logs and health/status reporting correlate user action, station ID, worker, source connection, mount, and stream health without logging secrets.

Do not mount the Docker socket into the public-facing web container. If container-level worker orchestration is selected, isolate it behind a narrowly scoped management service and document the security boundary.

### 4.2 Logical flow

1. A user authenticates and requests a station operation through the web UI.
2. The API validates the request, verifies station ownership, and checks quotas.
3. The API records the desired state/configuration transactionally.
4. The controller reconciles that desired state with the actual playback worker and Icecast source.
5. Health checks verify source connection, expected mount, and flowing audio.
6. The UI reports observed state and actionable failures; it does not equate a successful API response with a live stream.

## 5. Core domain model

Use PostgreSQL migrations and stable opaque IDs. The exact schema should be reviewed before implementation; this is the proposed logical model.

- **users:** ID, normalized unique email/login, password hash or external identity reference, account status, timestamps.
- **invitations:** token hash, issuer, expiry, redemption timestamp, optional email binding, and status. Store only a hash of invitation tokens.
- **stations:** ID, owner user/tenant ID, name, slug/display metadata, desired state, observed state, configuration version, generated mount identifier, timestamps.
- **station_media:** ID, station ID, opaque storage key, original filename, detected format, size in bytes, processing status, metadata, timestamps.
- **playlists:** ID, station ID, name, ordering/shuffle/repeat settings, timestamps.
- **playlist_items:** playlist ID, media ID, ordering and per-item settings.
- **quota_policies:** applicable user/tenant limits for concurrent active stations and stored bytes; deployment defaults may supply limits for self-hosted installations.
- **station_runtime:** worker identity, last reconciliation, heartbeat, last error, source/mount observation, and relevant runtime version. This is operational state, not the sole source of desired configuration.
- **audit_events:** actor, action, resource ID, outcome, timestamp, and safe metadata for security-sensitive operations.

Add a station-membership/role model when shared station administration is required. Keep owner-only permissions initially if collaboration is not part of the first release, but avoid schema assumptions that make later sharing impossible.

Use foreign keys, unique constraints, indexes, and transaction boundaries to prevent cross-station references and race conditions.

## 6. Quotas and resource controls

- Enforce maximum concurrent active stations per user on every start request.
- Make quota checks concurrency-safe; two simultaneous start requests must not exceed the limit.
- Track stored media bytes from server-verified file sizes, not client-reported values.
- Define whether storage quota counts original files, normalized/transcoded derivatives, temporary uploads, and deleted-but-retained files. Recommended initial rule: count all retained station-owned media and processing outputs; reserve quota for uploads before accepting them.
- Validate file size, file type/content, duration and processing limits. Never trust a filename or client MIME type.
- Define limits for upload size, concurrent processing jobs, bitrate/sample rate where applicable, and per-station worker resources.
- Stop/delete behavior must be explicit: stopping a station must not delete its music; deleting a station should require clear confirmation and a documented retention/backup policy.
- Use database transactions/locking or equivalent atomic accounting for quota reservation and release.
- Expose current usage and limits in the portal; distinguish enforcement errors from temporary infrastructure failures.

## 7. Security and account lifecycle

- Invite-only account creation by default; public registration remains disabled unless explicitly configured.
- Hash passwords with a current password-hashing algorithm and use secure session cookies, CSRF protection for cookie-authenticated state-changing requests, login throttling, and safe password-reset flows.
- Enforce station ownership and role checks in the API and controller, including media downloads, playlist mutations, status, logs, and lifecycle operations.
- Keep Icecast source/admin passwords and other secrets out of Git and image layers. Inject secrets through deployment configuration/secret facilities; do not return secrets to the browser.
- Generate mount names and internal configuration from validated server-side IDs; do not allow users to set arbitrary filesystem paths, process arguments, or XML.
- Restrict admin functions and audit invite creation, account changes, quota changes, station lifecycle actions, and destructive operations.
- Add tenant isolation tests before enabling more than one untrusted user.
- Rotate credentials already exposed in tracked configuration and troubleshooting. Removing a value from the latest file does not remove it from Git history.

## 8. Configuration and data ownership

Separate four categories:

1. **Image defaults:** safe defaults and templates; never user music or deployment secrets.
2. **Deployment settings:** host ports, public base URL, storage backend, database connection, registration mode, initial administrator/bootstrap flow, and platform-level defaults.
3. **Station settings:** validated per-station settings persisted in PostgreSQL and rendered into runtime configuration by the controller.
4. **Secrets:** database credentials, source/admin passwords, session secrets, and external service credentials, supplied separately from tracked configuration.

For Compose, a documented `.env.example` may describe non-secret configuration. Do not assume that an existing named configuration volume will update when an image is rebuilt. Prefer generated configuration from database state plus read-only templates, or a versioned initialization/migration procedure for any retained configuration volume.

Music and station state must survive routine image rebuilds and container recreation. Provide explicit backup and restore instructions for both the database and music storage, and test restoring them together.

## 9. Streaming and station lifecycle contract

Proposed desired states: `stopped`, `running`.

Proposed observed states: `stopped`, `provisioning`, `starting`, `connecting`, `live`, `degraded`, `stopping`, `failed`.

The controller should be idempotent: repeated start/stop requests converge on the requested state rather than spawning duplicate workers. Reconciliation must recover after web/API or controller restarts.

A station is **live** only when checks confirm:
- The worker process is alive and its configuration corresponds to the intended station.
- The source is authenticated and connected to the expected Icecast mount.
- The mount is visible through Icecast status.
- The stream endpoint returns the expected status/content type and audio bytes are flowing.

Track and report failures such as missing music, invalid playlist, source authentication rejection, unavailable Icecast, worker crash, quota exceeded, and storage exhaustion. Health endpoints must not disclose source/admin credentials.

A shared Icecast deployment is the initial hypothesis, not an immutable requirement. If isolation, capacity, or authentication behavior is inadequate, evaluate separate Icecast instances or another routing model with measured tests.

## 10. Deployment models

### Self-hosted Compose (first-class)

- Single documented installation path and environment template.
- PostgreSQL, frontend/API, station controller, Icecast, and playback workers on a private service network.
- Publish only the web interface and intended listener-facing stream/status endpoints.
- Persistent volumes for database and music; avoid publishing internal worker/control ports.
- Safe bootstrap of the first administrator, invitation mode, secret setup, health checks, backup/restore, and upgrades.
- Default quotas configurable by the installation administrator.

### Hosted service (future)

- Same application domain model and station API; deployment adapters may differ.
- Tenant isolation, account verification and recovery, invitation/registration policy, quota enforcement, abuse prevention, monitoring, backups, and resource accounting are required before public onboarding.
- Storage may move to a shared/object-storage backend; worker scheduling and isolation may move to Kubernetes or another orchestrator.
- Do not require one full Icecast server or VM per user by default; measure density and isolation needs before selecting deployment granularity.

### Kubernetes (optional)

- Preserve the current manifests during the early phases.
- Later align secret/config handling, persistent storage, health probes, worker lifecycle, and resource quotas with the same platform contract.
- Kubernetes should not dictate the application data model or become a prerequisite for a self-hosted installation.

## 11. Phased migration plan

### Phase 0 — Documentation, baseline, and security
- Record existing behavior and current deployment topology.
- Define supported audio formats, current stream endpoint contract, metadata behavior, and a known-good test playlist.
- Rotate exposed credentials and stop tracking secrets.
- Document existing volumes and provide a backup procedure before any destructive change.
- Add baseline smoke-test instructions without changing runtime behavior.

**Exit criteria:** reviewed architecture, threat model, acceptance tests, and a reproducible baseline. No destructive volume reset is used as a troubleshooting shortcut.

### Phase 1 — Reliable single-station Compose foundation
- Resolve image-versus-volume configuration ownership.
- Run Ices in foreground under one clear process manager; test reconnect and restart behavior.
- Make music import and playlist generation explicit runtime operations rather than image-build side effects.
- Standardize public/internal URLs, ports, mount naming, and secrets.
- Add a stream-level health test and useful frontend handling for absent source/metadata.

**Exit criteria:** fresh install, upgrade, service restart, source reconnection, actual audio delivery, and data persistence tests pass.

### Phase 2 — Platform data foundation
- Introduce PostgreSQL and migration tooling.
- Add users, invitations, stations, media, playlists, quota policies, and runtime-state schema.
- Introduce a configuration/version model and explicit storage interface.
- Keep the current single station running while schema and service code are developed.

**Exit criteria:** migrations are repeatable, constraints prevent invalid cross-station relationships, and backup/restore covers database plus music data.

### Phase 3 — Authentication, invitations, and authorization
- Add login/logout, safe sessions, invitation creation/redemption, account status, and password reset.
- Default registration to invite-only; make any later public registration opt-in.
- Enforce authorization in the API for all station and media operations.
- Add audit events for security-sensitive actions.

**Exit criteria:** automated tests prove unauthenticated users are rejected and one user cannot read, modify, start, stop, or delete another user's resources.

### Phase 4 — Administrative portal and single-station control
- Add station dashboard, create/edit station, media upload/import, playlist management, and station settings.
- Replace direct file/config edits with validated API operations.
- Show desired versus observed state and useful error messages.
- Use the existing PHP/Nginx frontend as the starting point; modernize only where requirements justify it.

**Exit criteria:** a user can manage the first station end-to-end through the portal, with no manual XML editing or shell access required.

### Phase 5 — Multi-station playback and quota enforcement
- Generate per-station runtime configuration and unique mounts.
- Implement an idempotent controller/reconciler for start, stop, restart, crash recovery, and orphan cleanup.
- Enforce concurrent-station and storage quotas atomically.
- Test simultaneous stations, independent playlists, mount isolation, and resource exhaustion.
- Decide based on evidence whether Ices can be maintained or needs a replacement.

**Exit criteria:** multiple stations broadcast concurrently and independently; quota races cannot exceed configured limits; controller restart reconciles desired state correctly.

### Phase 6 — Hosted-service readiness
- Add operational controls for account recovery, user verification if needed, rate limits, abuse handling, monitoring, backups, restore drills, and resource accounting.
- Test tenant isolation, load, noisy-neighbor scenarios, and failure recovery.
- Introduce storage and worker-scheduling adapters as necessary.
- Keep public registration disabled until operational and security acceptance criteria pass.

**Exit criteria:** documented service-level targets, capacity tests, incident/backup procedures, tenant-isolation tests, and a reviewed launch checklist.

### Phase 7 — Kubernetes parity and release maturity
- Align Kubernetes deployment with the same configuration, storage, secrets, health, and lifecycle contracts.
- Test install, upgrade, rollback, restore, and station recovery.
- Publish versioned releases and a clear support policy.

**Exit criteria:** Compose remains straightforward for self-hosters, while Kubernetes offers equivalent station behavior without creating a separate application model.

## 12. Acceptance test matrix

Every release candidate should cover:

- Fresh Compose install and first-admin bootstrap.
- Invite-only registration and invitation expiry/reuse behavior.
- Authentication, session expiry, CSRF protection, and authorization boundaries.
- Station create/edit/start/stop/restart/delete.
- Multiple active stations with different playlists and unique mounts.
- Concurrent start requests at the station quota boundary.
- Upload quota reservation, rejected oversized files, and storage accounting after deletion.
- Worker crash, Icecast restart, controller restart, and reconciliation.
- Actual audio response headers and continuous audio bytes; browser playback in a supported browser.
- Missing source, missing metadata, invalid playlist, bad credentials, and storage exhaustion.
- Rebuild/recreate/upgrade with persistence of intended state.
- Database-plus-music backup and restore.
- No secrets in Git changes, image layers, browser payloads, or routine logs.

## 13. Pull-request and repository workflow

- Keep `main` stable; do not commit directly to it or enable auto-merge.
- Use focused branches and PRs for each phase or independently reviewable unit.
- Keep the existing Compose PR open until its runtime behavior and volume/configuration model are reviewed against this plan.
- Each PR must state scope, tests run, migration/rollback considerations, persistent-data effects, and any known limitations.
- Do not remove volumes, reset user data, rotate production configuration, or perform destructive deployment actions without explicit review and backup.
- Require review before merging; merge only after the acceptance criteria for the relevant change pass.

## 14. Decisions to validate before implementation

1. Whether one shared Icecast instance and per-station Ices processes meet isolation and capacity requirements.
2. Whether the controller manages worker processes directly or uses isolated worker containers; never grant the public web container unrestricted Docker control.
3. Whether hosted storage uses a mounted filesystem initially or an object-storage adapter from the beginning.
4. Whether station sharing/roles are required in the first user-facing release or only later.
5. Which audio formats and upload/transcoding limits are officially supported.
6. How the first administrator is bootstrapped safely in self-hosted installations.
7. Whether user accounts and station configuration should support export/import for migration between self-hosted and hosted deployments.

These decisions should be resolved with small prototypes or explicit requirements before the corresponding implementation phase, rather than guessed during a broad rewrite.
