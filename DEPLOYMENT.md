# Deployment

## Option A — All-in-one plugin (recommended; no Node, Docker or SSH)

Upload `rk-builder-all-in-one.zip` (Releases page, CI artifact, or `pnpm build:plugin`) via
_Plugins → Add New → Upload Plugin_, activate, follow the setup check. Full customer-facing steps, settings, migration,
update and uninstall behaviour: **[INSTALL.md](INSTALL.md)**. Staging checklist for this mode:

1. Install the ZIP on staging WordPress (PHP 8.1+, pretty permalinks); **Settings → RK Builder → Setup** shows no ✗.
2. Open _RK Builder_, edit a page, **Save draft**, reload — props intact; **Preview link** shows the draft, `noindex`, and 404s when altered.
3. **Publish** — the normal page URL shows the PHP-rendered layout (view source: `data-rk-block`, canonical, Open Graph, no editor JS). Draft saves do not change it.
4. With your cache plugin on, publish a change and confirm it appears immediately (or use _Purge public cache now_).
5. As an Editor, theme controls are disabled; as a Subscriber the RK Builder screen is refused.
6. Back up → update by uploading the next ZIP → confirm data intact → practise rollback with the previous ZIP.

Releases: bump `RK_BUILDER_VERSION` **and** the plugin header (the packager refuses a mismatch), merge, then push a tag
`vX.Y.Z`; the _Release plugin_ workflow runs `pnpm verify`, builds the ZIP and attaches it to a GitHub release.

## Option B — Headless: Node/Docker frontend

Use this only if you want the public site on a separate Node server (e.g. a different domain or framework-style
SSR). Everything below this heading describes that mode.

## Environments

| Environment          | WordPress                                         | Frontend (Node)                        | Notes                                         |
| -------------------- | ------------------------------------------------- | -------------------------------------- | --------------------------------------------- |
| Local                | your WP, or `pnpm dev:mock-wp`                    | `pnpm dev` (3001 + Vite 3000)          | `.env` from `.env.example`                    |
| Staging WordPress    | staging copy of prod DB + plugin build under test | —                                      | App password for a _staging-only_ user        |
| Staging frontend     | —                                                 | immutable build, `NODE_ENV=production` | Points at staging WP; run the checklist below |
| Production WordPress | plugin release N                                  | —                                      | Backup first (see below)                      |
| Production frontend  | —                                                 | immutable build behind TLS/CDN         | `PUBLIC_SITE_URL` must be https               |

## Configuration reference

Validated at startup (`server/env.ts`): the server refuses to start and lists every problem (without printing values).

| Variable                                        | Required      | Meaning                                                                             |
| ----------------------------------------------- | ------------- | ----------------------------------------------------------------------------------- |
| `WORDPRESS_PUBLIC_URL`                          | yes           | Public WP URL; used for the CSP image allow-list and the `/embed` CORS origin       |
| `WORDPRESS_API_URL`                             | yes           | REST root the server calls, ends with `/wp-json/` (can be an internal address)      |
| `WORDPRESS_FRONTEND_ORIGIN`                     | no            | Origin of the frontend as seen by WP; also accepted as an `Origin` on editor writes |
| `WORDPRESS_AUTH_MODE`                           | no (`proxy`)  | `proxy` or `nonce`                                                                  |
| `WORDPRESS_APP_USER` / `WORDPRESS_APP_PASSWORD` | proxy         | Application password credentials (server-side only)                                 |
| `BUILDER_EDITOR_PASSWORD`                       | proxy         | Builder login; ≥12 chars in production                                              |
| `PUBLIC_SITE_URL`                               | yes           | Canonical public origin; https in production                                        |
| `PUBLIC_HOME_SLUG` / `SITE_NAME`                | no            | Page served at `/`; site name for titles                                            |
| `PUBLIC_CACHE_TTL_SECONDS`                      | no (60)       | Public page cache freshness                                                         |
| `REVALIDATE_SECRET`                             | production    | Shared with WordPress `RK_BUILDER_REVALIDATE_SECRET`                                |
| `SENTRY_DSN`                                    | no            | Forwards server exceptions (Sentry envelope API; no SDK)                            |
| `ANALYTICS_ENDPOINT` + `ANALYTICS_WEBSITE_ID`   | no (together) | Injects the Umami script on public pages only                                       |
| `TELEMETRY_ENABLED`                             | no (true)     | Client error/vitals beacons                                                         |
| `METRICS_TOKEN`                                 | no            | Enables `GET /metrics` (Bearer)                                                     |
| `TRUST_PROXY`                                   | no            | `true` behind one reverse proxy so client IPs are correct for rate limiting         |
| `LOGIN_RATE_LIMIT_MAX`                          | no (5)        | Attempts per IP per 15 min                                                          |
| `PORT`                                          | no            | 3000 in production, 3001 in dev                                                     |

