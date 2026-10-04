# Kit Library: sell or share site kits

The Kit Library lets a site browse a catalogue of kits and add one to its **Themes** in a click. A kit is the zip made by
**Themes → Download kit (.zip)** (see [CREATE-A-THEME.md](CREATE-A-THEME.md)). The catalogue is one JSON file, so any
static host or CDN can serve a library: there is no server to run.

## Publish a library

1. Put your kit zips in one folder. Next to a zip you can add a picture with the same name (`studio-1.0.0.zip` +
   `studio-1.0.0.jpg`) that becomes its preview.
2. Build the catalogue:

   ```bash
   node scripts/build-kit-catalogue.mjs ./kits --base https://kits.example.com/files/ --name "Example Kits"
   ```

   It reads each kit's `manifest.json`, adds the SHA-256 and the size, and writes `./kits/index.json`. Mark paid kits with
   `--require-key studio-kit,other-kit`.

3. Upload the whole folder (zips, pictures, `index.json`) over **https**.
4. On any site: **Themes → Kit Library**, paste the address of `index.json` (for example
   `https://kits.example.com/files/index.json`), **Connect**.

Re-run step 2 and re-upload when you add a kit or release a new version. Sites see a cached list for an hour; **Refresh**
fetches it again. A kit whose version in the catalogue is newer than the one on the site shows **Update**.

## The catalogue file

```json
{
  "format": "rk-kit-catalogue",
  "version": 1,
  "name": "Example Kits",
  "kits": [
    {
      "id": "studio-kit",
      "name": "Studio Kit",
      "description": "A portfolio site for design studios.",
      "version": "1.2.0",
      "author": "Example",
      "industry": "Design",
      "license": "Regular license",
      "price": "$49",
      "preview": "studio-1.2.0.jpg",
      "demo": "https://demo.example.com/studio",
      "download": "studio-1.2.0.zip",
      "sha256": "…64 hex characters…",
      "bytes": 7694164,
      "requires": "1.25.0",
      "tags": ["Design"],
      "requiresKey": true
    }
  ]
}
```

`id`, `name`, `download` and `sha256` are required; a kit without them is ignored. Relative `download`, `preview` and
`demo` addresses are read against the catalogue's own address. At most 100 kits and 1 MB per catalogue.

## What a site does with it

- Everything is **https only**, fetched with WordPress' safe HTTP client (no private addresses).
- The download is checked against `sha256` before it is opened. A mismatch is refused and nothing is added.
- The zip then goes through the same checks as an uploaded kit: only `manifest.json`, `site.json` and `images/`, size
  limits, a plugin-version check (`requires`).
- Adding puts the kit in **Themes**. It is not installed until the buyer presses **Install** (the wizard).

## Selling kits

The library itself does not take payments. Two simple models:

- **Free or open library**: anyone with the address can add kits.
- **Licence key**: the buyer pastes a key next to the address (**Kit Library → Settings**). The plugin sends it as an
  `X-RK-License` header, and only to the catalogue's own host, never to another host. Make your server (or a Cloudflare
  Worker, an nginx rule, or your store's download handler) answer `403` for a missing or wrong key on the files you
  protect, and the plugin tells the buyer the key was not accepted. Keep the zips of paid kits behind that check; the
  catalogue (names, previews, prices) can stay public.

The plugin never stores the key anywhere it can be read back through the API.

## Setting the address from code

For a white-label plugin you can pre-set the library:

```php
define( 'RK_BUILDER_KIT_LIBRARY_URL', 'https://kits.example.com/files/index.json' );
// or: add_filter( 'rk_builder_kit_library_url', fn() => 'https://kits.example.com/files/index.json' );
```
