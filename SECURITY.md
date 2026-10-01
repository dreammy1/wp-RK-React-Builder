# Security

Report vulnerabilities privately to the repository owner (do not open a public issue). Include reproduction steps
and the affected version (`RK_BUILDER_VERSION`, git SHA).

## Threat model in one paragraph

Editors are semi-trusted (they can publish content, not run code). Visitors and the open internet are untrusted. The
WordPress database is trusted but its contents are _re-validated on read_ (a compromised or corrupted row must not
become stored XSS or a crash). The builder bundle runs in editors' browsers and must never hold long-lived WordPress
credentials.

## Controls and where they are enforced

| Requirement                             | Enforcement                                                                                                                                                                                                                   | Verified by                                                   |
| --------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| Anonymous users cannot save             | Plugin `permission_callback` → `rk_unauthorized` 401; proxy requires a session                                                                                                                                                | PHP `RestAuthTest`, `app.test.tsx`, Playwright, real-WP smoke |
| Per-page permission                     | `edit_post` on the target page; non-`page` posts are 404                                                                                                                                                                      | PHP, smoke (subscriber → 403)                                 |
| Theme is admin-only                     | `manage_options`; layout saves cannot smuggle a changed theme                                                                                                                                                                 | PHP, smoke (editor 403 / admin 200), integration test         |
| Publishing needs `publish_post`         | publish/unpublish endpoints                                                                                                                                                                                                   | PHP                                                           |
| CSRF — proxy mode                       | `SameSite=Strict` cookie **and** `X-RK-CSRF` per-session token **and** same-site `Origin` check                                                                                                                               | `app.test.tsx`                                                |
| CSRF — nonce mode                       | Core `X-WP-Nonce`; cookie-only requests are treated as anonymous                                                                                                                                                              | wp-admin Playwright (no nonce / wrong nonce → 401/403)        |
| No long-lived credential in the browser | App password is only in the server environment; session cookie is `HttpOnly`; nothing credential-like in `localStorage`                                                                                                       | `app.test.tsx`, Playwright                                    |
| Login brute force                       | 5 attempts / 15 min / IP, constant-time password compare                                                                                                                                                                      | `app.test.tsx`                                                |
| CORS                                    | Exact-match allow-list (`RK_BUILDER_ALLOWED_ORIGINS`), never `*`, never reflects arbitrary/`null` origins, `Vary: Origin`                                                                                                     | PHP `CorsTest`, smoke                                         |
| Draft/private content never public      | `public/page` serves only the published snapshot of a `publish` page; 404 otherwise (existence not leaked); preview needs an HMAC token                                                                                       | PHP, smoke, Playwright                                        |
| Unsafe URLs                             | Only `/rel`, `#frag`, `http(s)`, `mailto`, `tel` (links) / relative or `http(s)` (images); `javascript:`, `data:`, `//host`, control chars, backslashes rejected in TS **and** PHP; absolute image hosts must be allow-listed | shared `contracts/invalid`, unit tests                        |
| Size limits                             | 256 KB body (plugin) / 300 KB (proxy), ≤100 blocks, per-field max lengths                                                                                                                                                     | PHP, `app.test.tsx`                                           |
| Unknown block/prop rejection            | Strict schemas; 400 with `issues[{path,message}]`, nothing silently dropped                                                                                                                                                   | contracts fixtures                                            |
| No arbitrary HTML/CSS                   | Text blocks are plain text rendered as text nodes; theme values are validated tokens turned into CSS custom properties only                                                                                                   | `render.test.tsx`, schema tests                               |
| Stored data re-validated                | Invalid stored layouts → 503 on the frontend / `invalid_response` in the editor, never rendered                                                                                                                               | `app.test.tsx`                                                |
| Revalidation endpoint                   | `X-RK-Revalidate-Secret`, constant-time compare, disabled without a secret                                                                                                                                                    | `app.test.tsx`, integration test                              |
| Metrics endpoint                        | Bearer token, 404 when unset                                                                                                                                                                                                  | `app.test.tsx`                                                |
| Secrets/tokens/nonces not logged        | Redacting JSON logger (`password                                                                                                                                                                                              | token                                                         | secret | authorization | cookie | nonce | dsn | csrf`); preview tokens/secrets never printed by plugin tests | `app.test.tsx`, PHP runner fails if a secret appears in output |
| Browser hardening                       | Strict CSP (nonce'd styles on public pages, `default-src 'none'`), `nosniff`, `frame-ancestors 'none'`, `Referrer-Policy`, `Permissions-Policy`, HSTS on https                                                                | `app.test.tsx`, Playwright                                    |
| Proxy cannot be used as an open relay   | Fixed allow-list of method+path regexes; only `rk/v1/...`; redirects refused; 15 s timeout                                                                                                                                    | `app.test.tsx`                                                |

## Known limits (be aware, not hidden)

- **Proxy mode uses one WordPress identity.** All editors who know the builder password act as the application-password
  user (revision "author" will show that user). Use _nonce_ mode if you need per-user attribution and capabilities.
- **Sessions are in memory** — a restart signs editors out; running several Node instances needs sticky sessions or a
  shared store (not implemented).
- **Rate limits are per process.**
- The plugin's save lock is an advisory `INSERT IGNORE` row (see `OPERATIONS.md`); it has not been load-tested on MySQL.
- `srcset` is length-limited but its URLs are not host-checked; the frontend only emits it on `<img>` under a CSP
  that restricts nothing beyond `img-src https:`.
- Fonts load from Google Fonts (CSP allows `fonts.googleapis.com`/`fonts.gstatic.com`); self-host them if that matters.
- Dependencies: run `pnpm audit` in CI (high severity gate); review lockfile diffs.

## Secrets inventory

`WORDPRESS_APP_PASSWORD`, `BUILDER_EDITOR_PASSWORD`, `REVALIDATE_SECRET` (= `RK_BUILDER_REVALIDATE_SECRET`),
`METRICS_TOKEN`, `SENTRY_DSN`, optional `RK_BUILDER_PREVIEW_SECRET`. Keep them in the host's secret store; rotate by
changing both sides (revalidate secret) or by revoking the application password in WordPress.
