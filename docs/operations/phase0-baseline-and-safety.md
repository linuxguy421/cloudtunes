# Phase 0 — Baseline, Safety, and Acceptance Checklist

**Status:** Initial repository review only; live runtime checks remain unverified.
**Scope:** Documentation and safe observation. No application/runtime changes.
**Related:** [Architecture proposal in draft PR #3](https://github.com/linuxguy421/cloudtunes/blob/docs/multitenant-architecture-plan/docs/architecture/multi-tenant-platform.md) and [open Compose PR #2](https://github.com/linuxguy421/cloudtunes/pull/2).

## 1. Guardrails

- Do not delete Docker volumes or run `docker compose down -v`.
- Do not rebuild/recreate a live installation until configuration and persistent data are backed up.
- Do not run song-processing scripts against the only copy of user media; current scripts can rename, convert, or delete files.
- Do not rotate live stream credentials without a coordinated change to all consumers and a recovery plan.
- Redact credentials and private URLs from any evidence shared publicly.
- A healthy container or HTTP status endpoint does not prove that audio is flowing.
- Repository inspection does not prove what is running on the user's host. All live checks below require an operator with deployment access.

## 2. Initial repository findings

The repository contains a PHP/Nginx frontend, Icecast, Ices, Memcached, Kubernetes manifests, and a legacy Kubernetes-oriented launcher. The Compose proposal is on branch `feat/compose-deployment` and remains open in [PR #2](https://github.com/linuxguy421/cloudtunes/pull/2).

The open Compose proposal:
- Publishes the web interface and Icecast listener/status port; Memcached and Ices remain on the private Compose network.
- Uses named volumes for music, Icecast configuration, Ices configuration, and playlists.
- Sets public and internal Icecast URLs through environment variables.
- Has not yet been validated by a documented live playback and persistence test in the PR.

Important risks to verify:
1. **Persistent configuration drift.** Named volumes mounted over `/etc/icecast2`, `/etc/ices/config`, and `/etc/ices/playlist` hide files baked into images. Rebuilding an image does not update files already in those volumes.
2. **Build-time music processing.** The Ices Dockerfile runs media preparation and playlist generation during image construction. Some scripts mutate, convert, rename, or delete files.
3. **Worker lifecycle.** The checked-in Ices configuration uses background mode while Supervisor also manages Ices. Validate this behavior before changing either setting.
4. **Health semantics.** Icecast's status endpoint can respond even if no source is connected. Verify source/mount presence and continuous audio bytes.
5. **Metadata failure handling.** The current `streaminfo.php` on the Compose branch suppresses PHP errors and assumes a source exists in the status JSON. Test absent-source and missing-metadata behavior safely.
6. **Credential exposure.** Tracked Icecast/Ices configuration contains hard-coded authentication values. Treat exposed values as compromised. Do not copy them into this document, terminal transcripts, issues, or pull requests.
7. **Build reproducibility.** Some Dockerfiles use mutable base-image tags such as `latest`. Track this for later hardening rather than mixing it into baseline diagnosis.

## 3. Operator baseline capture

Run read-only checks from the actual deployment directory. Redact output before sharing.

### Identify revision and services

```bash
git status --short
git rev-parse --abbrev-ref HEAD
git rev-parse HEAD
docker compose ps
docker compose images
```

Record environment type (test or production-like), deployment method, image versions, expected ports, intended public stream URL, and service names. Do not assume the running installation uses PR #2's Compose file.

### Capture current status without restarting

```bash
docker compose ps
docker compose logs --since=30m --no-color icecast ices frontend memcached
```

Use the actual service names if they differ. Inspect logs locally before sharing them.

Record whether the frontend loads, Icecast responds, the expected source mount appears, Ices stays running or repeatedly exits, metadata is current, and audio plays continuously.

### Inspect effective mounts

```bash
docker compose config
docker inspect "$(docker compose ps -q icecast)" --format '{{json .Mounts}}'
docker inspect "$(docker compose ps -q ices)" --format '{{json .Mounts}}'
```

Review resolved configuration locally; do not post secret-bearing output publicly. Record volume names and mount destinations. Back up the effective configuration from the running deployment or its volumes; do not assume repository files are the files actually in use.

### Verify the stream

Use the real configured URL in the operator's private shell:

```bash
curl -sS -D - -o /dev/null "$ICECAST_PUBLIC_URL/default0.ogg"
```

Then use a known-good client for a short playback test. Record HTTP status/content type, source mount visibility, audible continuous playback, metadata behavior, and whether a remote listener can connect. An HTTP response alone is not a successful stream test. Do not save an unbounded stream response to disk.

## 4. Data inventory and backup gate

Inventory and back up:
- Effective Icecast and Ices configuration and referenced authentication files.
- All music files, including files not listed in the generated playlist.
- Playlists and any user-maintained files.
- Relevant deployment configuration, with credentials stored separately and access-controlled.
- Kubernetes storage/configuration details if Kubernetes is the active deployment.

Record each location, storage type, approximate size, backup time, destination, retention, and restore owner. Verify backup contents and test restore in an isolated environment; never restore over the live data directory.

**No destructive configuration migration, volume replacement, media processing, or deployment upgrade until an appropriate backup exists and rollback is understood.**

## 5. Credential remediation runbook

1. Inventory credential types and every consumer without recording secret values.
2. Generate replacement values using an approved secure method and store them outside Git.
3. Schedule a coordinated update for the Ices source and Icecast authentication configuration.
4. Update the effective configuration used by the deployment, not just image source files.
5. Verify the new credentials work, old credentials are rejected, and continuous audio returns.
6. Validate admin access and any external integration using the changed credentials.
7. Remove secrets from active tracked configuration in a separate reviewed PR.
8. Assess Git history and other copies separately; deleting a value from the latest file does not remove it from previous commits.

Do not rotate production credentials solely as part of this documentation step. Coordinate the operation and recovery path first.

## 6. Phase 0 acceptance checklist

Mark each item complete only with evidence from the actual target environment.

- [ ] Deployment type, revision, services, image versions, and intended endpoints recorded.
- [ ] Effective configuration sources and volume mounts inventoried.
- [ ] Music, playlist, and persistent storage locations recorded.
- [ ] Icecast status and source/mount presence captured.
- [ ] Ices process behavior and relevant logs captured.
- [ ] Actual continuous audio playback verified.
- [ ] Frontend and now-playing metadata behavior verified while streaming.
- [ ] Missing-source and missing-metadata behavior tested in a non-production environment.
- [ ] Backup created, contents verified, and restore tested in isolation.
- [ ] Credential consumers inventoried and coordinated rotation plan reviewed.
- [ ] Rollback procedure documented.
- [ ] Evidence stored privately with sensitive values redacted.
- [ ] Isolated Phase 1 test environment identified.

## 7. Required tests before runtime redesign

| Test | Expected result |
|---|---|
| Fresh install | Services initialize with documented settings and a known-good sample station |
| Persistence | Music, playlists, and intended settings survive routine container recreation |
| Configuration upgrade | A documented path updates configuration stored in persistent volumes |
| Source authentication | Ices authenticates and connects to the expected mount |
| Continuous playback | Listener receives valid audio continuously |
| Missing source/metadata | UI degrades gracefully without warnings or stale data presented as live |
| Ices restart | Worker recovers predictably without an endless rapid restart loop |
| Icecast restart | Source reconnects and streaming returns |
| Cache outage | Streaming behavior remains understood; cache is not treated as authoritative state |
| Backup/restore | Configuration and media can be restored in isolation |
| Secret hygiene | Credentials are absent from tracked active configuration, browser responses, and routine logs |
| Upgrade/rollback | Data is preserved without deleting persistent volumes |

## 8. Exit criteria and non-goals

Phase 0 is complete when the baseline has been captured, backup/restore evidence exists, credential remediation has a reviewed runbook, a rollback path is understood, and a test environment is ready.

This phase does not add PostgreSQL, accounts, station APIs, a controller, quotas, or a new playback engine. It does not replace PHP/Nginx, Icecast, or Ices, upgrade base images, or change the Compose volume model. Those are subsequent reviewed changes.
