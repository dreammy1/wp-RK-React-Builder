# Operations

## What is observable

Structured JSON logs on stdout (one object per line; credentials, cookies, nonces, tokens and layout bodies are never
logged), Prometheus text on `GET /metrics` (Bearer `METRICS_TOKEN`), optional Sentry forwarding, optional Umami analytics.

| Question                        | Where to look                                                                                                                         |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| Builder load failures           | `rk_client_events_total{type="load_failure"}`; log event `client_load_failure` (`detail`: `boot:…` / `page:<kind>`)                   |
| Save failures **by error code** | `rk_wp_requests_total{route="builder/layout/:id",status=…,code=…}` — e.g. `rk_invalid_layout`, `rk_forbidden`, `rk_payload_too_large` |
| Authentication failures         | `rk_auth_failures_total{reason="no_session\|csrf\|bad_password\|rate_limited"}`; log `login_failed`                                   |
| Revision conflicts              | `rk_wp_requests_total{status="409",code="rk_revision_conflict"}`                                                                      |
| REST latency                    | `rk_wp_request_ms_sum / _count` per route; log field `ms`                                                                             |
| Public rendering errors         | `rk_public_render_total{status=404\|503\|500}`; log `public_fetch_failed`; Sentry `exception`                                         |
| Missing images                  | `rk_client_events_total{type="image_error"}`; log `client_image_error` (URL in `detail`)                                              |
| JavaScript errors               | `rk_client_events_total{type="js_error"}`; log `client_js_error`                                                                      |
| Core Web Vitals                 | `rk_web_vitals_lcp_ms_sum/_count`, `rk_web_vitals_cls_sum/_count` (public pages, via `/rum.js`)                                       |
| Revalidation health             | `rk_revalidate_total{result="ok\|denied"}`                                                                                            |

Suggested alerts: 503 ratio > 1 % for 5 min; `rk_auth_failures_total{reason="bad_password"}` spike; sustained
`code="unreachable"` on the proxy; `rk_revalidate_total{result="denied"}` > 0 (secret mismatch).

## All-in-one plugin operations

- **Settings → RK Builder** holds configuration (rendering mode, image hosts, preview lifetime, revisions kept, default grid size,
  cache purging, uninstall behaviour). Constants in `wp-config.php` and filters keep priority over stored settings.
- **Diagnostics** shows plugin/PHP/WordPress versions, REST status, permalinks, last migration and the last public render error
  (time, page id, code — no content). **Export diagnostics** produces JSON with no secrets.
- **Logs:** `rk_builder_log()` writes safe metadata via `error_log`, gated by the `rk_builder_log_level` filter (default `warning`);
  nonces, tokens, passwords and layout bodies are never logged.
- **Cache:** publish/unpublish/restore/theme/Service/Portfolio/media changes purge public caches (WP Super Cache, W3 Total Cache,
  LiteSpeed, WP Rocket, `clean_post_cache`, and the `rk_builder_public_cache_purge` action for hosts/CDNs). Use _Purge public cache
  now_ after manual DB edits. Output is correct with no cache plugin; purge failures never block a save.
- **Backups:** layouts live in post meta, revisions in `_rk_revisions`, theme and settings in options — a standard WordPress
  database backup covers everything; media are in `uploads`.

## Caching behaviour (headless Node server)

Public page data is cached in-process for `PUBLIC_CACHE_TTL_SECONDS` and may be served **stale for up to 1 h if
WordPress is failing** (`X-RK-Stale: 1`). It is purged by (a) the proxy after publish/unpublish/restore/theme, (b)
the WordPress webhook, (c) restarting the process. 404s and previews are never cached. Edits to _Service/Portfolio posts_
do not fire the webhook (not in scope for v1): grids update when the TTL expires.

## Revisions

Each save/publish/restore/unpublish appends a record to `_rk_revisions` (a single JSON meta row, newest first, last 20 kept;
`rk_builder_max_revisions` filter or `RK_BUILDER_MAX_REVISIONS`, 1–200). Numbers are monotonic. A 20-revision page with
large layouts can reach a few MB in one meta row; lower the cap if that matters.

## Known limits

- The save/publish/restore critical section uses an atomic `INSERT IGNORE` into `wp_options` as a lock (30 s stale
  takeover). Verified on SQLite (Playground) only; for heavy concurrent editing on MySQL swap `rk_builder_lock()` for
  `GET_LOCK()`.
- In-memory sessions, rate limits and cache are per process.
- Layouts stored by the _prototype_ (`_rk_layout` meta, flat blocks) are not auto-imported; the client migrates the
  flat shape if it ever receives it, but the old meta key is not read.
- Not covered by tests: PHP 7.4 execution (source is 7.4-syntax only; CI runs it on 7.4), browser CORS preflight in a real
  browser, MySQL concurrency.

## Troubleshooting

| Problem                                                        | Diagnosis                                                                                                            |
| -------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| Server exits at start with "Invalid environment configuration" | Fix every listed variable; values are never printed                                                                  |
| Editor shows "Could not reach the server"                      | Proxy 504: check `WORDPRESS_API_URL` reachability from the _server_; see `wp_proxy_unreachable` log                  |
| Saves return 401 after working earlier                         | Session expired (8 h) or server restarted → sign in again; unsaved work is in the local draft                        |
| Saves return 403                                               | Missing capability or CSRF mismatch (check `rk_auth_failures_total{reason="csrf"}`; stale tab after login elsewhere) |
| 409 on every save                                              | Another editor/tab saved; use _rebase_ in the conflict dialog; `currentRevision` is in the error                     |
| 400 `rk_invalid_layout`                                        | Response has `issues[{path,message}]`; reproduce with `contracts/` fixtures; check allowed image hosts               |
| Public page 503 "Stored layout … failed validation"            | Layout in WP no longer validates (manual DB edit / older data): restore a revision or re-save                        |
| Preview link 404                                               | Token expired (15 min) or tampered; generate a new one                                                               |
| wp-admin screen blank                                          | `RK_BUILDER_APP_URL` unset or blocked; check the browser console/network for the module script                       |
