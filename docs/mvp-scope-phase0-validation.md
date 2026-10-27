# CloudTunes MVP Scope and Phase 0 Validation Plan

**Status:** Draft for review  
**Deployment direction:** Docker Compose first; Kubernetes remains an optional future deployment target.  
**Migration direction:** Clean redesigned installation with a supported path to import music. Preserve useful existing components only where testing and maintenance justify them.

## Product goal

CloudTunes is a multi-user radio platform. An approved user can create independently managed radio stations, maintain a private music library, build playlists in the web interface, and publish stable public stream URLs. Administrators manage account approval, quotas, station health, logs, restarts, and media.

The first release should prove one complete vertical slice: **register -> administrator approval -> create station -> add music -> build playlist -> start station -> listen to its public URL**.

## MVP requirements

### Accounts and authorization

- Open registration; an administrator must approve an account before it can create stations.
- Provision the initial administrator through a secure installation/bootstrap procedure.
- Enforce user ownership on every station and media operation.
- Public listening does not grant access to station management, private media, logs, or administrative endpoints.

### Stations and playback

- Default quota: 3 concurrent stations per approved user; administrator-configurable.
- Station creation uses a guided wizard.
- Each station has independent configuration, playlist, process lifecycle, and observable status.
- Shuffle is the first playback mode; the model should permit additional modes later.
- Public URLs use unique, stable station slugs, e.g. `/stream/<slug>`.
- Failed station processes are reported and wait for an explicit manual restart.
- After host reboot, restore stations that were intended to be running before shutdown, after dependencies are healthy. Do not interpret an unexpected process failure as permission for an unlimited restart loop.
- Station deletion requires confirmation and uses a soft-delete period before permanent cleanup.

### Media and playlists

- Private per-user music libraries with a default 10 GB quota per user; administrator-configurable.
- Initial accepted formats: MP3 and Ogg Vorbis.
- Upload and server-side import copy media into CloudTunes-managed storage; imported files count against the owning user's quota.
- Users can select and order tracks in the web UI.
- Transcode when required to meet the selected, documented stream format.
- Validate file contents rather than trusting filename extensions or client-supplied MIME types.
- Reserve quota before concurrent uploads, reconcile failed/interrupted uploads, and release usage only when media is actually removed.
- Soft-deleted files continue to consume storage quota until permanent cleanup, unless a separately documented policy states otherwise.

### Administration and operations

- Approve/reject users; adjust quotas; view station health and logs; manually restart stations; manage user media.
- Provide administrator-triggered backup and restore, with a tested restore procedure.
- Use Docker Compose as the supported initial deployment and upgrade path.
- Use a narrowly scoped station manager. Do not give the web application unrestricted Docker socket access or arbitrary command execution.
- Keep application metadata in PostgreSQL and media in persistent storage.
- No automatic PR merges, no direct commits to `main`, and no changes to live services or persistent volumes without explicit approval.

## Proposed logical architecture

1. **Web/control plane:** authentication, approval, station/media metadata, ownership checks, quotas, desired state, audit records, and queued operations.
2. **PostgreSQL:** source of truth for accounts, station configuration, media metadata, quota accounting, and lifecycle history.
3. **Station manager:** a narrowly scoped service responsible for validated start/stop/restart operations, health checks, logs, and reconciliation between desired and observed state.
4. **Media pipeline:** managed private storage, upload/import validation, playlist management, and bounded transcoding jobs.
5. **Streaming runtime:** evaluate existing Ices and Icecast empirically before choosing the multi-station implementation.
6. **Reverse proxy:** stable public stream paths; management, administrative, and private-media endpoints remain authenticated.

These are logical responsibilities, not a final decision about the exact number of containers or processes. Prefer the smallest deployment topology that meets isolation and operational requirements.

## Phase 0: repository and runtime audit

### Current known facts

