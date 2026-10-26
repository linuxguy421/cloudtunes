# CloudTunes Compose Credential Handling and Rotation

## Scope and status

This document describes the Compose secret-file implementation for Icecast and Ices. It does not rotate a running installation automatically. Test the branch in an isolated deployment and review the effective live configuration before adopting it on a live host.

The Compose setup uses four local secret files:

- `secrets/icecast_source_password`
- `secrets/ices_source_password` (must match the Icecast source password)
- `secrets/icecast_relay_password`
- `secrets/icecast_admin_password`

The secret directory is ignored by Git. These are file-backed Compose secrets, not an encrypted secret manager; protect the host, backups, and secret files accordingly.

## Initial setup

From the repository root:

```bash
bash scripts/init-secrets.sh
```

The script generates 64-character hexadecimal values without printing them, restricts directory/file permissions, and ensures the Ices and Icecast source secret files match. It does not overwrite existing values. Store a secure backup of the secret files before rotating or rebuilding the deployment.

Before starting the stack, confirm the files exist and are ignored by Git:

```bash
git status --short --ignored secrets
stat -c '%a %n' secrets secrets/*
docker compose config --quiet
```

Do not paste secret contents or resolved secret-bearing configuration into tickets, logs, or pull requests.

## Runtime behavior

The service entrypoints read the secret files and render effective configuration under `/run` at container startup. Icecast uses `/run/icecast.xml`; Ices uses `/run/ices-default0.xml`. The secret values are not written back into the persistent configuration volume by the renderer.

The renderer requires secret values containing 32-128 ASCII letters, digits, underscores, or hyphens. The initializer generates 64-character hexadecimal values. Compose secret mounts are read-only; local file-backed secrets remain plaintext on the host and must be protected.

The Supervisor control interface is switched from a network HTTP listener to a Unix-domain socket, intended for local in-container administration only.

## Isolated validation gate

Before a live rollout:

1. Build both images from the branch.
2. Initialize temporary secret files in a disposable checkout or isolated environment; never reuse production secrets for test credentials.
3. Run `docker compose config --quiet` and inspect the rendered service definitions without printing secret contents.
4. Start the isolated stack and verify both containers remain running.
5. Confirm the Icecast status endpoint reports the expected source mount and that continuous audio plays.
6. Verify Icecast admin authentication works with the new admin credential.
7. Verify the Supervisor HTTP port is no longer listening on the container network and `supervisorctl status` works through the Unix socket.
8. Confirm the persistent configuration volumes still contain no rendered replacement secret values.
9. Record test results without secret values.

This PR is not proof of a successful build or runtime test unless those tests are actually run.

## Live rotation and rollback

Do not rotate a live deployment until a maintenance window and a tested recovery path are available.

1. Back up the current effective Icecast/Ices configuration, all named volumes needed for recovery, and the current secret files to an access-controlled location.
2. Confirm which Compose project, volume names, image revisions, and config files the live containers use. Existing volumes may contain configuration that differs from the repository.
3. Generate new source, relay, and admin secrets in the protected secret directory. Keep the two source files identical. Do not commit them.
4. Build the new images before the maintenance window.
5. Apply the new Compose configuration and recreate Icecast and Ices together so both read the matching source credential. Expect a brief stream interruption.
6. Verify the source reconnects, the expected mount appears, and audio plays continuously.
7. Verify admin authentication with the new admin secret. Verify the old credential is rejected only in a controlled test that will not lock out the operator.
8. Check logs and listener behavior, then document the outcome without secret values.

If the source does not reconnect, stop the rollout. Restore the prior image/configuration and the matching prior secret files together; never combine an old Ices password with a new Icecast password. Verify playback before ending rollback.

## Credential history

Removing values from current files does not remove them from Git history, old images, backups, or existing volumes. Treat previously tracked credentials as compromised and rotate them. History rewriting is a separate coordinated task and does not replace credential rotation.
