# Running smilir with Docker

smilir is a plain PHP + MySQL + Python application, so a container is just a
different host: the same code, the same `dags/` modules, the same console. One
container carries the web server and the scheduler; a second runs MySQL.

```
┌──────────────────────────── app container ────────────────────────────┐
│  Apache :80 ──► public/index.php ──► app/ (private, outside webroot)  │
│  supercronic ──► php app/scheduler.php   every minute                 │
│  python3 + /venvs/default                                              │
└───────────────────────────────────────────────────────────────────────┘
                                   │
                          ┌────────▼────────┐
                          │  mysql:8.0      │
                          └─────────────────┘
```

- Schedule: `supercronic` inside the container — **no host cron needed**.
- State: MySQL (runs, tasks, venvs, users) + `logs/`, `storage/` volumes.
- Pipelines: `./dags` and `./jobs`, bind-mounted from the repository.
- Works on `amd64` and `arm64` (Apple Silicon, ARM servers).

## Quick start

```bash
cp .env.example .env        # set database/admin secrets and the port
docker compose up -d --build
```

Open <http://localhost:8080> and sign in with **`admin` / `admin`**. That is a
weak password on purpose (the usual convention), so the console asks for a new
one immediately — the stored hash is replaced before any other page opens.

If you prefer to start with a different temporary password, set
`PIPNIX_ADMIN_PASS` in `.env` before the first start; a strong one skips the
forced change. Changing that variable later does not reset an existing account
(use `docker compose exec -T -u www-data app php app/install.php --admin=you:secret`
for a reset).

```
docker compose ps          # both services should be "healthy"
docker compose logs -f app # Apache access log + one line per scheduler tick
```

## What persists

| Path | How | Contains |
| --- | --- | --- |
| `./dags`, `./jobs` | bind mount | your DAG modules and Python functions (edit on the host or in the console) |
| `/var/www/pipnix/logs` | volume `pipnix_logs` | per-attempt task logs, `cron.log` if you add one |
| `/var/www/pipnix/storage` | volume `pipnix_storage` | DAG hash cache and runner scratch files |
| `/venvs` | volume `pipnix_venvs` | Python virtualenvs |
| MySQL data | volume `db_data` | all run history |

`docker compose down` keeps every volume; `docker compose down -v` deletes
them (including run history). Rebuilding the image never touches them.

### Backup

```bash
docker compose exec -T db sh -c \
  'mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" --single-transaction pipnix' \
  > pipnix-$(date +%F).sql
docker run --rm -v pipnix_pipnix_logs:/data -v "$PWD":/backup alpine \
  tar czf /backup/pipnix-logs.tgz -C /data .
```

### Restore

```bash
docker compose exec -T db sh -c \
  'mysql -u root -p"$MYSQL_ROOT_PASSWORD" pipnix' < pipnix-2026-01-01.sql
```

## Pipelines

DAG authoring is identical to the cPanel deployment — see
[`DAGS.md`](DAGS.md).

- **Edit on the host**: files in `./dags` are visible in the container
  immediately; the next tick re-executes a module only when its sha256
  changes.
- **Edit in the console**: Dags → *New DAG* writes to the same bind mount.
- **Validate**:

  ```bash
  docker compose exec -T -u www-data app php app/dagcheck.php --file=dags/my_dag.py
  ```

- **Inspect from the CLI**:

  ```bash
  docker compose exec -T -u www-data app php app/ops.php dags
  docker compose exec -T -u www-data app php app/ops.php runs --dag=my_dag
  docker compose exec -T -u www-data app php app/ops.php logs --run=12 --tail=80
  ```

The `-u www-data` matters: it keeps new files owned by the same user Apache
runs as.

## Python and packages

