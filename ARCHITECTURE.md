# Architecture

> WordPress owns content and editorial state. The builder owns layout and presentation rules.
> The public renderer and the editor use the same canonical document model.

This file describes what is built. The original planning document is kept as an appendix at the end.

## 0. Deployment shapes

**All-in-one (default, v1.1):** one WordPress plugin. The React editor ships in `assets/` and opens from
_wp-admin → RK Builder_; WordPress cookie + REST nonce authenticate; published layouts are rendered by **PHP** inside the
active theme (or a standalone template). No Node process exists at runtime. **Headless (optional):** the Node server in
`server/` renders the public site and/or proxies the editor, as described in the rest of this document.
Both share the same schema, REST API and block markup (see ADR-6).

## 1. System overview

```text
                 ┌──────────────────────────── browser ────────────────────────────┐
                 │  Builder SPA (Vite + React + TS)   client/                       │
                 │  registry-driven blocks · Zod validation · undo/redo · drafts    │
                 └───────────────┬──────────────────────────────┬──────────────────┘
        standalone ("proxy")     │                              │   embedded ("nonce")
        same-origin fetch        ▼                              ▼   same-origin to WordPress
        cookie rk_sid + X-RK-CSRF                               cookie wordpress_logged_in + X-WP-Nonce
┌───────────────────────────────────────────┐        ┌──────────────────────────────────┐
│ Node server  server/                      │        │ WordPress + rk-builder plugin    │
│  • /api/auth  session + CSRF              │ Basic  │  REST rk/v1  (wp-plugin/)        │
│  • /api/wp    allow-listed proxy ─────────┼──auth─▶│  validation · revisions · locks  │
│  • public SSR  react-dom/server           │◀───────┤  CPTs · CORS · preview tokens    │
│  • /api/revalidate  ◀── signed webhook ───┼────────┤  revalidation webhook            │
└───────────────────────────────────────────┘        └──────────────────────────────────┘
        ▲
        │ HTML (no client JS required) — theme as CSS variables, metadata, canonical, OG/Twitter
     visitors
```

Two deployment shapes, one codebase:

|                       | Standalone ("proxy") — default                                                                      | Embedded ("nonce")                                                                 |
| --------------------- | --------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| Builder served by     | Node server (`/builder`)                                                                            | WordPress admin screen (`wp-admin → RK Builder`) loading the bundle from `/embed/` |
| Editor identity       | One WordPress application-password account held **server-side**; editors sign in to the Node server | The signed-in WordPress user; capabilities are per user                            |
| Browser credential    | `HttpOnly; SameSite=Strict` session cookie + CSRF header                                            | WordPress login cookie + REST nonce                                                |
| Public site           | Node SSR                                                                                            | Node SSR (same server)                                                             |
| `WORDPRESS_AUTH_MODE` | `proxy`                                                                                             | `nonce`                                                                            |

## 2. Decision record

**ADR-1 — Public rendering: server-rendered React in the existing Node/Express server, not Next.js.**
The repository was Vite-based and already shipped an Express server. A second framework would duplicate the block
registry or force a rewrite of a working editor. Instead the server imports the _same_ `registry` and `BlockRenderer`
that the canvas uses and calls `renderToString`. The public pages ship **no framework JavaScript**: the HTML is the
product (hydration is not needed because blocks are static; grids are rendered from data fetched on the server).
Trade-off: no Next.js ISR/image optimizer. We cover the need with an explicit page cache (TTL + stale-if-error +
webhook purge) and by emitting `width`/`height`/`srcset` from WordPress image sizes.

**ADR-2 — Authentication: two explicit flows, no credentials in browser storage.**
_Proxy_ keeps the long-lived WordPress application password on the server; the browser holds only an opaque session
id (HttpOnly) and an in-memory CSRF token. _Nonce_ is the preferred flow when the builder can be served by WordPress
itself: core cookie auth + `X-WP-Nonce`, per-user capabilities (`edit_post`, `publish_post`, `manage_options`).
The plugin never rolls its own auth; it only enforces capabilities and returns stable error codes.