No placeholder like `%VITE_ANALYTICS_ENDPOINT%` ships: analytics is injected server-side only when both variables are set.

## WordPress plugin

1. Copy `wp-plugin/rk-builder/` to `wp-content/plugins/` (or upload `rk-builder-wp-plugin.zip`) and activate.
2. `wp-config.php` constants (all optional unless noted):

| Constant                                                                  | Purpose                                                                                                       |
| ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| `RK_BUILDER_ALLOWED_ORIGINS`                                              | CORS allow-list (array or comma string). Needed only if a _browser_ calls WP cross-origin; proxy mode doesn't |
| `RK_BUILDER_ALLOWED_IMAGE_HOSTS`                                          | Extra hosts permitted for absolute image URLs (site/home host always allowed)                                 |
| `RK_BUILDER_REVALIDATE_URL`, `RK_BUILDER_REVALIDATE_SECRET`               | Webhook to purge the frontend on publish/unpublish/theme                                                      |
| `RK_BUILDER_PREVIEW_SECRET`                                               | HMAC key for preview tokens (defaults to `wp_salt('auth')`)                                                   |
| `RK_BUILDER_PREVIEW_TTL`                                                  | Preview link lifetime in seconds (default 900)                                                                |
| `RK_BUILDER_MAX_REVISIONS`                                                | Revisions kept per page (default 20; filter `rk_builder_max_revisions`, 1–200)                                |
| `RK_BUILDER_APP_URL`, `RK_BUILDER_APP_CSS_URL`, `RK_BUILDER_FRONTEND_URL` | Embedded (nonce) mode, see below                                                                              |
| `RK_BUILDER_DELETE_DATA_ON_UNINSTALL`                                     | Opt in to deleting layouts when the plugin is deleted (default keeps them)                                    |

3. Application passwords require HTTPS on the WordPress site (or `WP_ENVIRONMENT_TYPE=local`).

### Embedded wp-admin ("nonce") mode

```php
define( 'RK_BUILDER_APP_URL',      'https://www.example.com/embed/rk-builder.js' );
define( 'RK_BUILDER_FRONTEND_URL', 'https://www.example.com' );   // where "Preview link" and publicSiteUrl point
```

Run the Node server with `WORDPRESS_AUTH_MODE=nonce` (public SSR + `/embed/*` only; no proxy, no login).
`wp-admin → RK Builder` opens a standalone full-page document (no wp-admin CSS collisions) that loads the bundle
cross-origin (the server answers `Access-Control-Allow-Origin: <WORDPRESS_PUBLIC_URL origin>` on `/embed/*`).
Verified end to end in `pnpm test:wp-admin`.

## Frontend build and run

```bash
pnpm install --frozen-lockfile
pnpm verify                    # check, lint, format, tests, PHP tests, build
NODE_ENV=production node --env-file=.env dist/index.js
```

Artifacts: `dist/index.js` (+map), `dist/assets/site.css`, `dist/public/**` (builder SPA + `embed/`). Build once, ship
the folder; **do not** rebuild on the server. Tag images/folders with the git SHA (immutable builds). Put a TLS-terminating
reverse proxy/CDN in front, set `TRUST_PROXY=true`, and let the CDN honour `Cache-Control` (`s-maxage` is emitted on
public pages; `/builder` and the API are `no-store`). `/healthz` is the liveness probe.

## CI

`.github/workflows/ci.yml` runs on every push/PR: clean install from the frozen lockfile → typecheck → lint → format
check → unit/contract tests → PHP tests (PHP 7.4 and 8.3) → build → audit → Playwright (mock WP, incl. axe) → real-WordPress
integration + wp-admin E2E (Playground). Node/pnpm are pinned (`.nvmrc`, `packageManager`).