- The current `compose.yaml` runs Icecast, one Ices service, the legacy frontend, and Memcached. Icecast and the frontend are currently exposed on separate host ports.
- The current Ices configuration uses a fixed mount/playlist model, so it is effectively a single-station deployment.
- PR #8 (`phase1/runtime-playlist-init`) reports a successful isolated Compose test that generated a playlist and verified that audio bytes arrived from Icecast. This is useful preliminary evidence, but does not prove multi-station isolation, long-running stability, or reboot recovery.
- PR #10 (`phase1/control-plane-foundation`) is a draft Laravel control-plane scaffold with a fake runtime provider and a separate development Compose stack.
- PR #11 (`fix/php-dom-extension-build-order`) is a draft build fix. Its latest Docker extension change still needs a successful no-cache build, migrations, dependency lockfile, and test run.
- The current frontend Dockerfile uses an old PHP/Alpine base and builds an old Nginx version from source. Review supported versions and security maintenance before expanding the legacy image.
- Existing PRs are separate proposals and are not assumed merged.

### Runtime validation gate

Run tests in an isolated Compose project with disposable test volumes. Do not point the test at the existing live volumes and do not run `docker compose down -v` against the normal project.

1. Build the Ices and Icecast images reproducibly.
2. Verify MP3 and Ogg Vorbis inputs and decide whether each can be streamed directly or requires transcoding.
3. Run two independent station processes with different playlists and mounts; verify their output and configuration do not cross-contaminate.
4. Confirm the station manager can identify process exit, capture useful logs, and perform a deliberate manual restart.
5. Simulate a host/service restart and verify only stations whose persisted desired state is running are restored after dependencies become healthy.
6. Verify bounded resource use, safe process termination, and cleanup of orphaned processes.
7. Verify stable public stream paths can be routed through the planned reverse proxy without exposing Icecast administration.
8. Record evidence, limitations, and a go/no-go recommendation for Ices. If it fails, compare alternatives before implementing the production runtime adapter.

### Control-plane build gate

On the isolated development stack only:

1. Rebuild the control-plane image with no cache and capture the first actual compiler/build error if it fails.
2. Resolve Composer dependencies and commit `composer.lock`.
3. Run database migrations against the isolated PostgreSQL service.
4. Run the application test suite and report actual results.
5. Confirm the existing `compose.yaml` and named volumes were not modified by these tests.

## Phased implementation

### Phase 0 — Audit and decisions
Deliver repository findings, architecture decision records, runtime test evidence, and a clear retain/replace recommendation for Ices/Icecast. Resolve the current isolated control-plane build/test gate. No live deployment changes.

### Phase 1 — Reliable development/deployment foundation
Make clean builds, dependency locking, migrations, secrets/configuration, health checks, and local setup repeatable. Do not expand the legacy player image without a supported-version review.

### Phase 2 — Vertical slice
Implement the first usable path: approved login, station wizard, managed upload, playlist editing, station start, and verified public listening URL. Prove two independent stations before declaring the runtime architecture valid.

### Phase 3 — Multi-user and quotas
Implement registration approval, ownership policies, private media access, unique slugs, transactional station and storage quotas, upload reservations, import validation, and soft deletion.

### Phase 4 — Operations
Implement station health/logs, manual restart, desired-state persistence, reboot restoration, safe process termination, orphan cleanup, and administrator controls.

### Phase 5 — Release hardening
Complete transcoding limits, backup/restore, security review, documentation, upgrade rehearsal, and the release acceptance suite.

## Pull request and safety policy

- Each phase is split into small, reviewable branches and PRs with explicit acceptance tests.
- Do not commit directly to `main`; do not auto-merge PRs.
- Do not overwrite untracked/local files or force-reset existing branches.
- Do not alter/restart live services, migrate live databases, or delete/recreate live volumes without explicit user approval.
- Report test evidence honestly; do not mark tests passed unless they were run and their results were observed.

## MVP release acceptance

- An unapproved user cannot create or start a station.
- Cross-user station and media access is denied.
- The default 3-station and 10 GB quotas are enforced under concurrent requests.
- MP3/Ogg uploads and imported files are validated, accounted for, and playable as specified.
- Users can edit playlists and start a station through the wizard.
- Two stations run independently with stable public slug URLs.
- A station failure is visible and requires explicit restart; previously running stations recover after host reboot.
- Administrators can inspect health/logs, manage users/media, adjust quotas, and restart stations.
- Soft deletion and permanent cleanup preserve correct quota accounting.
- Backup/restore succeeds in an isolated recovery test.
- A clean Compose installation and upgrade can be completed from documented instructions.
