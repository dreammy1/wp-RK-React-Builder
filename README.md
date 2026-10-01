# RK React Builder

A focused visual page builder for headless WordPress. An editor picks a WordPress page, arranges blocks (hero,
heading, text, image, CTA, live services/portfolio grids, spacer, divider), tweaks a global theme, previews, saves a
**draft**, and deliberately **publishes**. The public site is server-rendered from the same document model.

- **Builder** — Vite + React + TypeScript, registry-driven blocks, Zod validation, undo/redo, keyboard reorder, local
  draft recovery, revision history, media picker, conflict handling.
- **Server** — Node/Express: public SSR, session-protected WordPress proxy, cache, metrics, security headers.
- **Plugin** — `wp-plugin/rk-builder`: storage, REST, permissions, revisions, locks, CPTs, CORS, preview tokens.

Details: [ARCHITECTURE.md](ARCHITECTURE.md) · [SECURITY.md](SECURITY.md) · [DEPLOYMENT.md](DEPLOYMENT.md) ·
[OPERATIONS.md](OPERATIONS.md) · [PRODUCTION_IMPLEMENTATION_PLAN.md](PRODUCTION_IMPLEMENTATION_PLAN.md)

## Quick start (no WordPress needed)

```bash
nvm use            # Node 24 (.nvmrc); >=22.12 works
corepack enable    # pnpm 10.4.1 is pinned in package.json
pnpm install --frozen-lockfile
cp .env.example .env

pnpm dev:mock-wp   # terminal 1 — in-memory WordPress API on :8099 (user "editor", app password "mock-app-password")
pnpm dev           # terminal 2 — Node server :3001 (public site, API) + Vite :3000 (builder)
```

- Builder: <http://localhost:3000/builder> — password from `BUILDER_EDITOR_PASSWORD` in `.env`
  (`change-me-please-123` in `.env.example`).
- Public site: <http://localhost:3001/> (Home is published in the mock). Pages: `/about` is a draft → 404 until you publish.
- `?demo=1` (e.g. `/builder?page=42&demo=1`) shows an **explicit** offline content fixture; it is never a fallback.

## With real WordPress

1. Install `wp-plugin/rk-builder` (zip: `wp-plugin/rk-builder-wp-plugin.zip`) and activate it. It registers the
   `service` and `portfolio` post types, `service_cat`/`portfolio_cat`, and the REST API.
2. Create an **application password** for the editing user (Users → Profile → Application Passwords).
3. Set in `.env`: `WORDPRESS_PUBLIC_URL`, `WORDPRESS_API_URL` (`https://cms.example.com/wp-json/`),
   `WORDPRESS_APP_USER`, `WORDPRESS_APP_PASSWORD`, `BUILDER_EDITOR_PASSWORD`, `PUBLIC_SITE_URL`, `REVALIDATE_SECRET`.
4. In `wp-config.php` let WordPress purge the frontend and allow your origin:
   ```php
   define( 'RK_BUILDER_REVALIDATE_URL',    'https://www.example.com/api/revalidate' );
   define( 'RK_BUILDER_REVALIDATE_SECRET', '<same value as REVALIDATE_SECRET>' );
   define( 'RK_BUILDER_ALLOWED_ORIGINS',   'https://www.example.com' );   // only if a browser calls WP cross-origin
   ```

See [DEPLOYMENT.md](DEPLOYMENT.md) for the embedded wp-admin ("nonce") mode and production hardening.

## Using the builder

- **Pages** (`/builder`) — search/filter by title, slug, status; shows modified time and revision (and live revision).
- **Edit** — add from the palette (click, or drag), select, edit in the inspector, duplicate, delete (Undo toast),
  reorder by drag **or** the ↑/↓ buttons (fully keyboard-operable). `Ctrl/⌘+Z` undo, `Ctrl/⌘+Shift+Z` redo, `Ctrl/⌘+S` save.
- **Save draft** never publishes. Status text is explicit: _Draft saved to WordPress_ vs _Saved on this device only_.
- **Preview** (toggle) shows unsaved edits; **Preview link** opens the real frontend with a 15-minute draft token.
- **Publish / Update live page / Unpublish** — one dialog; saves first if needed.
- **History** — preview any revision; restore creates a new revision (nothing is deleted; last 20 kept).
- **Theme tab** — colors, font (3 approved), logo (media picker), social links, sticky header, footer columns.
  Administrators only.
- **Image block** — choose from the WordPress media library; alt text is required unless marked decorative.

## Commands

|                                                  |                                                                                                |
| ------------------------------------------------ | ---------------------------------------------------------------------------------------------- |
| `pnpm verify`                                    | typecheck + lint + format check + unit/contract tests + PHP tests + build                      |
| `pnpm check` · `pnpm lint` · `pnpm format:check` | static checks                                                                                  |
| `pnpm test` · `pnpm test:php`                    | Vitest (schemas, reducer, API client, SSR, proxy) · plugin tests (plain PHP)                   |
| `pnpm build && pnpm test:e2e`                    | Playwright against the mock WordPress (editing, publishing, a11y, recovery)                    |
| `pnpm test:wp`                                   | **Real WordPress**: client + proxy + plugin + SSR through WordPress Playground (needs network) |
| `pnpm test:wp-admin`                             | **Real WordPress wp-admin**: nonce mode in a browser (needs network)                           |
| `pnpm smoke:wp`                                  | Plugin REST smoke test against real WordPress                                                  |
| `pnpm audit`                                     | dependency audit (prod, high severity)                                                         |

First run of the Playground-based commands downloads WordPress and `@wp-playground/cli`; Playwright needs
`pnpm exec playwright install chromium` once.

## Troubleshooting

| Symptom                            | Fix                                                                                                |
| ---------------------------------- | -------------------------------------------------------------------------------------------------- |
| "Builder unavailable" on load      | The Node server isn't running or `/api/config` is blocked; check `pnpm dev` output                 |
| Sign-in says _not accepted_        | `BUILDER_EDITOR_PASSWORD` mismatch (restart the server after editing `.env`)                       |
| "Too many attempts"                | Login limiter: 5 per 15 min per IP (`LOGIN_RATE_LIMIT_MAX`)                                        |
| Saves fail with _Not permitted_    | The WordPress user lacks `edit_post`; theme saves need `manage_options`                            |
| Every page 404s publicly           | The page is not published; publishing sets `post_status=publish`                                   |
| Public page stale after publishing | Check `REVALIDATE_SECRET` / `RK_BUILDER_REVALIDATE_*`; otherwise TTL (60 s) applies                |
| Images missing in grids            | Set a featured image on the Service/Portfolio post; absolute image URLs must be on an allowed host |

More in [OPERATIONS.md](OPERATIONS.md).

## License

MIT
