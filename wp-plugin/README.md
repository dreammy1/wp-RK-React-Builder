# RK Builder (All-in-One) - WordPress plugin

A visual page builder in a single plugin: a React editor inside wp-admin, strict server-side layout validation,
draft/publish with revisions, preview links, PHP public rendering and Service/Portfolio content types.
**No Node.js, Docker, Composer or SSH is needed to install or run it.**

## Install (ordinary cPanel / shared hosting)

1. Get the plugin ZIP (built on a development machine or CI with `pnpm build:plugin`; the ZIP contains the compiled
   editor in `assets/`).
2. WordPress admin: **Plugins > Add New > Upload Plugin**, choose the ZIP, **Install Now**, **Activate**.
3. You are taken once to **Settings > RK Builder > Setup**. Fix anything marked Problem, then click
   **Create a test page** and open it in RK Builder to confirm the editor works.
4. Open **RK Builder** in the admin menu (or **Open in RK Builder** on any page in **Pages**).

A source checkout of this repository has no `assets/` folder. The editor screen then says so and points at
`pnpm build:plugin`; the plugin never fatals because of it.

### Requirements

| | Required | Recommended |
| --- | --- | --- |
| PHP | 7.4 | 8.1 or newer |
| WordPress | 5.5 (every REST route declares a permission callback, which core expects from 5.5) | 5.9 or newer |
| REST API | enabled (security plugins that block `/wp-json/rk/v1/` break the editor) | |
| Permalinks | any | pretty permalinks (Settings > Permalinks) |
| Uploads | writable `wp-content/uploads` (for images) | |
| Other | `wp_json_encode` (core) | WordPress cron is not needed for rendering |

## Settings (Settings > RK Builder)

| Section | Setting | Meaning |
| --- | --- | --- |
| General | Enable RK Builder | Off: the editor screen shows a "disabled" message and public rendering stops. Saved layouts stay. |
| General | Public rendering mode | *Inside the active theme* (layout replaces the page content) or *Standalone template*. |
| Security | Allowed image hosts | Extra hosts allowed in absolute image URLs (one per line or comma separated). Your own site is always allowed. |
| Security | Preview link lifetime | 60 to 86400 seconds (default 900). |
| Security | Maximum revisions | 1 to 200 revisions kept per page (default 20). |
| Security | Allowed origins | **Only for direct cross-origin browser use** (a separately hosted editor or site calling this WordPress from the browser). Exact `scheme://host[:port]` values, no wildcards. Leave empty for a normal install. |
| Content | Service / Portfolio content types | Turn the two custom post types on or off. |
| Content | Default grid limit | Items shown by grid blocks when none is set (1 to 24). |
| Cache | Purge known cache plugins | Call supported cache plugins' purge functions after publish/unpublish. |
| Cache | **Purge public cache now** | Button; clears cached public pages immediately. |
| Uninstall | Delete all RK Builder data | See "Uninstall" below. Off by default. |
| Diagnostics | versions, REST status, permalinks, last migration, last public render error | Read-only. **Export diagnostics** downloads JSON with no secrets, tokens, nonces or page content. |

Constants in `wp-config.php` and the filters below keep priority over the stored settings.
Settings are stored in the single option `rk_builder_settings`; invalid values are clamped or reset to defaults.

## Setup wizard

Shown once after activation (not for network-wide or bulk activation) and always available as the **Setup** tab.
Each check reports **OK / Warning / Problem** with a message: PHP version, WordPress version, REST API routes,
pretty permalinks, uploads folder writable, bundled editor files present, content types, and the number of
prototype layouts found. **Create a test page** adds a draft page called "RK Builder test page" with a small layout
(a draft revision made through the normal save path; clicking again reuses the same page).

## Migrating prototype layouts (Tools > RK Builder migration)

For sites that used the prototype, whose pages carry the old `_rk_layout` meta (flat blocks).

1. **Back up first**: the database (and uploads). Export your theme configuration if you use one.
2. Open **Tools > RK Builder migration** and click **Run dry-run report**. Nothing is written. For every page the report
   shows what would be migrated, skipped or **needs manual fix**, plus every change: restructured blocks, `services` to
   `service`, generated ids (`hero-3fa9c1`), added default props, converted values and any dropped field.
3. Fix pages listed as *needs manual fix* (the report names the block and field that failed validation, for example an
   image without alt text). Those pages are never migrated automatically.
4. Tick the confirmation box and click **Run migration**. Each migrated page gets its layout stored as a **draft**
   revision. Review it in RK Builder and publish when happy.

Guarantees: `_rk_layout` is never deleted; `_rk_layout_published` and the page status are never touched; pages that
already have a draft are skipped unless you tick **Overwrite existing drafts** (the replaced draft stays in the revision
history); drafts are saved with the normal `draft` revision kind. The outcome is recorded in the option
`rk_builder_last_migration` (`time, user, dry_run, migrated, skipped, failed`) and shown under Diagnostics.

### Upgrades

The option `rk_builder_schema_version` records the data schema. On `plugins_loaded`, if it is lower than the current
version, incremental steps run in order; the result is recorded in `rk_builder_schema_upgrade` (`status ok|failed`).
A failed step stops the run and keeps the last good version. Steps never delete data (none are needed at v1).