**ADR-6 — All-in-one: PHP renders the public page; React views and PHP renderers are kept in lock-step by a parity test.**
Hosting for most customers is cPanel/PHP only, so the plugin renders published layouts itself
(`includes/renderer.php`, `includes/render/*`, `includes/public.php`). To keep one stylesheet (`assets/site.css`) valid for the
canvas, the Node SSR and PHP, `scripts/render-parity.test.tsx` renders every `contracts/valid/layout-*.json` with React and
with PHP (`wp-plugin/tests/render-cli.php`) and requires identical HTML apart from the added `rk-block rk-block-<type>` classes
and `data-rk-block` attributes. Escaping is done by the plugin's own `rk_builder_h()` (React-compatible), `the_content` is
replaced after shortcodes run so layout text can never execute one, and password-protected pages are skipped. The admin screen
is a bare full-page document served from `load-<hook>` rather than an enqueue inside wp-admin chrome, because the editor's CSS
contains global resets (deliberate deviation from the original brief). Previews are served from `template_redirect` without
`wp_head()` so SEO plugins cannot inject home-page tags; an invalid token is a real 404 and never falls back to published content.

**ADR-3 — One canonical document, validated at every boundary.**
`{version: 1, blocks: [{id, type, props}]}` plus a versioned theme. Zod schemas (`client/src/lib/schema`,
`client/src/blocks/*/schema.ts`) and the strict PHP validator (`wp-plugin/rk-builder/includes/validation.php`) are
kept in lock-step by shared fixtures in `contracts/` that **both** test suites run. Invalid data is rejected with
paths, never silently dropped. `migrate.ts` upgrades historical shapes (prototype flat blocks, versionless themes).

**ADR-4 — Draft and published are separate states.**
Saving writes a draft revision. Publishing snapshots the draft into `_rk_layout_published`, sets the page's
`post_status`, and fires the revalidation webhook. The public endpoint serves only the snapshot, and only when the
page is `publish`.

**ADR-5 — Optimistic concurrency.**
Every write carries `expectedRevision`; a mismatch is `409 rk_revision_conflict {currentRevision}`. The plugin guards
the read-check-write window with an atomic `INSERT IGNORE` lock row (see _Known limits_ in `OPERATIONS.md`).

## 3. Repository map

```text
client/src/
  lib/schema/          Zod schemas, migrations, theme → CSS vars, friendly messages
  blocks/<type>/       schema.ts · View.tsx · Editor.tsx (field defs); registry.ts ties them together
  render/              BlockRenderer + ContentContext (shared by canvas and public site)
  lib/api/             http (timeouts, nonce/CSRF), typed endpoints, error mapping, content cache
  lib/editor/          pure reducer (undo/redo, coalescing), local drafts, session state machine
  components/          page selector, canvas, inspector, theme panel, media picker, dialogs
  e2e/  e2e-wp/        Playwright (mock WordPress / real WordPress wp-admin)
server/                env validation, auth, proxy, SSR, cache, metrics, telemetry, security headers
wp-plugin/rk-builder/  the WordPress plugin (+ tests/ in wp-plugin/tests, plain PHP, no composer)
contracts/             shared valid/invalid/legacy fixtures
scripts/               mock WordPress, Playground boot helper, smoke + integration tests
```

## 4. Data contract

```ts
LayoutDocument = { version: 1, blocks: Block[] }            // max 100 blocks, flat stack
Block          = { id: /^[a-z0-9][a-z0-9_-]{0,63}$/, type: BlockType, props: <per-type strict object> }
ThemeConfig    = { version: 1, primary, bg, ink: #RRGGBB, font: "Space Grotesk" | "IBM Plex Mono" | "Georgia",
                   logoMediaId?, logoUrl?, social?{instagram?, linkedin?: https}, header?{sticky?}, footer?{columns?: 1–4} }
```

| Block                | Props (all required unless `?`)                                                                                                                   |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| hero                 | `heading` 1–160, `sub` ≤400, `cta` ≤60, `ctaHref` link                                                                                            |
| heading              | `text` 1–200, `level` 2 \| 3                                                                                                                      |
| text                 | `text` ≤5000 (plain text; blank line = paragraph; never HTML)                                                                                     |
| image                | `url` (relative or http(s)), `alt` ≤300 (required unless `decorative`), `decorative`, `mediaId?`, `width?`, `height?`, `srcset?`                  |
| cta                  | `heading` 1–160, `cta` 1–60, `ctaHref` link                                                                                                       |
| services / portfolio | `title` ≤120, `source` (`service` / `portfolio`), `limit` 1–24, `cols` 2–4, `category` slug, `orderBy` date\|title\|menu_order, `order` asc\|desc |
| spacer               | `h` 8–240                                                                                                                                         |
| divider              | `style` solid \| dashed                                                                                                                           |

