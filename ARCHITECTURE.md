# RK Builder — Headless WordPress + React Visual Page Builder (Architecture Plan)

> **Goal:** repurpose "RK Theme" from an Elementor theme-builder into a system where **WordPress is the headless CMS + admin** and a **React frontend** is the public site — with an **in-app visual drag-drop builder** where the admin arranges pages and edits content, images, portfolios, services, and global customization (colors/fonts/logo/layout).
>
> **Honest framing:** this is a *build*, not a *conversion*. You keep WordPress + RK Core (CPTs/fields) as the backend, drop RK Theme's Elementor rendering, and add (a) a React frontend with a component library, (b) a visual builder UI, and (c) a small WordPress module (`rk-builder`) that stores layouts + theme config and exposes them over REST. The included POC (`rk-builder-poc.html`) is a working slice of the builder UX.

---

## 1. The big picture

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
- **Builder** is the *same* components rendered inside a drag-drop editor that reads/writes that layout JSON.

Because both use the same components, "what you build is what you ship" — no divergence between editor and live site (the classic Elementor promise, kept).

---

## 2. Recommended stack

| Layer | Choice | Why |
|---|---|---|
| CMS / admin / DB | **WordPress** (existing) | Keep the admin your client already knows; keep RK Core. |
| Content types | **RK Core CPTs + fields** (existing) | Portfolio, Service, etc. already modelled and REST-exposed. |
| Layout + theme store | **`rk-builder` module** (new, small) | Stores per-page layout JSON + global theme config; REST endpoints. |
| API | **WP REST** (add **WPGraphQL** later if queries get complex) | REST is enough to start; already how RK API works. |
| Frontend | **Next.js (App Router) + React + TypeScript** | **SSR/SSG = real SEO** (server-rendered HTML + meta). A plain SPA would be invisible to Google. |
| Styling | **CSS variables driven by theme config** (+ Tailwind or CSS Modules) | Global tokens (primary color, font) flow from WP → CSS vars → every block. |
| Drag-drop | **dnd-kit** (`@dnd-kit/core`, `@dnd-kit/sortable`) | Modern, accessible, maintained; handles palette-drag + canvas-reorder. |
| Editor state | **Zustand** (or React context) | Simple store for the layout tree, selection, undo/redo. |
| Auth (builder) | WP **application passwords** or JWT | The `/builder` route and write endpoints require an authenticated admin. |
| Hosting | Frontend on **Vercel/Netlify**; WP on your current host | Decoupled; WP can even be firewalled to admin-only. |

> SSR is not optional for your use case — your sites are marketing/portfolio and need to rank. Next.js renders each page's HTML on the server from the layout JSON, so crawlers see real content.

---

## 3. Data model

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
    { "type": "hero", "id": "a1b2c3",
      "props": { "heading": "Powering what's next", "sub": "Licensed contractors.", "cta": "Get a quote", "ctaHref": "/contact" } },
    { "type": "services", "id": "d4e5f6",
      "props": { "title": "Our Services", "source": "service", "cols": 3, "limit": 6 } },
    { "type": "portfolio", "id": "g7h8i9",
      "props": { "title": "Recent Work", "source": "portfolio", "cols": 3, "category": "commercial" } },
    { "type": "cta", "id": "j0k1l2", "props": { "heading": "Ready to start?", "cta": "Contact us" } }
  ]
}
```

Key idea: **content blocks reference CPTs by source**, they don't copy the data. A `services` block stores *which* CPT + how many columns; the actual items are fetched live. So editing a Service in WP updates every page that shows it — no re-publishing.

### 3.3 Theme config (global customization)
Stored as a site option (`rk_theme_config`), exposed at `/wp-json/rk/v1/theme-config`:

```json
{ "primary": "#2f6df6", "bg": "#ffffff", "ink": "#141a22",
  "font": "Inter", "logo": "https://.../logo.svg",
  "social": { "instagram": "...", "linkedin": "..." },
  "header": { "sticky": true, "menu": "primary" },
  "footer": { "columns": 3 } }
