# Updating smilir

The console can update itself from GitHub: **Admin → Updates**.

It downloads a source archive, backs up the files it replaces, installs them
around your data, and runs the idempotent schema migrations. No git, shell or
root access is needed on the host.

## Channels

| Channel | Source | When to use |
| --- | --- | --- |
| `release` | newest GitHub **release tag** | production — published, stable versions |
| `branch` | newest commit of a branch (default `main`) | before the first release, or to track development |

With the release channel the version strings are compared (`v1.2.0` >
`v1.1.0`). With the branch channel the commits are compared through GitHub's
`compare` API; a checkout that is ahead of GitHub, or has commits GitHub does
not know, is **not** offered a downgrade.

Set the channel in **Admin → Settings → Updates**, or in `app/config.php`:

```php
'updater' => [
    'enabled'      => true,
    'repo'         => 'qoisoctava/smilir',   // owner/name
    'channel'      => 'branch',              // or 'release'
    'branch'       => 'main',
    'token'        => null,                  // read-only token for private forks
    'allow_docker' => false,
],
```

## Using the console

1. **Admin → Updates → Check now.** You get the installed version/commit, the
   remote version/commit, the commit title (and release notes for tags).
2. **Update now** — files are copied to `storage/updates/backup-<timestamp>/`
   first, then replaced one by one (temp file + `rename`), then
   `php app/install.php` runs.
3. The page refreshes while the background job runs and shows the log at the
   bottom.
4. **Roll back files** restores the newest backup. Database changes are not
   reverted — rerun the update (or a newer one) afterwards.

**Upload an archive** does the same with a zip/tar.gz you provide; useful when
the host cannot reach GitHub. `make dist` output works.

### CLI

```bash
php app/ops.php update:check
php app/ops.php update:apply                 # download + apply + migrate
php app/ops.php update:apply --archive=/path/to/pipnix.zip
php app/ops.php update:rollback
```

## What an update never touches

- `app/config.php` and `.env` (secrets stay local)
- `dags/` and `jobs/` (your pipelines)
- `logs/` and `storage/` (history, uploads, updater state)
- `.git/`, `dist/`, `venvs/`

Everything else is replaced with the archive's version: `app/`, `public/`,
`lib/`, `schema.sql`, docs, examples, tests, Docker files.

The DAG hash cache is invalidated after an update, so new normalisation rules
reach modules whose source did not change.

### cPanel split webroot warning

The standard [cPanel deployment](/docs/cpanel) copies the contents of
`~/pipnix/public/` to `~/public_html/pipnix/`. The built-in updater applies
archives to the private application tree; it does **not yet mirror** updated
`public/` files to that separate webroot. The PHP backend can update while the
live front controller/assets stay old. After an updater run, manually copy the
updated public files into `public_html/pipnix/`, or use an installation whose
document root/symlink points directly at `~/pipnix/public/`.

Until split-webroot synchronization is implemented, for the standard cPanel
layout prefer a release-zip update: back up first, update the private source,
run `php app/install.php`, then copy the updated `public/` contents to
`public_html/pipnix/`.

## Permissions

The PHP user must be able to write `app/`, `public/` and the files inside them.
On cPanel PHP usually runs as your account, so this works out of the box. If
the console reports *"… is not writable by the web server"*, fix ownership:

```bash
chmod -R u+rwX ~/pipnix/app ~/pipnix/public ~/pipnix/lib
```

The updater refuses to run rather than half-copying a tree.

## Security

- Admin role + CSRF token + explicit confirmation.
- HTTPS to `api.github.com` and `codeload.github.com`, certificate verification
  on, redirects limited, 50 MB archive cap.
- Private forks: create a **read-only** fine-grained token and put it in
  `app/config.php` (`updater.token`); the console never displays it.
- The archive is code — treat an update exactly like a `git pull` from a
  trusted repository. Pin `repo` to your own fork if you do not want upstream
  changes.

## Docker

GitHub checks remain available inside Docker. Applying/rolling back files is
disabled by default (`updater.allow_docker` is false), because changes to the
image layer are lost on the next rebuild. Update the image instead:

```bash
docker compose build --pull && docker compose up -d
```

The entrypoint re-runs `install.php`, so the schema follows automatically. If
you really want in-container updates, set `PIPNIX_UPDATE_ALLOW_DOCKER=1` and
keep the `dags/`/`jobs/` bind mounts.

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `GitHub API 403` | rate limit; set `updater.token` or wait an hour |
| `GitHub API 404` | wrong `owner/name`, branch, or no release published |
| `No GitHub release yet` | publish a tag (`git tag v1.0.0 && git push --tags`) or use the branch channel |
| "local commit is not on GitHub" | unpublished changes in a git checkout — push first, or update from an archive |
| `not writable by the web server` | see **Permissions** above |
| Update ran but pages look old | hard-reload (CSS/JS from `public/assets`) |
| Something broke | **Roll back files**, then report the log at `storage/updates/update.log` |