## Staging checklist (run before every production release)

1. Deploy the plugin to staging WP; run `pnpm smoke:wp` locally and the plugin's PHP tests.
2. Deploy the frontend build; set `WORDPRESS_API_URL` to staging.
3. **CORS** — `curl -si -H 'Origin: https://evil.example' https://stg-cms/wp-json/rk/v1/theme-config` has no `Access-Control-Allow-Origin`; your real origin is echoed exactly.
4. **Auth** — anonymous `POST …/builder/layout/<id>` → 401; wrong CSRF → 403; subscriber → 403.
5. **Round trip** — open `/builder?page=<id>`, edit, _Save draft_, reload, confirm props; _Publish_; load the public URL.
6. **Cache/revalidation** — publish a change; public HTML updates within seconds without waiting for the TTL (webhook), and `rk_revalidate_total{result="ok"}` increments. Stop WP briefly: cached pages still serve (`X-RK-Stale: 1`).
7. **Preview link** opens, shows the draft, is `noindex`, and 404s after 15 minutes or with a tampered token.
8. Run Lighthouse on a public page; check Core Web Vitals appear in `/metrics`.

## Backup and restore (before every plugin deployment)

- **Back up** the WordPress database (`wp db export`) and `wp-content/uploads`. Layouts live in post meta
  (`_rk_layout_draft`, `_rk_layout_published`, `_rk_revision`, `_rk_published_revision`, `_rk_revisions`) and the theme
  in the option `rk_theme_config` — confirm they are in the dump:
  `wp db query "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key IN ('_rk_layout_draft','_rk_layout_published')"` and
  `wp option get rk_theme_config`.
- **Restore drill (do it once before launch, then quarterly):** restore the dump into a scratch WordPress, activate the
  plugin, open a page in the builder, confirm layout, theme and revisions load, and that the public page renders.

## Rollback

- **Plugin:** deactivate → replace with the previous release folder → activate. Data formats are forward-compatible
  within `version: 1`; the plugin re-validates on read and falls back to an empty layout rather than fatalling. If a
  release changed stored data, restore the pre-deploy backup.
- **Frontend:** redeploy the previous immutable image/folder (the server is stateless except in-memory cache and sessions).
  Purge the CDN. Editors will need to sign in again.
- **Content:** use _History → Restore_ in the builder to roll a single page back; restoring creates a new revision.

## Docker image and the Deploy workflow

`Dockerfile` builds the app in one stage and ships only production dependencies plus `dist/` in a non-root `node` image
(health check on `/healthz`; configuration only via environment variables). Build locally with
`docker build -t rk-builder . && docker run --env-file .env -p 3000:3000 rk-builder`.
**The Dockerfile and workflow have not been executed yet (no Docker in the authoring environment) — treat the first
staging run as their test.**

`.github/workflows/deploy.yml` is manual (_Actions → Deploy → Run workflow_, choose `staging` or `production` and a
ref). It refuses to deploy a commit without a green CI run, pushes `ghcr.io/<owner>/<repo>:<sha>`, SSHes to the host,
runs the container with the host-side env file, health-checks `/healthz`, and re-starts the previous image if the check fails.

Set up once per GitHub Environment (`staging`, `production`; add required reviewers on production):

| Kind     | Name                         | Value                                                                                                        |
| -------- | ---------------------------- | ------------------------------------------------------------------------------------------------------------ |
| secret   | `DEPLOY_HOST`, `DEPLOY_USER` | SSH target (Docker installed, `curl` available)                                                              |
| secret   | `DEPLOY_SSH_KEY`             | private key authorised for that user                                                                         |
| secret   | `DEPLOY_KNOWN_HOSTS`         | output of `ssh-keyscan <host>` (pins the host key)                                                           |
| variable | `DEPLOY_ENV_FILE`            | path on the host, e.g. `/etc/rk-builder/staging.env` (holds the variables from the table above; `chmod 600`) |
| variable | `DEPLOY_PUBLIC_URL`          | public https URL used for the final `/healthz` check                                                         |
| variable | `DEPLOY_HOST_PORT`           | optional, default `3000`; the container binds `127.0.0.1` only — put your TLS reverse proxy in front         |
