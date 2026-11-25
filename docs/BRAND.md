# Brand and rename policy

The product is displayed as **smilir** (Javanese: *udara yang bergerak kecil*,
a small movement of air). The mark is the tapered S in `docs/brand/`.

This file records what was renamed in stage 1, what must not be renamed without
a migration, and the runbook for the remaining stages. Follow the order: a
display rename is safe at any time; repository, path, database and Docker
identity changes are not.

## Single source of truth

| What | Where |
| --- | --- |
| Default name / tagline | `PN_BRAND` / `PN_TAGLINE` in `app/bootstrap.php` |
| Per-install override | `ui.title`, `ui.tagline`, `ui.logo`, `ui.icon` in `app/config.php` |
| Accessors (never read the config directly in views) | `brand_name()`, `brand_tagline()`, `brand_logo()`, `brand_icon()` in `public/lib.php` |
| Docker defaults | `PIPNIX_UI_TITLE`, `PIPNIX_UI_TAGLINE`, `PIPNIX_UI_LOGO`, `PIPNIX_UI_ICON` in `docker/configure.php` |
| Mark / icons | `docs/brand/*.svg` → re-render the PNGs (see `docs/brand/README.md`) |

A rebrand is: replace the SVGs, re-render the PNGs, change `PN_BRAND` and/or
`ui.title`. No view edits.

## Stage 1 — display (done)

Renamed: UI, page titles, login, dashboard, favicon, logo, README/guides,
CLI headings, task-log prefix (`smilir: start/finished`), error-log prefix,
alert subject, updater User-Agent, Docker-generated `ui.*` defaults,
screenshots.

Deliberately unchanged, because changing them breaks live installs or needs a
migration:

| Kept | Why |
| --- | --- |
| `pn_` table prefix | schema + every query; needs `RENAME TABLE` |
| `PN_*` constants and runner env (`PN_DAG`, `PN_TASK`, ...) | documented contract for DAGs/jobs |
| `PIPNIX_*` env names | existing `.env` files and Docker stacks |
| session cookie `pipnix_sid` | renaming logs every user out |
| install paths `~/pipnix`, docroot `.../pipnix`, `ui.base_path=/pipnix` | cron, `.htaccess`, docs, bookmarks |
| `dist/pipnix-v*.zip` | release asset name/extract folder for the deployment profile |
| Docker project/image/volume names (`pipnix`, `pipnix_*`) | new project name = new empty volumes |
| MySQL advisory lock `pipnix` | two names = two schedulers = double runs |
| LICENSE copyright | MIT requires keeping the notice |

## Two profiles: utama and deployment

The remaining stages are split into two **install profiles** of the same code,
not two forks or two branches:

| | **Utama** (clean install) | **Deployment** (this cPanel) |
| --- | --- | --- |
| Source | `qoisoctava/smilir`, `main` | same repo, same commits |
| Archive | `make dist PREFIX=smilir` → `smilir-v*.zip` | `make dist` → `pipnix-v*.zip` |
| Install dir | `~/smilir` | `~/pipnix` (unchanged) |
| `ui.base_path` | `/smilir` (or empty at a subdomain root) | `/pipnix` (unchanged) |
| Database | new database | existing `octavamy_pipnix`, prefix `pn_` |
| Lock / cookie / env | `pipnix`, `pipnix_sid`, `PIPNIX_*` | identical (no migration) |
| Cron | new path | existing line (no change) |
| Updater | `qoisoctava/smilir` | `qoisoctava/smilir` |

Rules:

- **Never** run both profiles against the same database — two schedulers, two
  locks, double runs. Coexisting installs need separate databases and different
  `lock.name` / session names.
- Profiles differ only in configuration, archive prefix and deployment kit.
  Security fixes and features ship once and reach both.
- The deployment profile **never** runs stage 3 or 4: paths, database, lock,
  cookie and env stay exactly as they are.
- Stage 3/4 (below) are opt-in and only for a fresh utama install. If the
  internal names (`pn_`, `PN_*`, `PIPNIX_*`) are ever renamed, do it as a
  one-time `2.0` with a migration script — not as a permanent second branch.
