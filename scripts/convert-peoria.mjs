#!/usr/bin/env node
/**
 * Convert the "Peoria Hardwood Floors" Next.js site into RK Builder site-export bundles.
 *
 *   gh repo clone mefahim/peoria-hardwood-floors /tmp/peoria
 *   node scripts/convert-peoria.mjs --src /tmp/peoria [--out dist/peoria] [--ref main]
 *
 * Output (import in order with Import site; each file is small enough to import in well under a minute):
 *   1-core.json      reusable blocks, Home, About, Contact, Services, Pricing, Estimate; theme + the 6 services as content
 *   2-services.json  the six service pages
 *   3-catalog.json   Finishes, Stains, Products
 *   4-gallery.json   Gallery
 *   REPORT.md        what was converted, what could not be, and the old → new URL map
 *
 * Content is read from the repo's data files WITHOUT executing any of its code: TypeScript literals are evaluated
 * from the syntax tree and anything that is not a plain literal is rejected. Images stay at their GitHub raw URLs in
 * the bundle; "Import site" copies them into the WordPress media library.
 */
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import ts from "typescript";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const argv = process.argv.slice(2);
const flag = (n, d) => {
  const i = argv.indexOf(n);
  return i >= 0 ? argv[i + 1] : d;
};
const src = resolve(flag("--src", ""));
const out = resolve(flag("--out", join(root, "dist", "peoria")));
const REF = flag("--ref", "main");
const REPO = flag("--repo", "mefahim/peoria-hardwood-floors");
const fail = m => {
  console.error(`convert-peoria: ${m}`);
  process.exit(1);
};
if (!flag("--src") || !existsSync(join(src, "lib", "site.ts")))
  fail("pass --src <clone of the Peoria repo> (needs lib/site.ts)");

/* ---------- literal extraction (no code execution) ---------- */
function literal(node) {
  if (ts.isStringLiteralLike(node)) return node.text;
  if (ts.isNumericLiteral(node)) return Number(node.text);
  if (node.kind === ts.SyntaxKind.TrueKeyword) return true;
  if (node.kind === ts.SyntaxKind.FalseKeyword) return false;
  if (ts.isArrayLiteralExpression(node)) return node.elements.map(literal);
  if (ts.isParenthesizedExpression(node) || ts.isAsExpression(node))
    return literal(node.expression);
  if (ts.isObjectLiteralExpression(node)) {
    const o = {};
    for (const p of node.properties) {
      if (!ts.isPropertyAssignment(p)) throw new Error("unsupported property");
      const k =
        ts.isIdentifier(p.name) || ts.isStringLiteralLike(p.name)
          ? p.name.text
          : null;
      if (k === null) throw new Error("unsupported key");
      o[k] = literal(p.initializer);
    }
    return o;
  }
  throw new Error(`not a plain literal: ${ts.SyntaxKind[node.kind]}`);
}
function extract(file, name) {
  const text = readFileSync(join(src, file), "utf8");
  const sf = ts.createSourceFile(
    file,
    text,
    ts.ScriptTarget.Latest,
    true,
    file.endsWith("x") ? ts.ScriptKind.TSX : ts.ScriptKind.TS
  );
  let found;
  const visit = n => {
    if (
      ts.isVariableDeclaration(n) &&
      n.name.getText(sf) === name &&
      n.initializer
    )
      found = literal(n.initializer);
    ts.forEachChild(n, visit);
  };
  visit(sf);
  if (found === undefined)
    fail(`${file}: const ${name} not found or not a plain literal`);
  return found;
}

const site = extract("lib/site.ts", "site");
const serviceAreas = extract("lib/site.ts", "serviceAreas");
const services = extract("lib/site.ts", "services");
const finishes = extract("lib/site.ts", "finishes");
const stains = extract("lib/site.ts", "stains");
const clients = extract("lib/site.ts", "clients");
const galleryImages = extract("lib/site.ts", "galleryImages");
const products = extract("app/(site)/products/ProductsClient.tsx", "products");
const catalog = extract(
  "app/(site)/products/ProductsClient.tsx",
  "catalogProducts"
);

