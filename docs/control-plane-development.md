# Control-plane development stack

This is an isolated Laravel/PostgreSQL scaffold; it does not replace or extend the current radio stack.

## Safety

- Run `docker compose -f compose.control-plane.dev.yaml ...` by itself; do not combine it with `compose.yaml`.
- It uses dedicated volumes `cloudtunes-control-plane-dev-db-data` and `cloudtunes-control-plane-dev-storage`, and host ports 18080/15432.
- Credentials and APP_KEY in the Compose file are local-development-only. Never reuse them elsewhere.
- Do not use `docker compose down -v`; preserve the test database unless intentionally erasing it.
- This phase does not create station containers, access the Docker socket, upload media, or modify Icecast/Ices.

## Run

```sh
docker compose -f compose.control-plane.dev.yaml up --build -d
docker compose -f compose.control-plane.dev.yaml ps
curl --fail http://localhost:18080/up
curl --fail http://localhost:18080/
docker compose -f compose.control-plane.dev.yaml run --rm --no-deps control-plane php artisan test
docker compose -f compose.control-plane.dev.yaml down
```

The root endpoint returns status JSON, not a management UI. The migration service runs before the web and worker services. PostgreSQL is the source of truth for user/station/media metadata. User records have configurable station/storage quota values, but enforcement is not implemented yet. Media records store relative keys and metadata only; no uploads or file mutations exist. The runtime provider is an interface with a side-effect-free fake for tests; no real provider is bound. Invite issuance, UI, quota enforcement, media ingestion, lifecycle jobs, and real station provisioning remain later work.

A resolved `composer.lock` is not included yet. Generate and commit it after dependency resolution in a network-enabled environment before treating builds as reproducible or production-ready.