## Uninstall

Deleting the plugin (Plugins > Delete) **always** removes only bookkeeping: the per-page save locks
(`rk_builder_lock_*`) and `rk_builder_*` transients (setup flag, migration reports).

It deletes content-bearing data **only if you opt in**, by ticking the setting **Delete all RK Builder data**, or by defining
`define( 'RK_BUILDER_DELETE_DATA_ON_UNINSTALL', true );` in `wp-config.php` before deleting. Then it also removes:

- page meta `_rk_layout_draft`, `_rk_layout_published`, `_rk_revision`, `_rk_published_revision`, `_rk_published_at`,
  `_rk_revisions`, `_rk_builder_test_page`;
- the option `rk_theme_config` and every option beginning with `rk_builder_` (settings, schema version, last migration,
  last render error, allowed origins, app URL).

**Never deleted, with or without the opt-in:** pages and their content, Service/Portfolio posts and categories, uploaded
media, and the prototype's `_rk_layout` meta.

## Configuration constants (wp-config.php)

`RK_BUILDER_PREVIEW_SECRET`, `RK_BUILDER_REVALIDATE_URL`, `RK_BUILDER_REVALIDATE_SECRET` (signed webhook to an external
frontend), `RK_BUILDER_ALLOWED_ORIGINS`, `RK_BUILDER_ALLOWED_IMAGE_HOSTS`, `RK_BUILDER_MAX_REVISIONS`,
`RK_BUILDER_FRONTEND_URL`, `RK_BUILDER_APP_URL` / `RK_BUILDER_APP_CSS_URL` (serve the editor from elsewhere; by default the
bundled `assets/builder.js` and `assets/builder.css` are used), `RK_BUILDER_DELETE_DATA_ON_UNINSTALL`. See
[../DEPLOYMENT.md](../DEPLOYMENT.md) for headless setups.

## Hooks reference

Filters:

| Filter | Purpose |
| --- | --- |
| `rk_builder_seo_title`, `rk_builder_seo_description`, `rk_builder_canonical_url`, `rk_builder_og_image` | Adjust the SEO values the renderer prints for a page (`$value, $page_id`). |
| `rk_builder_log_level` | Verbosity of the plugin's safe diagnostic log (default `warning`). |
| `rk_builder_load_google_fonts` | Return true to load the approved Google Fonts on public pages (default off). |
| `rk_builder_allowed_image_hosts` | Hosts allowed in absolute image URLs (sees the merged list: site hosts, constant, setting). |
| `rk_builder_allowed_origins` | Origins allowed for direct cross-origin browser access (exact match; no wildcards). |
| `rk_builder_max_revisions` | Revisions kept per page; wins over the constant and the setting. Clamped 1 to 200. |
| `rk_builder_preview_ttl` | Preview link lifetime in seconds. |
| `rk_builder_app_url`, `rk_builder_app_css_url` | URL of the editor bundle and stylesheet (default: bundled files). |
| `rk_builder_frontend_url` | Where "Preview link" sends editors (default: this site). |
| `rk_builder_assets_dir` | Folder holding the bundled assets (rarely needed). |
| `rk_builder_schema_steps` | Register data-schema upgrade steps: `array( version => callable )`. |
| `rk_builder_environment` | Override what the setup checks see (support/testing). |

Actions:

| Action | When |
| --- | --- |
| `rk_builder_layout_changed` | A page's layout was saved, published, restored or unpublished. |
| `rk_builder_theme_changed` | The global theme was saved. |
| `rk_builder_public_cache_purge` | The public cache for a page (or all, id `0`) should be purged. |

## Layout

```text
rk-builder/
  rk-builder.php           bootstrap, constants, activation hook
  includes/settings.php    settings data contract: defaults, rk_builder_setting(), sanitiser
  includes/setup.php       Settings > RK Builder, setup wizard, diagnostics, admin-post handlers
  includes/migration.php   Tools > RK Builder migration, prototype layout conversion, schema upgrade steps
  includes/assets.php      bundled editor assets and their URLs
  includes/admin.php       the editor screen (standalone document, nonce mode), Open in RK Builder links
  includes/validation.php  PURE PHP (no WordPress calls): strict layout/theme validation, URL rules
  includes/storage.php     post meta + revisions + save lock + theme option
  includes/rest.php, builder.php, content.php, preview.php, revalidate.php, cors.php   REST API and content
  includes/renderer.php, seo.php, cache.php, ...   public rendering, SEO, cache purge
  assets/                  built editor (builder.js, builder.css, builder.asset.php) and site.css; created by the build
  uninstall.php            see "Uninstall"
tests/                     plain-PHP runner (no composer): php wp-plugin/tests/run.php
```

Run the tests: `php wp-plugin/tests/run.php` (shared fixtures from `../contracts/`). Real-WordPress checks:
`pnpm smoke:wp` (REST) and `pnpm test:wp` / `pnpm test:wp-admin` (full stack). The validator mirrors
`client/src/lib/schema`; change both together and add a fixture under `../contracts/`.

Storage keys: `_rk_layout_draft`, `_rk_layout_published`, `_rk_revision`, `_rk_published_revision`, `_rk_published_at`,
`_rk_revisions`; options `rk_theme_config`, `rk_builder_settings`.