Links: `""`, `/relative`, `#fragment`, `http(s)://`, `mailto:`, `tel:`. Never `javascript:`, `data:`, `//host`, control
characters or backslashes. Layouts reference content _sources and display rules_; they never copy a post's content.

## 5. REST contract (`/wp-json/rk/v1`)

| Method & path                                               | Auth                                            | Purpose                                                 |
| ----------------------------------------------------------- | ----------------------------------------------- | ------------------------------------------------------- |
| `GET builder/pages`                                         | `edit_pages` (per page `edit_post`)             | List pages: title, slug, status, modified, revision     |
| `GET builder/layout/{id}`                                   | `edit_post`                                     | Draft layout + theme + revision + `capabilities`        |
| `POST builder/layout/{id}`                                  | `edit_post` (+`manage_options` to change theme) | Save draft; `expectedRevision`, `status:"draft"`        |
| `GET builder/revisions/{id}` · `GET …/{rev}`                | `edit_post`                                     | History, and one revision's layout for preview          |
| `POST builder/revisions/{id}/{rev}/restore`                 | `edit_post`                                     | Appends a new `restore` revision                        |
| `POST builder/publish/{id}` · `POST builder/unpublish/{id}` | `edit_post` + `publish_post`                    | Snapshot → live; back to draft                          |
| `POST builder/preview-token/{id}`                           | `edit_post`                                     | 15-minute HMAC token (read-only draft preview)          |
| `GET builder/media`                                         | `upload_files`                                  | Search images (id, url, alt, size, srcset)              |
| `GET/POST theme-config`                                     | public / `manage_options`                       | Global theme                                            |
| `GET public/page/{slug}[?preview=token]`                    | public                                          | Published snapshot only (404 for draft/private/missing) |
| `GET content/{service\|portfolio}`                          | public                                          | Normalized published items with image dimensions        |

Errors are `{code, message, data:{status, currentRevision?, issues?[{path,message}]}}` with codes `rk_unauthorized`,
`rk_forbidden`, `rk_not_found`, `rk_invalid_layout`, `rk_invalid_theme`, `rk_revision_conflict`, `rk_payload_too_large`,
`rk_server_error`.

## 6. Editor flows

- **Load** — resolve route → authenticate → `GET layout` → validate/migrate (`parseLayout`) → fetch live grid content
  through a cache that de-duplicates in-flight requests → render. Distinct screens exist for loading, not found,
  unauthorized, forbidden, network error, invalid server data, and _offline draft available_.
- **Edit** — a pure reducer owns state: add, remove, duplicate, move, patch, theme patch, undo/redo (100 steps; rapid
  typing coalesces). Dirty = serialized snapshot ≠ the snapshot last known to be on the server, so undoing back to the
  saved state is clean and edits typed during an in-flight save stay dirty.
- **Save** — client validates, sends `{layout, theme?, expectedRevision, status:"draft"}`. Outcomes map to explicit
  statuses: saved to server, **saved locally** (never labelled as a WordPress save), auth required, validation failed,
  conflict, network error, forbidden.
- **Local drafts** — `rk:draft:{pageId}` envelope `{pageId, schemaVersion, baseRevision, savedAt, layout, theme}`,
  validated on read, offered back when it differs from the server, removed after a successful save.
- **Conflict** — modal with: load the server version, keep mine and rebase onto the new revision, or download mine.
- **Publish** — a deliberate dialog; saves first if dirty; Unpublish lives in the same dialog.
- **Preview** — in-editor preview mode (unsaved edits included) and a _preview link_ to the real frontend using a
  short-lived signed token (the draft is saved first because the frontend reads the stored draft).

## 7. Public rendering

