# Create a new website theme

A **theme** in RK Builder is a complete, installable website: the pages, the shared header and footer, the colours and
fonts, the content types and their templates, the sample entries, and the search settings. You build it once in the
dashboard, save it as a theme, and install it on any other WordPress site that has RK Builder. This guide walks through
building one from scratch, the SEO standards to follow, and the file format if you would rather write a theme by hand or
convert an existing site.

> Everything below happens in **wp-admin → RK Builder** (the dashboard). You do not need wp-admin's own pages.

---

## 1. What goes into a theme

| Part                                           | Where you make it                   | Included when you save a theme |
| ---------------------------------------------- | ----------------------------------- | ------------------------------ |
| Colours, font, logo                            | Editor → **Theme** tab              | yes                            |
| Header and footer                              | **Templates → Header / Footer**     | yes                            |
| Pages                                          | **Pages** → editor                  | yes (as drafts on install)     |
| Reusable blocks                                | Editor → _Save as reusable block_   | yes                            |
| Content types, fields                          | **Types & fields**                  | yes                            |
| Entry, listing, card templates, 404 page       | **Templates**                       | yes                            |
| Sample entries                                 | **Content**                         | yes (with their images)        |
| Page and entry SEO                             | Pages → _Search & sharing_, Content | yes                            |
| Site title, tagline, favicon, business details | **Site & SEO**                      | business details and favicon   |

Not included: the WordPress admin users, plugins, and the media library as such (only the images the theme uses, which are
copied in on install).

---

## 2. Build it, step by step

### Step 1: Site basics (Site & SEO)

1. **Site title** and **tagline**. The title is added to every page title: `Page | Site title`.
2. **Favicon**: choose or upload a square PNG, at least 512 × 512 px. It shows in browser tabs and bookmarks on every page
   (builder pages and theme pages alike).
3. **Default social image** (1200 × 630 px). Used wherever a page or entry has no image of its own.
4. **Business details** and **Local business**: name, phone, email, address, opening hours, service areas, and your
   Google Business Profile and social links. They become structured data for search engines.
5. Leave **Let search engines index this site** off while you are building, and switch it on at launch. WordPress does not
   publish a sitemap while it is off.

### Step 2: Look and feel (Theme tab in the editor)

Open any page in the editor and use the **Theme** tab (administrators only):

- **Primary**, **Canvas background**, **Ink** colours: every block uses these tokens, so change them here, not per block.
- **Type system**: Space Grotesk, IBM Plex Mono or Georgia.
- **Logo**, social links, sticky header, footer columns (1–4).

Pick colours with enough contrast: body text on the background should be at least **4.5 : 1**.

### Step 3: Header and footer (Templates)

Go to **Templates → New template → Header** and again for **Footer**.

- A header starts as the site's navbar (logo, links, phone button); a footer starts as the site footer (columns, contact
  details, copyright). Edit them like any page: change links (one per line as `Label|/path`), add a phone number,
  switch the navbar's _overlay_ option on if you want it to float over a hero image.
- Publish each one and switch **Use on site** on. From then on **one** live header and **one** live footer replace the
  navbar and footer blocks on every page and every template. Change them once and the whole site follows.
- Only one header and one footer are live at a time. Switching a new one on switches the old one off.
- A page that opens with a hero lets the header float over it; every other page gets a solid bar with room under it.
- **Drop-down menus**: in _Menu links_, start a line with `- ` to put it under the line above:

  ```
  Home|/
  Services|/services
  - Hardwood installation|/services/hardwood
  - Refinishing|/services/refinishing
  About|/about
  ```

  On a computer the sub-menu opens on hover, keyboard focus or a click on the small arrow; on a phone it is listed under its parent in the menu. One level, up to 8 items each. Add a short description after a second bar to show it under the item: `- Hardwood installation|/services/hardwood|New floors, any species`.

- **Appearance** (select the navbar block): logo size, bar colour (automatic, light, dark or the primary colour), bar height, menu next to the logo or centred, a shadow, button style, and an **extra button** (for example "Get a quote") next to the phone button. The bar colour applies whenever the bar is solid; on a page that opens with a hero and has _Overlay the hero below_ on, the bar is transparent until the visitor scrolls.
- **Footer options** (select the footer block): colour (dark, light or primary), social links (`Facebook|https://facebook.com/yourpage`), bottom links (`Privacy|/privacy`), plus the columns, contact details and small print.

### Step 4: Pages (Pages)

