# RK Builder inside RK Suite

RK Builder can ship as a **module of RK Suite** (v1.17.0+), next to RK Core, RK SEO, RK Forms and the others, and
its tools are exposed through RK Suite's MCP server so an assistant can build and edit pages.

## Build the merged zip

```bash
pnpm install --frozen-lockfile
pnpm build:plugin                                              # stages dist/plugin/rk-builder with the editor bundle
node scripts/build-suite.mjs ~/Downloads/rk-suite-v1.16.9.zip  # → dist/rk-suite-v1.17.0.zip
```

`build-suite.mjs` unpacks the suite zip, adds `modules/rk-builder/`, registers the module, lets RK API's MCP server
accept tools from modules (two small, anchor-checked patches) and bumps the version. If a future suite release
changes the patched files the script stops with a clear message instead of producing a broken zip.

## Install

1. Plugins → Add New → Upload `rk-suite-v1.17.0.zip` → **Replace current with uploaded**.
2. RK → Modules → switch on **RK Builder** (and **RK API** for MCP).
3. RK → RK Builder opens the editor. If the standalone _RK Builder_ plugin is also active the module steps aside;
   deactivate the standalone plugin and keep the suite.

Page data lives in post meta, so moving between the standalone plugin and the module keeps your pages.

## MCP tools

POST JSON-RPC to `/wp-json/rk/v1/mcp` with an Application Password or the RK API key (RK → RK API).

| Tool                                              | What it does                                                                                         |
| ------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| `wp_builder_block_types`                          | The strict document format: block types and every prop's limits                                      |
| `wp_builder_list_pages` · `wp_builder_get_layout` | Pages with draft / published revision; a page's draft layout + theme                                 |
| `wp_builder_save_layout`                          | Validate and save a **draft** (never publishes)                                                      |
| `wp_builder_preview_link`                         | 15-minute link that shows the draft on the real site                                                 |
| `wp_builder_publish` · `wp_builder_unpublish`     | Go live / go offline. **Require `confirm: true`**                                                    |
| `wp_builder_get_theme` · `wp_builder_save_theme`  | Site theme (header, footer, colors). Save needs admin + `confirm`                                    |
| `wp_builder_export_site`                          | Whole-site export bundle (draft layouts, theme, media, content)                                      |
| `wp_builder_import_site`                          | Import a bundle. **Dry run by default**; a real import needs `confirm: true`; pages arrive as drafts |
| `wp_builder_list_media`                           | Media-library images for image blocks and hero backgrounds                                           |

Every tool uses the editor's own permission checks, strict validation, revisions and locking. With the RK API
key (no user) tools run as the first administrator — the same reach the key already has over pages.

## Test it

```bash
pnpm build:plugin
RK_SUITE_ZIP=~/Downloads/rk-suite-v1.16.9.zip pnpm test:suite   # real WordPress via Playground, skips without the zip
```