`GET /:slug` → fetch `public/page/{slug}` + referenced content in parallel → validate → `renderToString` → HTML with
`<title>`, description, canonical, Open Graph, Twitter card, theme CSS variables (nonce'd `<style>`), `width`/`height`/
`srcset`/`alt` on images. Cache: TTL (`PUBLIC_CACHE_TTL_SECONDS`), stale-if-error up to 1 h, purged by the proxy after
publish/unpublish/theme/restore **and** by the WordPress webhook (`POST /api/revalidate`, constant-time secret).
404 for unknown/draft/private; 503 page with `Retry-After` if WordPress is down and nothing is cached. `/preview/:slug`
requires a token WordPress accepted and is `noindex, no-store`; an invalid token never falls back to public content.

## 8. Deferred (not in v1)

Nested columns/containers, per-block responsive controls, header/footer builder, custom CSS/HTML blocks, realtime
collaboration, animations, a larger widget library, AI layout generation. The schema is versioned and the registry is
open, so each can be added as a new block or a `version: 2` migration without touching existing documents.

---

# Appendix — original architecture plan (historical)

> **Goal:** repurpose "RK Theme" from an Elementor theme-builder into a system where **WordPress is the headless CMS + admin** and a **React frontend** is the public site — with an **in-app visual drag-drop builder** where the admin arranges pages and edits content, images, portfolios, services, and global customization (colors/fonts/logo/layout).
>
> **Honest framing:** this is a _build_, not a _conversion_. You keep WordPress + RK Core (CPTs/fields) as the backend, drop RK Theme's Elementor rendering, and add (a) a React frontend with a component library, (b) a visual builder UI, and (c) a small WordPress module (`rk-builder`) that stores layouts + theme config and exposes them over REST. The included POC (`rk-builder-poc.html`) is a working slice of the builder UX.

---

### 1. The big picture

```
┌───────────────────────────── WordPress (admin + data) ─────────────────────────────┐
│  wp-admin  ─ admin logs in here, edits everything                                   │
│  RK Core     → CPTs + fields: Portfolio, Service, (Pages)   ← already built         │
│  rk-builder  → stores page LAYOUT json + THEME CONFIG, exposes REST  ← new module   │
│  Media Lib   → images/uploads                                                        │
│  RK SEO      → per-page title/meta (exposed via REST)                                │
└───────────────┬─────────────────────────────────────────────────────────────────────┘
                │  REST / GraphQL (JSON)
                ▼
┌───────────────────────────── Next.js (React frontend) ─────────────────────────────┐
│  PUBLIC SITE (SSR/SSG)   → fetches layout+content+theme, renders component library  │
│  /builder (admin route)  → the visual drag-drop editor (auth-gated)                 │
│  Component library       → <Hero> <ServicesGrid> <PortfolioGrid> <CTA> <Image> ...  │
└─────────────────────────────────────────────────────────────────────────────────────┘
```

Two surfaces, one component library:

- **Public site** renders a page's saved layout JSON → SEO-friendly HTML (server-rendered).
- **Builder** is the _same_ components rendered inside a drag-drop editor that reads/writes that layout JSON.

Because both use the same components, "what you build is what you ship" — no divergence between editor and live site (the classic Elementor promise, kept).

---

### 2. Recommended stack

| Layer                | Choice                                                               | Why                                                                                             |
| -------------------- | -------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| CMS / admin / DB     | **WordPress** (existing)                                             | Keep the admin your client already knows; keep RK Core.                                         |
| Content types        | **RK Core CPTs + fields** (existing)                                 | Portfolio, Service, etc. already modelled and REST-exposed.                                     |
| Layout + theme store | **`rk-builder` module** (new, small)                                 | Stores per-page layout JSON + global theme config; REST endpoints.                              |
| API                  | **WP REST** (add **WPGraphQL** later if queries get complex)         | REST is enough to start; already how RK API works.                                              |
| Frontend             | **Next.js (App Router) + React + TypeScript**                        | **SSR/SSG = real SEO** (server-rendered HTML + meta). A plain SPA would be invisible to Google. |
| Styling              | **CSS variables driven by theme config** (+ Tailwind or CSS Modules) | Global tokens (primary color, font) flow from WP → CSS vars → every block.                      |
| Drag-drop            | **dnd-kit** (`@dnd-kit/core`, `@dnd-kit/sortable`)                   | Modern, accessible, maintained; handles palette-drag + canvas-reorder.                          |
| Editor state         | **Zustand** (or React context)                                       | Simple store for the layout tree, selection, undo/redo.                                         |
| Auth (builder)       | WP **application passwords** or JWT                                  | The `/builder` route and write endpoints require an authenticated admin.                        |
| Hosting              | Frontend on **Vercel/Netlify**; WP on your current host              | Decoupled; WP can even be firewalled to admin-only.                                             |

> SSR is not optional for your use case — your sites are marketing/portfolio and need to rank. Next.js renders each page's HTML on the server from the layout JSON, so crawlers see real content.

---

### 3. Data model

### 3.1 Content (RK Core CPTs — mostly exists)

- **Portfolio** (`portfolio`): title, excerpt, gallery/featured image, category (taxonomy), client, year, `order`.
- **Service** (`service`): title, excerpt, icon/image, price/summary, `order`.
- **Page** (`page` or a `rk_page` CPT): holds the **layout** (below) in post meta.
- All `show_in_rest = true` → `GET /wp-json/wp/v2/portfolio`, `/service`, etc.

### 3.2 Page layout (the builder's document)

Stored as JSON in page meta (`_rk_layout`). A page is an ordered tree of **blocks**:

```json
{
  "version": 1,
  "blocks": [
    {
      "type": "hero",
      "id": "a1b2c3",
      "props": {
        "heading": "Powering what's next",
        "sub": "Licensed contractors.",
        "cta": "Get a quote",
        "ctaHref": "/contact"
      }
    },
    {
      "type": "services",
      "id": "d4e5f6",
      "props": {
        "title": "Our Services",
        "source": "service",
        "cols": 3,
        "limit": 6
      }
    },
    {
      "type": "portfolio",
      "id": "g7h8i9",
      "props": {
        "title": "Recent Work",
        "source": "portfolio",
        "cols": 3,
        "category": "commercial"
      }
    },
    {
      "type": "cta",
      "id": "j0k1l2",
      "props": { "heading": "Ready to start?", "cta": "Contact us" }
    }
  ]
}
```

Key idea: **content blocks reference CPTs by source**, they don't copy the data. A `services` block stores _which_ CPT + how many columns; the actual items are fetched live. So editing a Service in WP updates every page that shows it — no re-publishing.

### 3.3 Theme config (global customization)

Stored as a site option (`rk_theme_config`), exposed at `/wp-json/rk/v1/theme-config`:

```json
{
  "primary": "#2f6df6",
  "bg": "#ffffff",
  "ink": "#141a22",
  "font": "Inter",
  "logo": "https://.../logo.svg",
  "social": { "instagram": "...", "linkedin": "..." },
  "header": { "sticky": true, "menu": "primary" },
  "footer": { "columns": 3 }
}
```

The React app injects these as CSS variables at the root, so one change restyles the whole site. (The POC's Theme panel demonstrates exactly this.)

---

### 4. The block system (this is the heart of it)

A **block registry** — one definition per block type, shared conceptually by editor and renderer:

```ts
// blocks/registry.ts
export const registry = {
  hero: {
    label: "Hero",
    defaults: { heading: "", sub: "", cta: "" },
    Editor: HeroFields,
    View: Hero,
  },
  heading: {
    label: "Heading",
    defaults: { text: "Heading" },
    Editor: TextField,
    View: Heading,
  },
  text: {
    label: "Text",
    defaults: { text: "" },
    Editor: TextareaField,
    View: Text,
  },
  image: {
    label: "Image",
    defaults: { url: "" },
    Editor: MediaField,
    View: ImageBlock,
  },
  cta: {
    label: "CTA banner",
    defaults: { heading: "", cta: "" },
    Editor: CtaFields,
    View: Cta,
  },
  services: {
    label: "Services",
    defaults: { source: "service", cols: 3 },
    Editor: GridFields,
    View: ServicesGrid,
  },
  portfolio: {
    label: "Portfolio",
    defaults: { source: "portfolio", cols: 3 },
    Editor: GridFields,
    View: PortfolioGrid,
  },
  columns: {
    label: "Columns",
    defaults: { ratio: "1-1", children: [[], []] },
    Editor: ColsFields,
    View: Columns,
  }, // nested (phase 2)
};
```

- **View** = renders the block for the public site (and inside the editor canvas).
- **Editor** = the inspector fields for that block.
- Adding a new block = one registry entry + two small components. This is how the builder stays extensible.

**Nested layout** (rows/columns) is the hard part of any visual builder. Start **flat** (a vertical stack of full-width sections — covers ~80% of marketing pages), then add a `columns` container block in phase 2 (dnd-kit supports nested sortables).

---

### 5. WordPress side — the `rk-builder` module

A small module (stub included as `rk-builder-wp-plugin.zip`). Endpoints:

| Method | Route                                    | Purpose                                                                         |
| ------ | ---------------------------------------- | ------------------------------------------------------------------------------- |
| GET    | `/wp-json/rk/v1/builder/layout/{pageId}` | Read a page's layout JSON (public — needed for SSR).                            |
| POST   | `/wp-json/rk/v1/builder/layout/{pageId}` | Save layout JSON (auth: `current_user_can('edit_pages')` + nonce/app-password). |
| GET    | `/wp-json/rk/v1/theme-config`            | Read global theme tokens (public).                                              |
| POST   | `/wp-json/rk/v1/theme-config`            | Save theme tokens (auth: `manage_options`).                                     |
| GET    | `/wp-json/wp/v2/service`, `/portfolio`   | CPT content (RK Core, existing).                                                |

Security follows the same rules RK already uses: `ABSPATH` guard, capability checks, nonce/app-password on writes, sanitize on input, `wp_kses`/escaping on any HTML, `$wpdb->prepare` if you touch the DB directly. (Layouts are JSON in post meta, so mostly `update_post_meta` + `wp_json_encode`.)

**What happens to RK Theme's Elementor pieces:** its _conditions engine_ concept (which template applies to which page/type) carries over as "which layout/route pattern the React app uses." Its Elementor _rendering_ is dropped. RK Core, RK SEO, RK Library, RK Migrate are unaffected and still useful (SEO meta and media especially).

---

### 6. Rendering flow (public page)

1. Visitor hits `/services` on the Next.js site.
2. Next.js (server) fetches in parallel: `builder/layout/{id}`, `theme-config`, and any CPT data the blocks reference (`/service`).
3. It maps `layout.blocks[]` → the block `View` components, injects theme tokens as CSS vars, and renders **HTML on the server** (SSR/ISR).
4. Crawlers and users get fully-formed HTML + `<title>`/meta (from RK SEO). Client hydrates for interactivity.

Editing loop: admin opens `/builder?page=42` → dnd-kit editor loads the same layout → drag/drop/edit → **Save** POSTs the layout JSON back → next SSR render reflects it (revalidate the route).

---

### 7. Build phases & effort (realistic)

| Phase                       | Scope                                                                                                                                                                   | Rough effort |
| --------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
| **0 — POC (done)**          | Clickable drag-drop builder, mock data, JSON export, theme tokens.                                                                                                      | ✅ included  |
| **1 — Backend + read path** | `rk-builder` module (layout + theme-config REST), RK Core CPTs for Portfolio/Service, Next.js public site rendering a hand-written layout from the API, SSR + SEO meta. | ~1–2 weeks   |
| **2 — Real builder**        | dnd-kit editor wired to the API (load/save), the 8 core blocks, media-library picker, theme customizer panel, auth.                                                     | ~2–4 weeks   |
| **3 — Layout depth**        | Columns/nested container block, per-block spacing/background controls, responsive (desktop/tablet/mobile) settings, undo/redo, autosave, revisions.                     | ~2–4 weeks   |
| **4 — Polish**              | Global header/footer builder, menu management, 404/preview, image optimization, caching/ISR, roles (who can edit what).                                                 | ~1–3 weeks   |

The **long pole** is Phase 3 — nested layouts + responsive controls are what make a builder feel "real," and they're genuinely non-trivial. Be honest with yourself that you're building a focused page builder; scoping the block set tightly (8–12 blocks, flat-first) is what keeps it achievable.

---

### 8. Trade-offs vs. staying on Elementor

**You gain:** a React frontend (fast, modern, app-like), full control of markup/perf/SEO, no Elementor bloat, a builder tailored to _your_ blocks, clean separation of content and presentation.

**You take on:** building and maintaining a page builder + a Next.js app + a headless deployment. Elementor's ecosystem (thousands of widgets, addons, community) is replaced by _your_ component library. Every "I wish it could also do X" is now your code.

**Middle path (worth considering):** keep WordPress + RK Core as the content backend and build the **Next.js frontend with a fixed set of page templates** (no visual builder) — the admin edits _content_ (CPTs, a few page fields) but not free-form layout. This gets you the React site + headless benefits for ~⅓ the effort, and you add the visual builder later only if clients truly need drag-drop. If the drag-drop editor is a "nice to have," this is the pragmatic first release.

---

### 9. Recommended next step

Phase 1 is the highest-leverage proof: stand up the `rk-builder` REST endpoints + Portfolio/Service CPTs on WordPress, and a minimal Next.js site that renders one real page from the API with working SEO. That validates the whole headless loop end-to-end before investing in the full drag-drop editor. The included WP stub + POC are the two ends of that loop; the missing middle is the Next.js reader, which is a focused next task.