/* ---------- media ---------- */
const media = new Map(); // url -> entry
let nextMediaId = 1000;
const badImages = new Map(); // repo path -> what it really is
const sniff = file => {
  const b = readFileSync(file).subarray(0, 16);
  const ascii = b.toString("latin1");
  if (b[0] === 0xff && b[1] === 0xd8 && b[2] === 0xff) return "jpeg";
  if (ascii.startsWith("\x89PNG")) return "png";
  if (ascii.startsWith("GIF8")) return "gif";
  if (ascii.startsWith("RIFF") && ascii.slice(8, 12) === "WEBP") return "webp";
  if (ascii.slice(4, 8) === "ftyp")
    return /avif|avis/.test(ascii.slice(8, 16)) ? "avif" : "heic";
  return "unknown";
};
const rawUrl = p => {
  const rel = p.replace(/^\//, "");
  const file = join(src, "public", rel);
  if (!existsSync(file)) fail(`image not found in the repo: public/${rel}`);
  const kind = sniff(file);
  if (kind === "heic" || kind === "unknown") {
    badImages.set(
      rel,
      kind === "heic"
        ? "HEIC photo saved with a .jpeg name (browsers cannot show it)"
        : "not an image browsers can show"
    );
    return null;
  }
  return `https://raw.githubusercontent.com/${REPO}/${REF}/public/${rel.split("/").map(encodeURIComponent).join("/")}`;
};
const useImage = (p, alt, title = "") => {
  const url = rawUrl(p);
  if (url === null) return null;
  if (url === null) return null;
  if (!media.has(url))
    media.set(url, { id: nextMediaId++, url, alt, title: title || alt });
  return media.get(url);
};

/* ---------- block builders ---------- */
const clip = (s, n) => (s.length <= n ? s : s.slice(0, n - 1).trimEnd() + "…");
class Page {
  constructor(slug, title) {
    this.slug = slug;
    this.title = title;
    this.blocks = [];
    this.n = 0;
  }
  add(type, props) {
    this.blocks.push({
      id: `${type}-${String(++this.n).padStart(2, "0")}`,
      type,
      props,
    });
    return this;
  }
  hero({ heading, sub = "", cta = "", ctaHref = "", bg }) {
    const props = {
      heading: clip(heading, 160),
      sub: clip(sub, 400),
      cta: clip(cta, 60),
      ctaHref,
    };
    const bgImage = bg ? useImage(bg, "") : null;
    if (bgImage) props.bgUrl = bgImage.url;
    return this.add("hero", props);
  }
  h(text, level = 2) {
    return this.add("heading", { text: clip(text, 200), level });
  }
  p(...paras) {
    return this.add("text", { text: clip(paras.join("\n\n"), 5000) });
  }
  bullets(items) {
    return this.p(...items.map(i => `• ${i}`));
  }
  img(path, alt) {
    const image = useImage(path, alt);
    if (!image) return this; // unusable file: reported, not imported
    return this.add("image", {
      url: image.url,
      alt: clip(alt, 300),
      decorative: false,
    });
  }
  cta(heading, cta, ctaHref) {
    return this.add("cta", {
      heading: clip(heading, 160),
      cta: clip(cta, 60),
      ctaHref,
    });
  }
  grid(title, limit = 6) {
    return this.add("services", {
      title,
      source: "service",
      limit,
      cols: 3,
      category: "",
      orderBy: "menu_order",
      order: "asc",
    });
  }
  ref(key) {
    return this.add("reusable", { refId: REUSABLE_IDS[key] });
  }
  contact(props) {
    return this.add("contact", props);
  }
  spacer(h = 24) {
    return this.add("spacer", { h });
  }
  json(extra = {}) {
    return {
      slug: this.slug,
      title: this.title,
      wasPublished: true,
      layout: { version: 1, blocks: this.blocks },
      ...extra,
    };
  }
}

const tel = site.phoneHref;
const callLabel = `Call ${site.phone}`;

/* ---------- reusable library ---------- */
const REUSABLE_IDS = { contact: 11, cta: 12, area: 13, products: 14 };
const reusables = [
  {
    id: REUSABLE_IDS.contact,
    slug: "contact-details",
    name: "Contact details",
    block: {
      type: "contact",
      props: {
        heading: "Talk to the team",
        intro:
          "Phone and email are the fastest way to reach us. Share a few details and we will help you figure out the right next step.",
        phone: site.phone,
        email: site.email,
        address: `Peoria & Central Illinois (${site.serviceRadius})`,
        hours: "",
      },
    },
  },
  {
    id: REUSABLE_IDS.cta,
    slug: "call-to-action",
    name: "Call-to-action band",
    block: {
      type: "cta",
      props: {
        heading: "Ready to talk about your floors?",
        cta: callLabel,
        ctaHref: tel,
      },
    },
  },
  {
    id: REUSABLE_IDS.area,
    slug: "service-area",
    name: "Service area",
    block: {
      type: "text",
      props: {
        text: clip(
          `Serving Peoria & Central Illinois. We travel to homes and businesses ${site.serviceRadius}. If you're nearby and not listed, give us a call — chances are we cover your town.\n\nTowns we serve: ${serviceAreas.join(", ")}.`,
          5000
        ),
      },
    },
  },
  {
    id: REUSABLE_IDS.products,
    slug: "trusted-products",
    name: "Trusted flooring products",
    block: {
      type: "text",
      props: {
        text: `We work with trusted flooring products: ${clients.join(" · ")}.`,
      },
    },
  },
];

/* ---------- pages ---------- */
const pages = {};
const add = p => (pages[p.slug] = p);

// Home
{
  const p = new Page("home", "Home");
  p.hero({
    heading: "Hardwood floors, crafted and cared for in Peoria.",
    sub: "Installation, sanding & refinishing, custom stains, and durable finishes for homes and businesses across Peoria and the surrounding Central Illinois communities.",
    cta: callLabel,
    ctaHref: tel,
    bg: "/images/hero-kitchen.png",
  });
  p.p(
    "FOCUSED ON WOOD\n\nInstallation, sanding, refinishing, stains, and finishes for homes and businesses across Peoria and Central Illinois."
  );
  p.h("A trusted, family-owned hardwood specialist");
  p.img("/images/about-craft.png", "Craftsman hand-finishing a hardwood floor");
  p.p(
    "We treat every floor like it's in our own home — careful prep, honest recommendations, and a finish that holds up to real life. From a single room refresh to a full commercial install, we bring the same attention to detail.",
    "No pushy sales, no fine-print surprises. Just clear guidance and quality workmanship you can stand on for years."
  );
  p.bullets([
    "Wood — focused specialty",
    "75 mi — service radius from Peoria",
    "Family — owned & operated",
  ]);
  p.h("Six ways we care for wood");
  p.p(
    "Separate expertise for every kind of project — each done to the same high standard."
  );
  p.grid("What we do", 6);
  p.ref("area");
  p.h("Clear guidance for your flooring project");
  p.bullets([
    "01 — Assess the space and existing floor",
    "02 — Compare materials, stains, and finishes",
    "03 — Plan the right next step for the project",
  ]);
  p.ref("products");
  p.ref("cta");
  add(p);
}
// About
{
  const p = new Page("about", "About");
  p.hero({
    heading: "Rooted in Peoria, built on trust.",
    sub: "A family-owned hardwood flooring company that treats your home and business like our own.",
    cta: callLabel,
    ctaHref: tel,
    bg: "/images/about-craft.png",
  });
  p.h("Hardwood is all we do — and we do it right");
  p.p(
    "Peoria Hardwood Floors is a family-owned business serving homeowners and businesses throughout Peoria and the surrounding Central Illinois region. We specialize entirely in wood — installation, sanding, refinishing, staining, and durable finishing — so every project gets focused, experienced hands.",
    "We believe a floor should be beautiful and honest. That means clear communication, realistic timelines, and recommendations based on what your floor truly needs. If a lower-cost sandless refresh will do the job, we'll tell you — we're not here to oversell.",
    "From a single worn room to a full commercial gymnasium, we bring the same standard of care and finish every time."
  );
  p.img(
    "/images/new-images/IMG_0216.jpg",
    "Freshly refinished hardwood floor in a bright living room"
  );
  p.h("Values you can stand on");
  for (const [t, b] of [
    [
      "Family owned",
      "You work directly with the people doing the work — not a call center. We stand behind every floor we touch.",
    ],
    [
      "Craftsmanship first",
      "Careful prep, precise sanding, and clean detail work around cabinets, stairs, and transitions.",
    ],
    [
      "Honest guidance",
      "We recommend what your floor actually needs — including sandless when a full refinish isn't necessary.",
    ],
    [
      "Quality materials",
      "Trusted finishes and stains from Bona, Rubio Monocoat, and DuraSeal for lasting results.",
    ],
  ])
    p.h(t, 3).p(b);
  p.ref("area");
  p.ref("cta");
  add(p);
}
// Contact
{
  const p = new Page("contact", "Contact");
  p.hero({
    heading: "Tell us what your floor needs.",
    sub: "Phone and email are the fastest way to reach the team. Share a few details and we will help you figure out the right next step.",
    cta: callLabel,
    ctaHref: tel,
    bg: "/images/hero-kitchen.png",
  });
  p.ref("contact");
  p.h("Peoria and Central Illinois.");
  p.p(
    "We serve Peoria and surrounding communities within roughly 75 miles. If you are nearby and unsure whether we cover your project, call — we are happy to talk it through."
  );
  add(p);
}
// Services index
{
  const p = new Page("services", "Services");
  p.hero({
    heading: "Everything we do with wood.",
    sub: "Focused expertise for residential and commercial projects across Peoria and Central Illinois.",
    cta: callLabel,
    ctaHref: tel,
    bg: "/images/new-images/IMG_1224.jpeg",
  });
  p.h("Start with the condition of the wood and the way you use the space.");
  p.p(
    "New floors call for material and subfloor planning. Existing floors may need a full refinish, a lower-disruption sandless refresh, or a focused repair. Commercial, deck, and cabinet work each has its own preparation and scheduling considerations."
  );
  p.grid("Our services", 12);
  p.ref("area");
  p.ref("cta");
  add(p);
}
// Pricing
{
  const p = new Page("pricing", "Pricing");
  p.hero({
    heading: "A clear conversation before the work begins.",
    sub: "Every floor is different. We prefer to look at the space, understand the scope, and give you a useful estimate instead of hiding behind a one-size-fits-all price.",
    cta: callLabel,
    ctaHref: tel,
    bg: "/images/new-images/IMG_0214.jpg",
  });
  p.h("Good pricing starts with the right questions.");
  p.p(
    "Square footage is only part of the story. We account for preparation, materials, access, details, and the finish you want so the recommendation fits the project—not just a calculator. The calculator is planning guidance; final scope and pricing are assessed for the specific space."
  );
  for (const [t, b] of [
    [
      "Installation",
      "Material, layout, subfloor preparation, trim, and installation method all shape the final range.",
    ],
    [
      "Sanding & refinishing",
      "Room count, repairs, stain selection, finish system, and the existing floor's condition matter most.",
    ],
    [
      "Sandless refinishing",
      "A lower-disruption refresh for floors with a sound existing finish and surface-level wear.",
    ],
    [
      "Deck & cabinet refinishing",
      "Exterior prep, board condition, cabinet count, color changes, and finish choice affect the scope.",
    ],
  ])
    p.h(t, 3).p(b);
  p.h("No surprises. No pressure.");
  p.bullets([
    "A clear scope before scheduling",
    "Material and finish options explained in plain language",
    "Recommendations based on your home, business, and budget",
    "A local team serving Peoria and Central Illinois",
  ]);
  p.ref("cta");
  add(p);
}
// Estimate calculator (interactive in the original)
{
  const p = new Page("estimate-calculator", "Estimate");
  p.hero({
    heading: "Get a rough estimate.",
    sub: "The online calculator is not part of this page. Call or email and we will talk through your space and give you a useful number.",
    cta: callLabel,
    ctaHref: tel,
  });
  p.p(
    "Pricing depends on the space, the material, the finish and the condition of the existing floor. Share the room sizes and what you have in mind and we will help you plan the right next step."
  );
  p.ref("contact");
  add(p);
}
// Service detail pages
for (const s of services) {
  const p = new Page(s.slug, s.title);
  p.hero({
    heading: s.hero,
    sub: s.short,
    cta: callLabel,
    ctaHref: tel,
    bg: s.image,
  });
  p.h("Who it's for");
  p.p(s.who);
  p.h("How the process works");
  p.p(...s.process.map((x, i) => `${i + 1}. ${x}`));
  p.h("What affects your price");
  p.bullets(s.factors);
  p.h("Frequently asked");
  for (const f of s.faqs) p.h(f.q, 3).p(f.a);
  p.ref("contact");
  p.ref("cta");
  add(p);
}
// Finishes
{
  const p = new Page("finishes", "Finishes");
  p.hero({
    heading: "Finish is where the floor becomes yours.",
    sub: "We help you compare sheen, durability, maintenance, and the feel you want underfoot — then recommend the right system for your space.",
    cta: callLabel,
    ctaHref: tel,
    bg: "/images/finish-water.png",
  });
  for (const f of finishes) {
    p.img(f.image, `${f.name} finish`);
    p.h(f.name, 3);
    p.p(`${f.family} · ${f.sheen}`, `Best for: ${f.bestFor}.`, f.notes);
  }
  p.h("See samples in your own light.");
  p.p(
    "Screen colors and online photos are useful for direction, but they cannot show exactly how a finish will look in your home. We bring the conversation back to real samples, your wood species, and your lighting."
  );
  p.bullets([
    "Compare sheen without guesswork",
    "Understand cure and maintenance needs",
    "Choose a finish that fits your everyday life",
  ]);
  p.ref("cta");
  add(p);
}
// Stains
{
  const p = new Page("stains", "Stains");
  p.hero({
    heading: "Start with a tone. Finish with a sample.",
    sub: "Explore stain directions we work with, then narrow the choice with real samples on your actual wood.",
    cta: callLabel,
    ctaHref: tel,
    bg: "/images/finish-oil.png",
  });
  for (const st of stains) {
    p.img(st.image, `${st.name} stain on hardwood`);
    p.h(st.name, 3);
    p.p(`${st.family} · ${st.tone} tone · ${st.brand}`, st.notes);
  }
  p.ref("products");
  p.ref("cta");
  add(p);
}
// Products
{
  const p = new Page("products", "Products");
  p.hero({
    heading: "A floor that feels like it belongs there.",
    sub: "A wide variety of wood flooring options, from classic unfinished oak to custom patterns, reclaimed boards, and specialty materials sourced for your project.",
    cta: callLabel,
    ctaHref: tel,
    bg: "/images/new-images/IMG_1778.JPG",
  });
  for (const pr of products) {
    p.img(pr.image, pr.title);
    p.h(pr.title, 3);
    p.p(pr.body);
    p.bullets(pr.details);
  }
  p.h("Product catalog");
  for (const c of catalog) {
    p.h(c.title, 3);
    p.p(c.source);
    p.bullets(c.items);
  }
  p.h("Need help narrowing it down?");
  p.p(
    "Tell us what you are imagining and we will help you compare species, grades, widths and finishes."
  );
  p.ref("cta");
  add(p);
}
// Gallery
{
  const p = new Page("gallery", "Gallery");
  p.hero({
    heading: "Floors with a story to tell.",
    sub: "A look at installations, refinishing, stains, and custom detail from our recent projects.",
    cta: callLabel,
    ctaHref: tel,
    bg: "/images/new-images/IMG_0214.jpg",
  });
  const groups = new Map();
  for (const g of galleryImages)
    (groups.get(g.service) ?? groups.set(g.service, []).get(g.service)).push(g);
  for (const [service, imgs] of groups) {
    p.h(service, 2);
    for (const g of imgs) p.img(g.src, g.alt);
  }
  p.ref("cta");
  add(p);
}

/* ---------- content posts (services grid) ---------- */
const content = services.map((s, i) => ({
  type: "service",
  slug: s.slug,
  title: s.title,
  status: "publish",
  excerpt: s.short,
  content: s.who,
  order: i + 1,
  terms: [],
  featured: useImage(s.image, s.title)?.id ?? null,
}));

/* ---------- theme ---------- */
const logo = useImage("/images/peoriahardwoodfloors-logo.png", site.name);
const theme = {
  version: 1,
  primary: "#94704a",
  bg: "#ffffff",
  ink: "#111111",
  font: "Georgia",
  logoUrl: logo.url,
  social: {},
  header: { sticky: true },
  footer: { columns: 3 },
};

/* ---------- bundles ---------- */
const mediaFor = pageList => {
  const urls = new Set();
  for (const pg of pageList)
    for (const b of pg.blocks) {
      if (b.type === "image") urls.add(b.props.url);
      if (b.type === "hero" && b.props.bgUrl) urls.add(b.props.bgUrl);
    }
  return urls;
};
function bundle(pageSlugs, { withTheme = false, withContent = false } = {}) {
  const list = pageSlugs.map(s => pages[s]);
  const urls = mediaFor(list);
  if (withTheme) urls.add(logo.url);
  if (withContent)
    for (const c of content)
      for (const m of media.values()) if (m.id === c.featured) urls.add(m.url);
  return {
    format: "rk-builder-site",
    version: 1,
    exportedAt: new Date().toISOString(),
    source: { url: `https://github.com/${REPO}`, plugin: "convert-peoria" },
    theme: withTheme ? theme : null,
    media: [...media.values()].filter(m => urls.has(m.url)),
    reusables,
    pages: list.map(pg => pg.json()),
    content: withContent ? content : [],
  };
}
const serviceSlugs = services.map(s => s.slug);
const files = {
  "1-core.json": bundle(
    ["home", "about", "contact", "services", "pricing", "estimate-calculator"],
    { withTheme: true, withContent: true }
  ),
  "2-services.json": bundle(serviceSlugs),
  "3-catalog.json": bundle(["finishes", "stains", "products"]),
  "4-gallery.json": bundle(["gallery"]),
};
mkdirSync(out, { recursive: true });
for (const [name, b] of Object.entries(files))
  writeFileSync(join(out, name), JSON.stringify(b, null, 2));

/* ---------- report ---------- */
const lines = [];
lines.push(
  "# Peoria Hardwood Floors → RK Builder",
  "",
  `Source: https://github.com/${REPO} (${REF}). Generated by scripts/convert-peoria.mjs.`,
  ""
);
lines.push(
  "## Import order",
  "",
  "Use **Import site** (RK Builder → page list) with each file. Everything arrives as drafts.",
  ""
);
lines.push("| File | Contents | Options to tick |", "|---|---|---|");
lines.push(
  "| `1-core.json` | Reusable blocks, Home, About, Contact, Services, Pricing, Estimate; the six services as Service posts | **Theme**, **Services and projects** |"
);
lines.push(
  "| `2-services.json` | The six service pages | — |",
  "| `3-catalog.json` | Finishes, Stains, Products | — |",
  "| `4-gallery.json` | Gallery | — |",
  ""
);
lines.push("## Reusable blocks (linked)", "");
for (const r of reusables)
  lines.push(
    `- **${r.name}** (\`${r.block.type}\`) — placed on ${Object.values(pages).filter(pg => pg.blocks.some(b => b.type === "reusable" && b.props.refId === r.id)).length} pages`
  );
lines.push(
  "",
  "Edit one in the editor (Inspector → Update everywhere) and every page that uses it changes.",
  ""
);
lines.push(
  "## Pages and URL map",
  "",
  "| Original | RK Builder page (slug) | Blocks | Images |",
  "|---|---|---|---|"
);
const old = s =>
  s === "home" ? "/" : serviceSlugs.includes(s) ? `/services/${s}` : `/${s}`;
for (const pg of Object.values(pages))
  lines.push(
    `| \`${old(pg.slug)}\` | \`/${pg.slug}/\` | ${pg.blocks.length} | ${[...mediaFor([pg])].length} |`
  );
lines.push(
  "",
  "WordPress page slugs are flat, so the six service pages move from `/services/<slug>` to `/<slug>`. Add 301 redirects (RK SEO → Redirects) from the old URLs to the new ones.",
  ""
);
lines.push(
  "## Not converted",
  "",
  "- **Visualizer** (`/visualizer`): an AI image tool with uploads, quota and lead capture. Not representable as blocks; keep it on the Next.js site or rebuild separately.",
  "- **Estimate calculator**: interactive; replaced by a short contact page.",
  "- **Header, footer and navigation**: RK Builder's theme has colors, logo, social links, sticky header and footer columns only. Navigation comes from your WordPress menu; the footer content is the reusable *Contact details* block.",
  "- **Gallery filter, product catalog dialog, stain swatches, animations, structured data**: the images and text are converted as plain blocks. SEO metadata is not carried over; set titles and descriptions in RK SEO.",
  "- **Layout**: RK Builder stacks blocks in one column, so multi-column grids become sequences of blocks.",
  ""
);
if (badImages.size) {
  lines.push(
    "## Images skipped",
    "",
    "These files are in the repo but are not valid web images, so they were left out (they are broken on the original site too):",
    ""
  );
  for (const [f, why] of badImages) lines.push(`- \`public/${f}\` — ${why}`);
  lines.push("");
}
lines.push(
  "## Counts",
  "",
  `- ${Object.keys(pages).length} pages, ${reusables.length} reusable blocks, ${content.length} service posts, ${media.size} images.`,
  ""
);
writeFileSync(join(out, "REPORT.md"), lines.join("\n"));
console.log(`wrote ${Object.keys(files).length} bundles + REPORT.md to ${out}`);
console.log(
  `${Object.keys(pages).length} pages, ${reusables.length} reusables, ${content.length} services, ${media.size} images`
);
