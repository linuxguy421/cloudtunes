# CloudTunes web MVP — Phase 1

## Scope

This phase adds a server-rendered Laravel interface to the control plane:

- Session-based sign-in, sign-out, and public self-registration with CSRF-protected forms.
- Public registration at `/register`, linked from the sign-in page and navigation when enabled.
- Registration creates a regular user with pending approval; it never grants administrator privileges or automatically signs the user in.
- An operator manually promotes the initial administrator with `cloudtunes:make-admin`.
- Authenticated dashboard with account approval, station count, configured station limit, and recent stations.
- Authenticated station listing, creation, editing, and archival.
- Per-user station isolation and administrator visibility across station owners.
- Station ownership is enforced by the station policy on view, update, and archive operations.
- Station archival uses Laravel soft deletes. Configuration, media references, and lifecycle history remain in PostgreSQL.
- Running or provisioning stations, and stations whose desired state is running, cannot be archived.
- Archived slugs and mount paths remain reserved to avoid identity collisions.
- New station records preserve the existing random playback mode and draft/stopped state.

Public registration is enabled by default through `CLOUDTUNES_PUBLIC_REGISTRATION_ENABLED=true`. Operators can disable it by setting the variable to `false`.

## Editing and archival behavior

Station editing currently changes the display name only. The slug and Icecast mount path remain stable because changing either can invalidate existing player URLs and requires a separate runtime migration operation.

Archival sets deleted_at; it does not physically delete the station or its related media and lifecycle history. Normal Eloquent queries automatically exclude archived stations. A future administrative restore/permanent-retention workflow can be added separately.

## Explicit non-goals

- No invitation issuance or email delivery workflow yet.
- No uploads or media file changes.
- No start/stop controls or claims that a stream is running.
- No real station provisioning or runtime reconciliation yet.

## Development and test

Run the isolated control-plane stack only:

```sh
docker compose -f compose.control-plane.dev.yaml up --build -d
docker compose -f compose.control-plane.dev.yaml run --rm --no-deps control-plane php artisan test
docker compose -f compose.control-plane.dev.yaml down
```

Do not combine this file with compose.yaml, and do not use docker compose down -v.

Browser forms use Laravel's web middleware, session cookies, and CSRF tokens. Registration is rate-limited and validates a unique email address plus a confirmed password of at least 12 characters.
