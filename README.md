# RK React Builder

A focused visual page builder for WordPress, shipped as **one installable plugin** (no Node, Docker or SSH needed), with an
optional headless mode (Node/Docker frontend) for teams that want it.

> **Just want to install it?** Read [INSTALL.md](INSTALL.md): upload the ZIP in _Plugins → Add New → Upload_, activate, done.

A focused visual page builder for WordPress. An editor picks a WordPress page, arranges blocks (hero,
heading, text, image, CTA, live services/portfolio grids, spacer, divider), tweaks a global theme, previews, saves a
**draft**, and deliberately **publishes**. The public site is server-rendered from the same document model.

- **Builder** — Vite + React + TypeScript, registry-driven blocks, Zod validation, undo/redo, keyboard reorder, local
  draft recovery, revision history, media picker, conflict handling.
- **Plugin** — `wp-plugin/rk-builder`: storage, REST, permissions, revisions, locks, CPTs, preview links, **PHP public
  rendering**, SEO metadata, cache purging, settings, setup wizard and migration tool. The built React editor ships
  inside it (`assets/`) and runs in wp-admin on the WordPress login cookie + REST nonce.
- **Media upload & site export/import** (WordPress-hosted editor) — upload images straight from the media picker; administrators
  can export every builder page, the theme and the media they use as one JSON file and import it on another site
  (see [Site export / import](#site-export--import)).
- **Server (optional, headless mode)** — Node/Express: public SSR, session-protected WordPress proxy, cache, metrics.

Details: [ARCHITECTURE.md](ARCHITECTURE.md) · [SECURITY.md](SECURITY.md) · [DEPLOYMENT.md](DEPLOYMENT.md) ·
[OPERATIONS.md](OPERATIONS.md) · [PRODUCTION_IMPLEMENTATION_PLAN.md](PRODUCTION_IMPLEMENTATION_PLAN.md)

## Two ways to run it

|              | **All-in-one (default)**                        | Headless (optional)                               |
| ------------ | ----------------------------------------------- | ------------------------------------------------- |
| Needs        | WordPress hosting only                          | + a Node/Docker host                              |
| Editor       | wp-admin → RK Builder                           | wp-admin (nonce) or `/builder` on the Node server |
| Public pages | PHP, inside your theme or a standalone template | Node SSR on its own domain                        |
| Auth         | WordPress login + REST nonce                    | same, or Node session + app password proxy        |
| Install      | upload one ZIP                                  | see [DEPLOYMENT.md](DEPLOYMENT.md)                |

Build the installable ZIP yourself: `pnpm install --frozen-lockfile && pnpm build:plugin` → `dist/rk-builder-all-in-one.zip`
(also published by the _Release plugin_ workflow when you push a `vX.Y.Z` tag that matches the plugin version).

## Developer quick start (no WordPress needed)

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

## Reusable blocks

Any content block can be saved once and used on many pages, **linked**: edit it in one place and every page changes.

- **Save:** select a block → Inspector → **Save as reusable block**, name it. The block moves to the library and the page now
  holds a reference. The library appears under the block palette ("reusable / library"); click an entry to add it to a page.
- **Edit everywhere:** select a reusable on any page → change the fields → **Update everywhere**. Published pages that use it
  are purged from caches immediately.
- **Detach:** **Detach** copies the content back into the page as an ordinary block that no longer follows the library.
- Rules: a reusable holds one content block (hero, heading, text, image, CTA, services/portfolio grid, spacer, divider,
  testimonial, contact) and cannot contain another reusable. A library entry can only be deleted once no page uses it.
  Creating or changing library entries needs `edit_others_pages` (editors and administrators).
- Public output is exactly the referenced block's own markup (no wrapper); the PHP renderer, the React views and the Node SSR agree
  (covered by the parity test). Site export/import carries the library: reusables are matched by slug, page references are re-pointed.
- REST: `GET /builder/reusables`, `POST /builder/reusables`, `POST /builder/reusables/{id}`, `POST /builder/reusables/{id}/delete`.

## Converting an existing site

[`scripts/convert-peoria.mjs`](scripts/convert-peoria.mjs) is a worked example: it turns the _Peoria Hardwood Floors_ Next.js
site into four RK Builder site-export bundles (pages, linked reusable blocks, theme, services) plus a report of what could not be
converted. It reads the repo's data files **without running any of its code**, keeps images at their source URLs and lets
**Import site** copy them into the media library. Rehearse an import on a throwaway WordPress with
`node scripts/wp-import-bundle.mjs dist/peoria/1-core.json … --theme --content`.

## Site export / import

Administrators get **Export site** and **Import site** on the page list (WordPress-hosted editor: all-in-one plugin or RK Suite).

- **Export** downloads `rk-builder-site-YYYY-MM-DD.json` with: every page that has a builder layout (the working **draft**), the
  theme, the media those pages use (URL, alt, title, size), and Services / Portfolio posts.
- **Import** first runs a **check** (dry run) that reports what would change; nothing is written until you click _Import now_.
  - Pages arrive as **drafts**. A page whose slug already exists gets a new draft revision; if it is live it **stays live
    until you publish**. Import never publishes. Services / projects arrive as drafts too (unless you choose otherwise through the API).
  - Images are **copied into this site's media library** (downloaded over http/https with WordPress' safe HTTP client; JPEG, PNG, GIF,
    WebP, AVIF only), and layouts are rewritten to the local copies. Attachment IDs from another site are never trusted.
  - Every layout is checked by the same strict validator as the editor. An invalid page is **skipped and listed**, never half-imported.
  - Importing the same file again **updates by slug** and re-uses images (matched by source URL): no duplicates.
  - The theme is only replaced if you tick _The theme_. It changes every page, so it is off by default.
- Limits: 8 MB file, 500 pages, 150 images, 300 content posts per import. The source site must be reachable for images to copy;
  otherwise those pages are skipped with a hint to fix the image or allow its host (Settings → RK Builder → Allowed image hosts).

REST (administrators): `GET /rk/v1/builder/site-export`, `POST /rk/v1/builder/site-import` with `{ "bundle": {...}, "options": { "dryRun": true, "theme": false, "content": false, "contentStatus": "draft" } }`
(`dryRun` defaults to **true**).

Bundle: `{ format: "rk-builder-site", version: 1, exportedAt, source: {url, plugin}, theme, media: [{id,url,alt,title,width?,height?}], pages: [{slug,title,wasPublished,layout}], content: [{type,slug,title,status,excerpt,content,order,terms,featured}] }`.

## Media upload

In the media picker choose **Upload image** (WordPress-hosted editor; needs the `upload_files` capability). Type the alt text first
and it is saved with the image. The server checks the file's real type (not its name): JPEG, PNG, GIF, WebP or AVIF, up to the
smaller of WordPress' upload limit and 10 MB. **SVG is refused** because it can carry script. REST: `POST /rk/v1/builder/media`
(multipart field `file`, optional `alt`, `title`) → `201 { item }`.

> The headless Node proxy deliberately exposes only the editing routes, so upload and site export/import are not available there.

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

|                                                  |                                                                                                                                     |
| ------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------- |
| `pnpm verify`                                    | typecheck + lint + format check + unit/contract tests + PHP tests + build                                                           |
| `pnpm check` · `pnpm lint` · `pnpm format:check` | static checks                                                                                                                       |
| `pnpm test` · `pnpm test:php`                    | Vitest (schemas, reducer, API client, SSR, proxy) · plugin tests (plain PHP)                                                        |
| `pnpm build && pnpm test:e2e`                    | Playwright against the mock WordPress (editing, publishing, a11y, recovery)                                                         |
| `pnpm build:plugin`                              | build the all-in-one plugin ZIP (`dist/rk-builder-all-in-one.zip`)                                                                  |
| `pnpm test:all-in-one`                           | **Packaged plugin on real WordPress, no Node server**: wp-admin builder, publish, PHP-rendered page, preview, roles (needs network) |
| `pnpm test:wp`                                   | **Real WordPress**: client + proxy + plugin + SSR through WordPress Playground (needs network)                                      |
| `pnpm test:wp-admin`                             | **Real WordPress wp-admin**: nonce mode in a browser (needs network)                                                                |
| `pnpm smoke:wp`                                  | Plugin REST smoke test against real WordPress                                                                                       |
| `pnpm audit:prod`                                | dependency audit (prod, high severity)                                                                                              |

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

### AI flooring visualizer (v1.6)

The **AI flooring visualizer** block is a photo upload, a set of look choices (room, project, style, species, direction,
finish, sheen, city) and a preview, backed by REST endpoints under `/wp-json/rk/v1/visualizer/` (`quota`, `generate`,
`status`, `lead`). Turn it on in **Settings > RK Visualizer** and choose where the images come from:

- **Hugging Face (FLUX Kontext)**: the same model the source site uses, through the Hugging Face router. Needs an access
  token: paste it in the field, set `RK_BUILDER_VIZ_HF_TOKEN` in `wp-config.php`, or set the `HF_TOKEN` environment variable.
  Asynchronous: the browser polls `status`, so no PHP request waits on the model.
- **Google Gemini (image editing)**: sends the photo and the instruction to a Gemini image model (default
  `gemini-2.5-flash-image`, changeable in Settings) and keeps the image it returns. Needs an API key from Google AI Studio:
  paste it in the field, set `RK_BUILDER_VIZ_GEMINI_KEY` in `wp-config.php`, or set `GEMINI_API_KEY` in the environment.
  The key travels in the `x-goog-api-key` header, never in a URL. One synchronous call, bounded by the "give up after" setting.
- **My own backend API**: the plugin POSTs JSON `{prompt, image (data URI), mimeType, options}` to your URL, with your key in
  the header you choose, and expects `{imageUrl}`, `{image: base64 or data URI}` or `{statusUrl}` (polled until it returns one
  of those). Use this to put your own model, queue or serverless function behind the page.
- **Test mode**: returns the uploaded photo, so you can try the whole page without a provider or any cost.

Visitors are limited per browser (a cookie) and per IP address: a few free visualizations (2 by default), then a short
contact form that unlocks one more and stores the lead (listed on the settings page, optionally emailed to you), then one
more each waiting period. A generation is counted when it starts and given back if the provider fails. Photos are checked
(JPEG, PNG or WebP, up to 10 MB, at least 640x480) and sent only to the backend you chose; results from your own backend
that arrive as image data are kept in `uploads/rk-visualizer/` for a week.

### Marketing blocks (v1.5)

For brochure-style sites: `navbar` (fixed header that turns solid on scroll), `coverhero` (full-bleed photo hero with
breadcrumb), `sitefooter`, `contactband`, `section`, `split`, `panel` (copy beside divider rows, or heading beside checks),
`values`, `catalog` (photo/swatch cards with specs and bullets), `detail` (steps, factors, FAQ and a sticky sidebar) and
`gallery`. List-like props are plain text, one item per line with `|` between fields (for example `Label|/path`), so they stay
flat, validated and identical in the PHP and React renderers. Header, footer and contact band are normally reusable blocks.
The full-width look needs the **standalone** rendering mode (Settings → RK Builder).

Small behaviours ship as one inline script (no extra file): `calculator` (project type × quantity → a planning range; one
`Label|rate|unit` line per type), filter buttons on `catalog`/`gallery` (`filters`; a catalog card's tag is the text after the
last `·` in its blurb, a gallery photo's tag is its category), product pop-ups on `catalog` (`modals`, one line per card:
`image|Title|Intro|item; item`), the navbar's mobile menu, and the active-page underline. Without JavaScript the pages still
render; only the interaction is missing.

## SEO per page

A site bundle page can carry `seo: { title, description, image, noindex, service, parent }`, and the bundle `seo.organization` (`name`, `telephone`, `email`, `description`, `logo`). The importer stores them as the `_rk_seo_*` post meta keys (so RK SEO reads the same values) and RK Builder prints the title, description, canonical, Open Graph, Twitter tags, `noindex, follow` and a JSON-LD graph (Organization, WebSite, WebPage, Service, BreadcrumbList) on the public page. When another SEO plugin is active, or the RK SEO module outputs its own graph, RK Builder prints nothing extra.
