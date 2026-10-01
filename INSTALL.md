# Install RK Builder (all-in-one) on WordPress / cPanel

One ZIP, no Node.js, no Docker, no SSH. Works on ordinary shared hosting.

## Requirements

WordPress 5.5+ (5.9+ recommended), PHP 7.4+ (8.1+ recommended), REST API enabled, HTTPS recommended, pretty
permalinks recommended (_Settings → Permalinks → Post name_), writable `wp-content/uploads` for media.

## 1. Get the ZIP

Download `rk-builder-all-in-one-vX.Y.Z.zip` from the GitHub **Releases** page (or the `rk-builder-all-in-one` artifact of a
CI run). Developers can build it: `pnpm install && pnpm build:plugin` → `dist/rk-builder-all-in-one.zip`.

## 2. Back up

Export the database and `wp-content/uploads` with your host's backup tool before installing anything.

## 3. Install and activate

**Plugins → Add New Plugin → Upload Plugin →** choose the ZIP **→ Install Now → Activate Plugin.**

## 4. Run the setup check

After activation you land on **Settings → RK Builder → Setup** (once). It checks PHP and WordPress versions, the REST API,
permalinks, uploads, bundled assets and content types. Fix anything marked ✗; ⚠ items are recommendations. Press
**Create a test page** to get a ready draft page.

## 5. Build a page

1. **RK Builder** (left menu) → pick a page (or use **Open in RK Builder** under a page on _Pages → All Pages_).
2. Add blocks, edit them on the right, drag or use the ↑/↓ buttons to reorder.
3. **Save draft** — nothing is public yet. **Preview link** opens the draft (valid 15 minutes, `noindex`).
4. **Publish**. The page appears at its normal WordPress URL, rendered by PHP inside your theme.
5. Services/Portfolio grids show the posts under **Services** / **Portfolio** (give them featured images).

## 6. Settings worth knowing (Settings → RK Builder)

- **Public rendering:** _Inside my theme_ (default: the layout replaces the page content in your theme's page template) or
  _Standalone_ (a minimal full-page template without theme chrome).
- **Allowed image hosts**, **preview lifetime**, **revisions kept** (default 20), **default grid size**.
- **Cache:** purge buttons and known cache-plugin integration (WP Super Cache, W3 Total Cache, LiteSpeed, WP Rocket).
- **Diagnostics:** versions, REST check, last migration, last render error; **Export diagnostics** (no secrets).

## 7. Coming from the old prototype / headless version?

**Tools → RK Builder migration** finds old `_rk_layout` data, shows a **dry run**, and only on your confirmation saves the
converted layouts as _drafts_. It never deletes the old data and never publishes. Pages needing manual fixes are listed.

## Updating

Upload the new ZIP the same way and choose _Replace current with uploaded_. Stored layouts, revisions and theme are kept.
Roll back with the previous ZIP (and the backup if a release changed stored data). Per page: **History → Restore**.

## Uninstalling

Deleting the plugin removes only temporary locks and settings caches. **Layouts, revisions, the theme, pages, Service/Portfolio
posts and media are kept.** To purge builder data on uninstall, tick _Delete builder data when the plugin is deleted_ in
Settings (or define `RK_BUILDER_DELETE_DATA_ON_UNINSTALL` as `true` in `wp-config.php`) _before_ deleting.

## Troubleshooting

| Symptom                                   | Fix                                                                                                                       |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| RK Builder screen says assets are missing | You installed a source checkout, not the built ZIP — use the release ZIP                                                  |
| Screen is blank                           | Check the browser console; confirm nothing blocks `wp-content/plugins/rk-builder/assets/builder.js` (security plugin/WAF) |
| _Not permitted_ when saving               | Your role lacks `edit_post` for that page (editors/admins have it); theme saving needs an Administrator                   |
| Page looks unstyled                       | Cache plugin serving old CSS: _Settings → RK Builder → Purge public cache_                                                |
| Duplicate title/meta tags                 | Another SEO plugin is active: RK defers to Yoast, Rank Math, SEOPress, AIOSEO and The SEO Framework automatically         |
| Preview link 404                          | It expired (15 min) or was altered — create a new one                                                                     |