Create the pages the site needs, usually Home, About, Services, Contact. In the editor:

- Add blocks from the palette. Start pages with a **Hero** or **Cover hero** (it carries the page's single H1).
- Use **Heading** blocks for sections (H2, H3). Never skip a level and never add a second H1.
- Give every image an **alt text** (or mark it decorative).
- Save as **Save draft**, then **Publish**. Use **Pages → Search & sharing** to set each page's search title and
  description.

If you need the same block on many pages (a call-to-action band, an offer strip), use **Save as reusable block** so it can
be edited in one place.

### Step 5: Content types and fields (Types & fields)

For anything that is a list of similar items (projects, team members, properties, events) create a content type:

1. **New type**: singular and plural name, web address, whether it has a listing page, and category groups.
2. **Fields**: short text, long text, number, email, link, date, colour, choice list, yes/no, image, gallery and repeater
   (rows of sub-fields).
3. Choose the type's **schema** for search engines: _Web page_, _Article_ (blog posts, news) or _Service_.
4. Optionally set the listing page's title and description.

The built-in **Post** type is your blog: it uses the Article schema by default.

### Step 6: Templates for your content (Templates)

Create, in this order:

1. **Card**: the small card used inside lists (image, category, title, summary).
2. **Archive / listing**: the type's list page (a Loop grid with search, filters and page numbers). Pick the Card template in
   the Loop grid's options.
3. **Single page**: how one entry looks (image, title, details, text, gallery, related entries).
4. **404 page**: what visitors see for a missing address.

Each starts from a layout built from the type's fields. Publish it, then switch **Use on site** on. A template that is not
published and in use never changes the website.

### Step 7: Sample content (Content)

Add 3 to 6 sample entries per type, with images, so the theme looks complete the first time it is installed. They travel
with the theme.

### Step 8: Check, then save as a theme (Themes)

1. Open the live pages on a phone and a desktop. Check the header, footer, listing, one entry, and a missing address.
2. **Themes → Save this site as a theme**: give it a name, description, version and author. Saving again with the same
   name replaces the package.
3. To use it on another site: on that site open **Themes**, **Import theme file**, then **Install**. Use **Check first** to see
   what would change.
4. To hand it to someone: **Export** downloads one `.json` file.

---

## 3. SEO standards for every page and entry

The editor shows a live checklist next to each page and entry. These are the limits it checks.

| Item               | Standard                                                                                        |
| ------------------ | ----------------------------------------------------------------------------------------------- |
| Search title       | **30–60 characters** (articles), 15–60 (other pages). Put the topic first.                      |
| Search description | **120–160 characters** (articles), 70–160 (other pages). One clear promise.                     |
| Address (slug)     | Lowercase, words joined with hyphens, **60 characters or fewer**, no dates.                     |
| H1                 | **Exactly one** per page, the title. The article text must not add another.                     |
| Sub-headings       | An **H2 every 200–300 words**; H3 only under an H2.                                             |
| Article length     | At least **300 words** (the minimum for an indexed article); **800–2,000** ranks best.          |
| Reading time       | Shown automatically (about 200 words a minute, never below 1 minute).                           |
| Paragraphs         | 2–4 sentences. Use lists for steps and options.                                                 |
| Images             | Alt text up to ~125 characters; compress to under 200 KB; featured image at least 1200 px wide. |
| Social image       | 1200 × 630 px, set per page or as the site default.                                             |
| Internal links     | 2–5 links to related pages or articles; descriptive link text, not "click here".                |
| Favicon            | Square PNG, 512 × 512 px or more.                                                               |
| Do not index       | Thank-you pages, internal search, anything duplicated: tick **Hide from search engines**.       |

What the theme prints for you: canonical address, Open Graph and Twitter tags, `robots` rules, and structured data
(Organization, WebSite, WebPage or Article or Service, Breadcrumbs). Articles also carry their publish and update dates and
word count. Pages and entries marked noindex are left out of the sitemap (`/wp-sitemap.xml`).

### Writing a blog post

Open **Content → Posts → New**. The text box has buttons for **H2**, **H3**, bold, italic, a bullet list and a quote, and
shows the **word count and reading time** as you type.

1. Title: the main keyword near the start, 30–60 characters.
2. Summary: 120–160 characters. It is the search description and the lead paragraph on the page.
3. Text: open with the answer, then sections each with an H2. Add a list or a quote where it helps.
4. Featured image, category, and a custom address if the title is long.
5. Check the **Search & sharing** checklist: every line should show ✓ before you publish.

The article template shows, in this order: category, headline (H1), author, date and reading time, the featured image, the
summary as a standfirst, the text in a comfortable reading column (about 70 characters wide), and "Keep reading" with
related posts.

---

## 4. Writing a theme by hand (the file format)

A theme file is a normal site export plus a `themeMeta` block, so it also works with **Import site**.

```json
{
  "format": "rk-builder-site",
  "version": 1,
  "themeMeta": {
    "name": "Studio",
    "slug": "studio",
    "description": "A clean studio website",
    "version": "1.0.0",
    "author": "Your name"
  },
  "theme": {
    "version": 1,
    "primary": "#C7F36B",
    "bg": "#F8F5ED",
    "ink": "#1B2430",
    "font": "Space Grotesk",
    "header": { "sticky": true },
    "footer": { "columns": 3 }
  },
  "pages": [
    {
      "slug": "home",
      "title": "Home",
      "wasPublished": true,
      "layout": {
        "version": 1,
        "blocks": [
          {
            "id": "h1",
            "type": "heading",
            "props": { "text": "Welcome", "level": 2 }
          },
          {
            "id": "t1",
            "type": "text",
            "props": { "text": "Hello from the Studio theme." }
          }
        ]
      }
    }
  ],
  "types": [],
  "templates": [],
  "entries": []
}
```

Rules:

- A page layout is `{ "version": 1, "blocks": [ { "id", "type", "props" } ] }`. The ids are unique inside the page.
  Every block type has a fixed set of props and limits; the importer checks them with the same validator as the editor, and an
  invalid page is **skipped and listed**, never half-imported. The easiest way to get a correct block is to make it in the
  editor and read it back from **Export** in the editor toolbar. `contracts/valid/` has complete working layouts.
- **Templates** carry `kind` (`single`, `archive`, `loop`, `notfound`, `header` or `footer`), the `postType` they are for
  (empty for `notfound`, `header` and `footer`), a `slug` unique in the file, and a `layout`. Set `"active": true` to
  switch one on at install. Card ids inside Loop grid blocks are re-linked automatically.
- **Types** are the saved type definitions (fields, schema, archive title and description).
- **Entries** list `type`, `slug`, `title`, `status`, `excerpt`, `content`, `terms`, `featured` and `fields`.
- **Images** can stay at their web address; the importer downloads them into the media library (JPEG, PNG, GIF, WebP,
  AVIF; never SVG).
- Limits per file: 8 MB, 500 pages, 150 images, 300 entries.
- Always **Check first** (a dry run) before installing a hand-written file.

### Converting an existing website

`scripts/convert-peoria.mjs` is a worked example that turns a Next.js site into bundles (pages, reusable blocks, theme,
services). Copy it, point it at your source site's data, and adapt the page mapping.

```bash
node scripts/convert-peoria.mjs
node scripts/wp-import-bundle.mjs dist/peoria/1-core.json --theme --content
```

The second command rehearses the import on a throwaway WordPress before you touch a real site.

---

## 5. Launch checklist

- [ ] Site title, tagline, favicon and default social image set
- [ ] Colours pass the 4.5 : 1 contrast check
- [ ] Header and footer templates published and **in use**
- [ ] Every page has one H1, a search title and a search description
- [ ] Every image has alt text
- [ ] Types, card, listing and single templates published and **in use**; a 404 template in use
- [ ] Sample entries added with images
- [ ] Posts pass the Search & sharing checklist (300+ words, an H2, description 120–160)
- [ ] Phone check: menu opens, text is readable, buttons are easy to tap
- [ ] **Let search engines index this site** switched on, then `/wp-sitemap.xml` opened to confirm it lists your content
- [ ] Theme saved (**Themes → Save this site as a theme**) and exported as a backup

## 6. If something looks wrong

| Symptom                                      | Fix                                                                                                        |
| -------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| The header or footer did not change          | The template must be **published** and **Use on site** switched on.                                        |
| Two headers show                             | A page-level navbar is only replaced when a live header template exists; publish it.                       |
| An entry page still looks like the old theme | The single template for that type is not published, or not in use.                                         |
| `/wp-sitemap.xml` is a 404 page              | **Let search engines index this site** is off (Site & SEO), or permalinks need a save.                     |
| An imported page is missing                  | It was invalid; the import report lists it with the reason.                                                |
| Images did not arrive                        | The source site was unreachable, or the host is not allowed (Settings → RK Builder → Allowed image hosts). |