```

The React app injects these as CSS variables at the root, so one change restyles the whole site. (The POC's Theme panel demonstrates exactly this.)

---

## 4. The block system (this is the heart of it)

A **block registry** — one definition per block type, shared conceptually by editor and renderer:

```ts
// blocks/registry.ts
export const registry = {
  hero:      { label: "Hero",        defaults: { heading:"", sub:"", cta:"" },  Editor: HeroFields,     View: Hero },
  heading:   { label: "Heading",     defaults: { text:"Heading" },             Editor: TextField,      View: Heading },
  text:      { label: "Text",        defaults: { text:"" },                    Editor: TextareaField,  View: Text },
  image:     { label: "Image",       defaults: { url:"" },                     Editor: MediaField,     View: ImageBlock },
  cta:       { label: "CTA banner",  defaults: { heading:"", cta:"" },         Editor: CtaFields,      View: Cta },
  services:  { label: "Services",    defaults: { source:"service", cols:3 },   Editor: GridFields,     View: ServicesGrid },
  portfolio: { label: "Portfolio",   defaults: { source:"portfolio", cols:3 }, Editor: GridFields,     View: PortfolioGrid },
  columns:   { label: "Columns",     defaults: { ratio:"1-1", children:[[],[]] }, Editor: ColsFields,  View: Columns }, // nested (phase 2)
};
```

- **View** = renders the block for the public site (and inside the editor canvas).
- **Editor** = the inspector fields for that block.
- Adding a new block = one registry entry + two small components. This is how the builder stays extensible.

**Nested layout** (rows/columns) is the hard part of any visual builder. Start **flat** (a vertical stack of full-width sections — covers ~80% of marketing pages), then add a `columns` container block in phase 2 (dnd-kit supports nested sortables).

---

## 5. WordPress side — the `rk-builder` module

A small module (stub included as `rk-builder-wp-plugin.zip`). Endpoints:

| Method | Route | Purpose |
|---|---|---|
| GET  | `/wp-json/rk/v1/builder/layout/{pageId}` | Read a page's layout JSON (public — needed for SSR). |
| POST | `/wp-json/rk/v1/builder/layout/{pageId}` | Save layout JSON (auth: `current_user_can('edit_pages')` + nonce/app-password). |
| GET  | `/wp-json/rk/v1/theme-config` | Read global theme tokens (public). |
| POST | `/wp-json/rk/v1/theme-config` | Save theme tokens (auth: `manage_options`). |
| GET  | `/wp-json/wp/v2/service`, `/portfolio` | CPT content (RK Core, existing). |

Security follows the same rules RK already uses: `ABSPATH` guard, capability checks, nonce/app-password on writes, sanitize on input, `wp_kses`/escaping on any HTML, `$wpdb->prepare` if you touch the DB directly. (Layouts are JSON in post meta, so mostly `update_post_meta` + `wp_json_encode`.)

**What happens to RK Theme's Elementor pieces:** its *conditions engine* concept (which template applies to which page/type) carries over as "which layout/route pattern the React app uses." Its Elementor *rendering* is dropped. RK Core, RK SEO, RK Library, RK Migrate are unaffected and still useful (SEO meta and media especially).

---

## 6. Rendering flow (public page)

1. Visitor hits `/services` on the Next.js site.
2. Next.js (server) fetches in parallel: `builder/layout/{id}`, `theme-config`, and any CPT data the blocks reference (`/service`).
3. It maps `layout.blocks[]` → the block `View` components, injects theme tokens as CSS vars, and renders **HTML on the server** (SSR/ISR).
4. Crawlers and users get fully-formed HTML + `<title>`/meta (from RK SEO). Client hydrates for interactivity.

Editing loop: admin opens `/builder?page=42` → dnd-kit editor loads the same layout → drag/drop/edit → **Save** POSTs the layout JSON back → next SSR render reflects it (revalidate the route).

---

## 7. Build phases & effort (realistic)

| Phase | Scope | Rough effort |
|---|---|---|
| **0 — POC (done)** | Clickable drag-drop builder, mock data, JSON export, theme tokens. | ✅ included |
| **1 — Backend + read path** | `rk-builder` module (layout + theme-config REST), RK Core CPTs for Portfolio/Service, Next.js public site rendering a hand-written layout from the API, SSR + SEO meta. | ~1–2 weeks |
| **2 — Real builder** | dnd-kit editor wired to the API (load/save), the 8 core blocks, media-library picker, theme customizer panel, auth. | ~2–4 weeks |
| **3 — Layout depth** | Columns/nested container block, per-block spacing/background controls, responsive (desktop/tablet/mobile) settings, undo/redo, autosave, revisions. | ~2–4 weeks |
| **4 — Polish** | Global header/footer builder, menu management, 404/preview, image optimization, caching/ISR, roles (who can edit what). | ~1–3 weeks |

The **long pole** is Phase 3 — nested layouts + responsive controls are what make a builder feel "real," and they're genuinely non-trivial. Be honest with yourself that you're building a focused page builder; scoping the block set tightly (8–12 blocks, flat-first) is what keeps it achievable.

---

## 8. Trade-offs vs. staying on Elementor

**You gain:** a React frontend (fast, modern, app-like), full control of markup/perf/SEO, no Elementor bloat, a builder tailored to *your* blocks, clean separation of content and presentation.

**You take on:** building and maintaining a page builder + a Next.js app + a headless deployment. Elementor's ecosystem (thousands of widgets, addons, community) is replaced by *your* component library. Every "I wish it could also do X" is now your code.

**Middle path (worth considering):** keep WordPress + RK Core as the content backend and build the **Next.js frontend with a fixed set of page templates** (no visual builder) — the admin edits *content* (CPTs, a few page fields) but not free-form layout. This gets you the React site + headless benefits for ~⅓ the effort, and you add the visual builder later only if clients truly need drag-drop. If the drag-drop editor is a "nice to have," this is the pragmatic first release.

---

## 9. Recommended next step

Phase 1 is the highest-leverage proof: stand up the `rk-builder` REST endpoints + Portfolio/Service CPTs on WordPress, and a minimal Next.js site that renders one real page from the API with working SEO. That validates the whole headless loop end-to-end before investing in the full drag-drop editor. The included WP stub + POC are the two ends of that loop; the missing middle is the Next.js reader, which is a focused next task.