The image ships Python 3 (Debian's `python3`) and a default venv at
`/venvs/default`, registered in the console as **default**. `func` tasks and
`python` commands use it unless the DAG pins another one.

```bash
# install into the default venv, then refresh the inventory in the DB
docker compose exec -T -u www-data app /venvs/default/bin/pip install pandas
docker compose exec -T -u www-data app php app/ops.php venv:check --name=default
```

Or use **Python → package manager** in the console. Extra venvs can be
created inside the volume and registered in the same page:

```bash
docker compose exec -T app python3 -m venv /venvs/reporting
docker compose exec -T app /venvs/reporting/bin/pip install -r requirements.txt
# then: Python → Add environment → name "reporting", path "/venvs/reporting"
```

`/venvs` is a named volume, so packages survive `docker compose up --build`.

## Schedules and time

- The tick runs every minute from `docker/supercronic.crontab`; supercronic
  logs `job started` / `job succeeded` to the container log.
- `PIPNIX_TIMEZONE` sets both PHP and MySQL (`TZ`). Cron expressions in DAGs
  are evaluated in that timezone.
- The MySQL advisory lock (`GET_LOCK`) means an overlapping or manually
  cleared tick can never double-run a task.
- Weekly housekeeping (`ops.php prune --days=30`) is also in the crontab;
  remove that line if you prune differently.

## Upgrades

```bash
git pull
docker compose build --pull
docker compose up -d
```

The entrypoint re-applies `install.php` (idempotent schema + migrations),
keeps the existing admin account and leaves your volumes alone. For a release
zip, unpack it over the repository and rebuild.

The in-app updater (**Admin → Updates**) can still **check GitHub** from the
container. Applying an archive or rolling back files is disabled by default:
changes to the image layer would be lost on the next rebuild. For a durable
update, use `docker compose build --pull && docker compose up -d`. Set
`PIPNIX_UPDATE_ALLOW_DOCKER=1` only if you understand that trade-off.

## Configuration

Everything is read from environment variables on each boot; the entrypoint
writes `app/config.php` (mode `0640`, owned by `www-data`). Do not edit that
file inside the container — change `.env` and recreate the container.

| Variable | Default | Meaning |
| --- | --- | --- |
| `PIPNIX_PORT` | `8080` | host port for the console |
| `PIPNIX_TIMEZONE` | `UTC` | PHP/MySQL/cron timezone |
| `PIPNIX_DB_NAME` / `PIPNIX_DB_USER` / `PIPNIX_DB_PASS` | `pipnix` | application database |
| `PIPNIX_DB_ROOT_PASS` | `pipnix-root` | MySQL root password (compose only) |
| `PIPNIX_ADMIN_USER` | `admin` | first admin username |
| `PIPNIX_ADMIN_PASS` | `admin` | first admin password; weak values force a change at first sign-in |
| `PIPNIX_ALERTS_ENABLED` | `0` | enable `mail()` alerts |
| `PIPNIX_ALERTS_FROM` / `PIPNIX_ALERTS_TO` | — | alert sender / comma-separated recipients |
| `PIPNIX_MAX_LAUNCHES` | `4` | task launches per tick (`tick.max_launches_per_tick`) |
| `PIPNIX_STALE_GRACE` | `15` | seconds before a silent runner is marked failed |
| `PIPNIX_LOCK_TIMEOUT` | `50` | MySQL lock wait per tick |
| `PIPNIX_UID` / `PIPNIX_GID` | — | remap `www-data` to your host user (Linux bind mounts) |
| `PIPNIX_DB_WAIT` | `90` | seconds the entrypoint waits for MySQL |
| `PIPNIX_FORCE_CONFIG` | `0` | `1` = overwrite a bind-mounted `app/config.php` |

To use a hand-written config instead of the generated one, uncomment the
`./app/config.php` bind mount in `docker-compose.yml`.

## Linux file ownership

On macOS and Windows, bind mounts are translated automatically. On Linux,
files written by the web editor land as UID/GID 33 (`www-data`). Either let
the entrypoint match your user:

```bash
echo "PIPNIX_UID=$(id -u)" >> .env
echo "PIPNIX_GID=$(id -g)" >> .env
docker compose up -d
```

or make the folders group-writable once: `sudo chown -R 33:33 dags jobs`.

## Reverse proxy and TLS

Terminate TLS in front of the container (Caddy, Traefik, nginx, or a cloud
load balancer) and proxy to `127.0.0.1:8080`. The console sets the session
cookie's `Secure` flag automatically when the request arrives over HTTPS
(`X-Forwarded-Proto` handled by your proxy is enough if it forwards `HTTPS`).

Never expose port 8080 to the internet without TLS: the console can edit and
trigger code.

## Email alerts

`Alerter` uses PHP `mail()`, and a bare container has no MTA. Options:

1. leave alerts disabled (default);
2. install an SMTP relay such as `msmtp` in a derived image and point
   `sendmail_path` at it;
3. call an external API from your job code instead.

If you set `PIPNIX_ALERTS_ENABLED=1` without (2), failures are still recorded
in the console and logs — only the email is lost.

## Operations

```bash
docker compose exec -T -u www-data app php app/scheduler.php   # one manual tick
docker compose exec -T -u www-data app php app/install.php     # re-run migrations
docker compose exec -T app php -r \
  'require "app/bootstrap.php"; Db::pdo()->query("SELECT 1");'  # DB check
```

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| Container restarts in a loop | `docker compose logs app` — usually wrong DB password in `.env` |
| `database: not reachable after 90s` | `db` is still initializing; raise `PIPNIX_DB_WAIT` |
| Login rejected | wrong password; the first boot account is `admin:admin` and must be changed on first sign-in |
| Console asks to set a new password | the account was created with a weak `PIPNIX_ADMIN_PASS` — intended |
| 500 on every page | `docker compose exec app php -l` the file, or check `docker compose logs app` |
| DAG never runs | Dags → check **Active**, then Health → Scheduler; inspect `docker compose logs app` for tick lines |
| Permission denied writing `dags/` | see [Linux file ownership](#linux-file-ownership) |
| Port already in use | change `PIPNIX_PORT` in `.env` |
| `docker compose down -v` lost history | expected — that removes volumes; back up first |

## Differences from the cPanel deployment

- No `.htaccess`-only assumptions: Apache enables `AllowOverride`, so the
  shipped file still governs rewrites and headers.
- `app/config.php` is generated from the environment instead of being copied
  by hand.
- Schema migrations run on every boot, so upgrades need no manual `install.php`.
- The `prune` cron entry ships enabled.