- `make dist PREFIX=smilir` controls only the archive/root folder name; the
  extracted deployment zip keeps `pipnix/`, matching `~/pipnix`.

## Stage 2 — repository and release (done)

The repository was renamed to **`qoisoctava/smilir`** (GitHub redirects the
old `qoisoctava/pipnix` URL). The local remote, updater defaults, Docker env
default, docs and tests now point at the new slug.

Existing installations keep working through the redirect, but should be
updated once:

```bash
# config.php (or the Settings form / Admin → Updates)
'updater' => ['repo' => 'qoisoctava/smilir', ...],

# if the slug was saved in settings
mysql -e "UPDATE pn_setting SET v='qoisoctava/smilir' WHERE k='updater.repo';"
php app/ops.php update:check
```

Releases are cut from `main`:

```bash
git tag -a v1.0.0 -m "smilir 1.0.0" && git push origin v1.0.0
make dist                              # deployment (pipnix-v1.0.0.zip)
make dist PREFIX=smilir                # utama (smilir-v1.0.0.zip)
gh release create v1.0.0 dist/pipnix-v1.0.0.zip dist/smilir-v1.0.0.zip \
  --repo qoisoctava/smilir
```

Rollback: the old repository name still redirects; if needed, set
`updater.repo` back to `qoisoctava/pipnix`.

## Stage 3 — install path and `base_path` (utama only)

Pre-flight: choose the names (`~/smilir`, `/smilir`). Keep the lock name
`pipnix` for the whole transition — a different name would let the old and new
cron ticks run at the same time.

1. Copy the install to the new directory; leave the old one untouched.
   Keep `app/config.php`, `dags/`, `jobs/`, `logs/`, `storage/`.
2. Set the new `ui.base_path` (`/smilir`) and, in the webroot, copy `public/`
   into the new folder. Add the new rewrite block to the root `.htaccess`
   and keep the old block as a `301` to the new prefix.
3. Add the new cron line (new path), run it once by hand, confirm
   `storage/heartbeat` updates and `php app/ops.php dags` is healthy.
4. Remove the old cron line, then run one tick from the new directory:
   `php app/scheduler.php`. This refreshes the absolute `pn_dag.source_path`
   values that still point at the old folder.
5. Only now rename the session cookie (optional). It logs everyone out once;
   do it after the redirect works so there is no half-migrated window.
6. Update `toolbox-deployment/` (outside this repo) and any bookmark/alert
   scripts. Keep the old folder for a week, then delete it.

Rollback: old folder and old cron are still intact until step 4.

## Stage 4 — database and Docker identity (utama only)

Only when stage 1–3 are stable.

- **Table prefix `pn_`**: `RENAME TABLE` all tables in one transaction, update
  every query, and add a schema migration keyed by version. Never run the old
  and new versions against the same database.
- **`PN_*` runner env**: for one release, set both old and new names so
  existing DAG/job code keeps working; document the deprecation; then drop the
  old names in the next minor release.
- **`PIPNIX_*` env**: accept both prefixes in `docker/configure.php` and
  `entrypoint.sh` (new wins), update `.env.example`, keep old keys working for
  one release.
- **Docker project/volumes**: renaming the compose project creates new empty
  volumes. Either keep `name: pipnix`, or migrate with
  `docker run --rm -v pipnix_pipnix_venvs:/from -v smilir_smilir_venvs:/to ...`,
  and stop the old stack first.
- **Release assets**: rename `dist/pipnix-v*.zip` and the archive prefix in
  `Makefile` together with the updater's archive handling.

## Checklist for any future rename

- [ ] `grep -ri` the old name: views, config, Docker, tests, docs, deployment kit
- [ ] brand helpers used everywhere user-facing (no literal name in views)
- [ ] SVGs + PNGs re-rendered at 32/180/256
- [ ] screenshots re-rendered
- [ ] `make check`, `make ui`, `make ui-actions`, `make e2e`
- [ ] login page shows the new name (ui smoke asserts this)
- [ ] no path/DB/session/lock change without the matching stage runbook above
