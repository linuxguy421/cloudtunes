# CloudTunes control-plane database backups

These procedures target only `compose.control-plane.dev.yaml`. It is a development
configuration with development credentials, a placeholder application key, and debug
mode. Do not use it as a production deployment configuration.

## Backup policy

Create a compressed PostgreSQL logical backup daily, retain 14 days, and store copies
outside the database container and database volume. Prefer a separate disk or remote
backup destination. Database dumps contain application data and must have restricted
permissions.

## Manual backup

From the repository root:

```sh
bash scripts/backup-control-plane-db.sh
```

The default destination is `backups/control-plane/`, which is excluded from Git.
The script checks that the running database container uses the expected named volume
before running `pg_dump`. It validates the gzip archive and applies retention.

Override the destination and retention as needed:

```sh
BACKUP_DIR=/var/backups/cloudtunes/control-plane RETENTION_DAYS=30 \
  ./scripts/backup-control-plane-db.sh
```

This backup covers the PostgreSQL database only. It does not include music files,
Icecast configuration, or other Docker volumes.

## Daily systemd timer

The unit templates are in `deploy/systemd/`. Edit the service template for the host:
set the correct `User`, `Group`, `WorkingDirectory`, `ExecStart`, and `BACKUP_DIR`.
Use a persistent backup destination outside the repository.

Install both unit files under `/etc/systemd/system/`, then run:

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now cloudtunes-control-plane-backup.timer
systemctl list-timers cloudtunes-control-plane-backup.timer
```

The timer is scheduled daily at 03:15 local system time, with a randomized delay of up to ten minutes. It catches up after downtime.

## Backup-first updates

After the database service is already running, use:

```sh
./scripts/update-control-plane-dev.sh
```

The wrapper requires a successful backup before Compose runs the application update.
The existing migration dependency runs before the control-plane and worker start.

For first-time startup, use the isolated development Compose procedure. Do not combine
this file with the radio stack's `compose.yaml`.

## Recovery testing

Periodically restore a selected backup into a separate scratch database. Verify that
the expected Laravel tables and representative records are present. Keep the active
application database separate throughout the test.

This is a database-only recovery measure. A production deployment will also need
off-host backup storage, a separate application-storage backup policy, and a documented
recovery procedure.
