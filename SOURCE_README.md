# RK React Builder — Start Here

Turning "RK Theme" into a **headless WordPress + React** site with an **in-app visual drag-drop page builder** (admin edits content, images, portfolios, services, and global customization).

## Files in this set

| File | What it is |
|---|---|
| `rk-builder-poc.html` | **Try this first.** A working, clickable proof-of-concept of the visual builder. Just double-click to open in a browser. |
| `RK-React-Builder-Architecture.md` | The full plan: stack, data model, block system, WordPress endpoints, build phases, effort, trade-offs. |
| `rk-builder-wp-plugin.zip` | The WordPress backend **stub** — installs Portfolio + Service CPTs and the REST endpoints the React app reads/writes. |
| `rk-builder/rk-builder.php` | The source of that plugin (unzipped), if you want to read/edit it. |
| `README-RK-React-Builder.md` | This file. |

## 1. Try the builder POC (30 seconds)

Open **`rk-builder-poc.html`** in any browser. You can:
- **Drag** blocks from the left palette onto the page (Hero, Services grid, Portfolio grid, Text, Image, CTA, Spacer).
- **Click** a block to edit its content in the right inspector; **drag** a placed block to reorder; use ⧉/✕ to duplicate/delete.
- Open the **Theme** tab to change primary color, background, text color, and font — watch every block restyle live (these are the global "customization" tokens).
- Hit **Preview** to see the clean public render, or **Export layout JSON** to see exactly the data that gets saved to WordPress.

> The POC is in-memory only (nothing is saved). Services/Portfolio grids use mock data to simulate what WordPress would return.

## 2. Install the WordPress backend stub (optional, to make it real)

1. WordPress admin → Plugins → Add New → Upload Plugin → choose **`rk-builder-wp-plugin.zip`** → Install → Activate.
2. You'll get **Services** and **Portfolio** in the admin menu — add a few items with a title, excerpt, and featured image.
3. Check the REST endpoints in a browser (logged in):
   - `…/wp-json/wp/v2/service` and `…/wp-json/wp/v2/portfolio` — your content.
   - `…/wp-json/rk/v1/theme-config` — global theme tokens.
   - `…/wp-json/rk/v1/builder/layout/{pageId}` — a page's saved layout (empty until saved).
4. Before wiring a live frontend, set your frontend domain for CORS: edit the `rk_builder_frontend_origin` filter in `rk-builder.php` (currently `https://your-frontend.example`).

> This is a **stub / starting point**, not production. In the real build, the CPTs come from RK Core and this module just adds the layout + theme-config endpoints.

## 3. The missing middle (next task)

You now have the **two ends** of the loop: the builder UX (POC) and the WordPress data layer (stub). The piece to build next is the **Next.js frontend** that:
- reads `builder/layout/{id}` + `theme-config` + the CPTs,
- renders them server-side (SSR/SSG) with the React component library for real SEO,
- and hosts the `/builder` editor route (the POC, wired to the save endpoint).

See **`RK-React-Builder-Architecture.md` §7** for the phased plan. Recommended first milestone (Phase 1): stand up the endpoints + one real page rendered by Next.js from the API — that proves the whole headless loop before building the full drag-drop editor.

## Honest note

A full visual drag-drop builder in React is a real project (you're building a focused, React-native page builder). The architecture doc is candid about phases and effort, and it also describes a **middle path** (§8) — a headless React site with fixed page templates, no visual builder — that delivers most of the benefit for roughly a third of the work if the drag-drop editor turns out to be a "nice to have."
