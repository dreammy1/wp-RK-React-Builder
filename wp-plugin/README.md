# RK Builder (Headless) — WordPress plugin

Storage, REST API, permissions, revisions and content endpoints for the RK React Builder. Version `1.0.0`, PHP ≥ 7.4.

```text
rk-builder/
  rk-builder.php          bootstrap, constants, hooks
  includes/validation.php PURE PHP (no WordPress calls): strict layout/theme validation, URL rules, canonical JSON
  includes/storage.php    post meta + revisions + save lock + theme option
  includes/rest.php       routes and permission callbacks (stable rk_* error codes)
  includes/builder.php    authenticated handlers: pages, layout, revisions, publish, media
  includes/content.php    CPTs + taxonomies, featured_image field, /content, /public/page
  includes/preview.php    HMAC preview tokens          includes/revalidate.php  signed frontend webhook
  includes/cors.php       exact-match origin allow-list  includes/admin.php  standalone wp-admin screen (nonce mode)
tests/                    plain-PHP runner (no composer): php wp-plugin/tests/run.php
```

Run the tests: `php wp-plugin/tests/run.php` (shared fixtures from `../contracts/`). Real-WordPress checks:
`pnpm smoke:wp` (REST) and `pnpm test:wp` / `pnpm test:wp-admin` (full stack). Configuration constants are listed in
[DEPLOYMENT.md](../DEPLOYMENT.md). Storage keys: `_rk_layout_draft`, `_rk_layout_published`, `_rk_revision`,
`_rk_published_revision`, `_rk_revisions`; option `rk_theme_config`.

The validator mirrors `client/src/lib/schema` — change both together and add a fixture under `../contracts/`.
