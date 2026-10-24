# Docker Compose Migration Plan

## Goal

Make Docker Compose the default, supported deployment path for CloudTunes while retaining Kubernetes as an optional deployment strategy for advanced users. All implementation work must be delivered on focused branches and reviewed through pull requests; do not commit directly to `main` or merge automatically.

## Deployment principles

- **Compose first:** a new self-hosting user should be able to configure and start CloudTunes using the Compose plugin and a documented `.env` file.
- **Kubernetes remains supported as an optional path:** preserve existing manifests and document Kubernetes-specific configuration separately. Do not remove Kubernetes resources as part of the initial Compose introduction.
- **Protect user data:** music, playlists, station configuration, and other state must not exist only in a disposable container or be lost during updates/uninstall.
- **Configuration, not source rewriting:** remove runtime/deployment dependence on replacing arbitrary IPv4 literals in PHP files. Use service DNS and explicit configuration values.
- **Least exposure:** expose only the web interface and intended streaming endpoints on the host; keep internal services such as Memcached on a private network.
- **Repeatable operations:** builds, upgrades, health checks, status reporting, backup, restore, and uninstall behavior should be documented and testable.
- **Small reviewable changes:** each PR should have a single primary purpose, validation notes, and clear rollback implications.

## Proposed sequence

### Phase 1 — Compose deployment foundation

Branch: `feat/compose-deployment`

- Add a Compose file for the current services: Icecast, Ices, Memcached, and the PHP/Nginx frontend.
- Use an explicit private network and service names rather than fixed host addresses for container-to-container communication.
- Add persistent mounts for music, playlists, and editable configuration. Confirm the existing image entrypoints and scripts can read/write these mounts before choosing paths.
- Publish only the ports required by the user-facing interface and audio stream. Do not publish Memcached by default.
- Add health checks only where a reliable check is available; do not treat a fixed sleep as readiness.
- Add `.env.example` and document which values are safe defaults versus required user configuration.
- Keep all Kubernetes manifests and workflows unchanged.

**Acceptance criteria**
- `docker compose config` succeeds with the documented configuration.
- A clean install can build or pull all required services and start them.
- Music and playlists persist across container recreation and image updates.
- Service-to-service communication does not require a hardcoded host IP.
- The web UI and stream endpoints are documented, and Memcached is not exposed on the host.
- Kubernetes deployment files remain present and unchanged in this phase.

### Phase 2 — Configuration and container reliability

Branch: `refactor/configuration`

- Replace hardcoded IPs in frontend PHP/configuration with explicit settings and Compose DNS defaults.
- Remove the broad `sed`-based IPv4 replacement from the normal deployment path.
- Add sensible HTTP timeouts, error handling, and logging for Icecast metadata and external API requests.
- Escape metadata before rendering it as HTML and validate URLs used for images or other client-side resources.
- Review image base versions, pinned dependencies, and insecure HTTP downloads; scope upgrades carefully to avoid mixing unrelated changes.
- Ensure the same applications can receive equivalent settings in Kubernetes.

**Acceptance criteria**
- No deployment step edits arbitrary PHP source to rewrite IP literals.
- Missing metadata/cache/API responses degrade gracefully.
- User-supplied or upstream metadata is safely rendered.
- Configuration requirements are documented for Compose and Kubernetes.

### Phase 3 — Compose-first management CLI

Branch: `feat/compose-cli`

- Refactor `cloudtunes.sh` so Compose is the default workflow.
- Provide clear commands for install/start, update, status, logs, backup, restore, and uninstall.
- Offer Kubernetes operations only through an explicit option or separate command path.
- Check required tools based on the selected deployment target; Kubernetes utilities must not be mandatory for Compose users.
- Validate configuration and Compose services before destructive operations.
- Never remove music, playlists, or user configuration during uninstall unless the user explicitly requests data deletion.
- Avoid automatically pulling or deploying unreviewed changes as part of a local install.

**Acceptance criteria**
- Compose users do not need `kubectl`, `kubectx`, `kubens`, or k3s installed.
- Commands fail clearly on missing prerequisites and unsuccessful service startup.
- Update does not erase persistent data.
- Uninstall explains what will be removed and preserves persistent data by default.

### Phase 4 — Documentation, validation, and optional Kubernetes guide

Branch: `docs/deployment-guide`

- Rewrite the README quick start around Docker Compose.
- Document configuration, port selection, persistent data, updates, backup/restore, troubleshooting, and uninstall.
- Move Kubernetes instructions into a clearly labeled advanced deployment guide.
- Add CI checks for shell syntax, Compose configuration, and available build/test validation.
- Document a manual smoke-test checklist for the UI, stream playback, metadata, and persistence.

**Acceptance criteria**
- A new user can follow the default README without encountering Kubernetes prerequisites.
- Kubernetes remains discoverable and documented as an optional strategy.
- Test steps and known limitations are explicit.

## Branch and pull-request workflow

1. Create each feature branch from the latest `main`, after its prerequisites have merged.
2. Keep one primary concern per branch and PR.
3. Open a PR targeting `main`; include summary, design choices, test commands/results, data-safety notes, and rollback instructions.
4. Review the diff and test results before merging. Do not enable automatic merge or merge a PR on the author's behalf.
5. After a PR is merged, update local `main` and branch the next dependent phase from it.
6. If a phase reveals a required architectural change, stop and update this plan in a separate reviewable change rather than quietly broadening scope.

## Initial out of scope

- Rewriting the frontend or radio engine from scratch.
- Removing Kubernetes manifests.
- Introducing a database or orchestration layer not required by the current stack.
- Destructive migration or automatic deletion of existing music and playlists.
- Unrelated feature work.

## Rollback strategy

Compose is introduced alongside Kubernetes, not as a replacement that deletes it. During the transition, existing Kubernetes users can continue using the current manifests. Roll back a Compose deployment by stopping the Compose services while preserving named volumes and bind-mounted data; do not run volume-pruning commands as part of routine rollback.
