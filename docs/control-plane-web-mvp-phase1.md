# CloudTunes web MVP — Phase 1

## Scope

This phase adds a server-rendered Laravel interface to the isolated control plane:

- Session-based sign-in and sign-out with CSRF-protected forms.
- Authenticated dashboard with account approval, station count, configured station limit, and recent stations.
- Authenticated station listing and station creation form.
- Per-user station isolation and existing approval policy.
- No public registration page; the sign-in page explains that access is invite-only.
- New station records preserve the existing random playback mode and draft/stopped state.

## Explicit non-goals

- No invitation issuance or email delivery workflow yet.
- No uploads or media file changes.
- No start/stop controls or claims that a stream is running.
- No changes to compose.yaml, the PHP/Nginx radio player, Icecast, Ices, or live music volumes.
- No changes to the live streaming stack's runtime.

## Development and test

Run the isolated control-plane stack only:

\`\`\`sh
docker compose -f compose.control-plane.dev.yaml up --build -d
docker compose -f compose.control-plane.dev.yaml run --rm --no-deps control-plane php artisan test
docker compose -f compose.control-plane.dev.yaml down
\`\`\`

Do not combine this file with compose.yaml, and do not use docker compose down -v.

The UI is served by the existing control-plane service on port 18080. Browser forms use Laravel's web middleware and CSRF tokens. The login page does not expose self-registration; invitation issuance remains a later phase.
