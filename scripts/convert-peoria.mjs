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
const clean = v =>
  String(v)
    .replace(/[|\n;]/g, v2 => (v2 === ";" ? "," : " "))
    .trim();
const rows = list =>
  list
    .map(r => r.map(x => String(x).replace(/[|\n]/g, " ").trim()).join("|"))
    .join("\n");
const clip = (s, n) => (s.length <= n ? s : s.slice(0, n - 1).trimEnd() + "…");
const EYEBROWS = {
  about: "Our story",
  contact: "Start a conversation",
  gallery: "Selected work",
  "estimate-calculator": "A rough starting point",
  stains: "Color direction",
  pricing: "Pricing guidance",
  finishes: "The final layer",
  services: "Services",
  products: "Flooring products",
  visualizer: "AI flooring visualizer",
};
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
    // Mirrors the source: the home hero fills the screen with two buttons; every other page has a shorter header with no buttons.
    const home = this.slug === "home";
    const props = {
      crumb: home ? "" : (this.crumb ?? this.title),
      eyebrow: home
        ? "Family owned · Central Illinois"
        : (EYEBROWS[this.slug] ?? "Service"),
      heading: clip(heading, 160),
      sub: clip(sub, 400),
      cta: home ? clip(cta, 60) : "",
      ctaHref: home ? ctaHref : "",
      cta2: home ? "Get a rough estimate" : "",
      cta2Href: home ? "/estimate-calculator" : "",
      size: home ? "screen" : "page",
    };
    const bgImage = bg ? useImage(bg, "") : null;
    if (bgImage) props.bgUrl = bgImage.url;
    return this.add("coverhero", props);
  }
  split({
    eyebrow = "",
    heading,
    body = "",
    facts = "",
    cta = "",
    ctaHref = "",
    img,
    alt = "",
    side = "left",
    tone = "light",
    links = "",
  }) {
    const props = {
      eyebrow,
      heading: clip(heading, 200),
      body: clip(body, 3000),
      facts,
      cta,
      ctaHref,
      imageAlt: clip(alt, 300),
      side,
      tone,
    };
    if (links) props.links = links;
    const image = img ? useImage(img, alt) : null;
    if (image) props.imageUrl = image.url;
    return this.add("split", props);
  }
  section({
    eyebrow = "",
    heading,
    body = "",
    linkLabel = "",
    linkHref = "",
    tone = "light",
  }) {
    return this.add("section", {
      eyebrow,
      heading: clip(heading, 200),
      body: clip(body, 3000),
      linkLabel,
      linkHref,
      tone,
    });
  }
  panel(o) {
    return this.add("panel", {
      mode: "rows",
      eyebrow: "",
      heading: "",
      body: "",
      checks: "",
      actions: "",
      items: "",
      itemStyle: "feature",
      flip: false,
      kicker: "",
      box: false,
      tone: "light",
      ...o,
    });
  }
  values({
    eyebrow = "",
    heading,
    items,
    cols = 4,
    tone = "muted",
    quote = false,
  }) {
    return this.add("values", {
      eyebrow,
      heading,
      items: rows(items.map(i => [i[0], i[1]])),
      cols,
      tone,
      ...(quote ? { quote: true } : {}),
    });
  }
  // cards: [{ image, eyebrow, title, blurb, specs: [[k,v]], bullets: [..], href }]
  cards({
    eyebrow = "",
    heading = "",
    intro = "",
    cols = 3,
    tone = "light",
    numbered = false,
    items,
    filters = false,
    modals = null,
    joined = false,
  }) {
    const line = c => {
      const img =
        c.image && !c.image.startsWith("#")
          ? (useImage(c.image, c.title)?.url ?? "")
          : (c.image ?? "");
      return [
        img,
        c.eyebrow ?? "",
        c.title,
        c.blurb ?? "",
        (c.specs ?? []).map(([k, v]) => clean(`${k}: ${v}`)).join("; "),
        (c.bullets ?? []).map(clean).join("; "),
        c.href ?? "",
      ]
        .map((v, i) => (i === 4 || i === 5 ? v : clean(v)))
        .join("|");
    };
    const props = {
      eyebrow,
      heading,
      intro,
      items: items.map(line).join("\n"),
      cols,
      tone,
      numbered,
    };
    if (filters) props.filters = true;
    if (joined) props.joined = true;
    if (modals) {
      // One pop-up per card, matched in order: image|Title|Intro|item; item
      props.modals = modals.items
        .map(m =>
          [
            useImage(m.image, m.title)?.url ?? "",
            clean(m.title),
            clean(m.source),
            m.items.map(clean).join("; "),
          ].join("|")
        )
        .join("\n");
      props.modalLabel = modals.label;
      props.modalCta = modals.cta;
    }
    return this.add("catalog", props);
  }
  gallery(items) {
    const line = g => {
      const image = useImage(g.src, g.alt);
      return image ? [image.url, g.service, g.alt].map(clean).join("|") : null;
    };
    return this.add("gallery", {
      items: items.map(line).filter(Boolean).join("\n"),
      filters: true,
    });
  }
  detail(o) {
    return this.add("detail", o);
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
      layout: {
        version: 1,
        blocks: [
          {
            id: "site-header",
            type: "reusable",
            props: { refId: REUSABLE_IDS.header },
          },
          ...this.blocks,
          {
            id: "site-footer",
            type: "reusable",
            props: { refId: REUSABLE_IDS.footer },
          },
        ],
      },
      ...extra,
    };
  }
}

const tel = site.phoneHref;
const callLabel = `Call ${site.phone}`;

/* ---------- reusable library ---------- */
// The source's primary nav, on the flat URLs used here. /visualizer is a page of blocks that sends visitors to the live tool.
function primaryNavLinks() {
  return [
    "About|/about",
    "Services|/services",
    "Finishes|/finishes",
    "Stains|/stains",
    "Products|/products",
    "Gallery|/gallery",
    "Pricing|/pricing",
    "Visualizer|/visualizer",
  ].join("\n");
}

const REUSABLE_IDS = {
  contact: 11,
  cta: 12,
  area: 13,
  products: 14,
  header: 15,
  footer: 16,
};
const reusables = [
  {
    id: REUSABLE_IDS.header,
    slug: "site-header",
    name: "Site header",
    block: {
      type: "navbar",
      props: {
        brand: site.name,
        logoUrl: "__LOGO__",
        links: primaryNavLinks(),
        phone: site.phone,
        phoneHref: site.phoneHref,
        overlay: true,
      },
    },
  },
  {
    id: REUSABLE_IDS.footer,
    slug: "site-footer",
    name: "Site footer",
    block: {
      type: "sitefooter",
      props: {
        brand: site.name,
        tagline: clip(String(site.tagline ?? ""), 300),
        colATitle: "Services",
        colALinks: services.map(s => `${s.title}|/${s.slug}`).join("\n"),
        colBTitle: "Explore",
        colBLinks: [
          "About|/about",
          "Finishes|/finishes",
          "Stains|/stains",
          "Products|/products",
          "Gallery|/gallery",
          "Estimate Calculator|/estimate-calculator",
          "Flooring Visualizer|/visualizer",
          "Contact|/contact",
        ].join("\n"),
        contactTitle: "Get in touch",
        phone: site.phone,
        email: site.email,
        address: clip(
          `Serving Peoria & surrounding Central Illinois communities, ${site.serviceRadius}.`,
          300
        ),
        copyright: `© ${new Date().getFullYear()} ${site.name}. Family owned & operated.`,
        note: "Estimates are rough guides — final pricing depends on site conditions and project scope.",
      },
    },
  },
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
      type: "contactband",
      props: {
        heading: "Ready to talk about your floors?",
        sub: "Call or email to discuss your project and the right next step. We answer real questions — no fragile contact forms.",
        phone: site.phone,
        email: site.email,
      },
    },
  },
  {
    id: REUSABLE_IDS.area,
    slug: "service-area",
    name: "Service area",
    block: {
      type: "section",
      props: {
        eyebrow: "Where we work",
        heading: "Serving Peoria & Central Illinois",
        body: clip(
          `We travel to homes and businesses ${site.serviceRadius}. If you're nearby and not listed, give us a call — chances are we cover your town.`,
          3000
        ),
        linkLabel: "",
        linkHref: "",
        tone: "muted",
        pill: "Peoria and nearby Central Illinois communities",
      },
    },
  },
  {
    id: REUSABLE_IDS.products,
    slug: "trusted-products",
    name: "Trusted flooring products",
    block: {
      type: "brandstrip",
      props: {
        label: "We work with trusted flooring products",
        items: clients.join("\n"),
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
  p.add("section", {
    eyebrow: "Focused on wood",
    heading: "",
    body: "Installation, sanding, refinishing, stains, and finishes for homes and businesses across Peoria and Central Illinois.",
    linkLabel: "",
    linkHref: "",
    tone: "light",
    center: true,
  });
  p.split({
    eyebrow: "Who we are",
    heading: "A trusted, family-owned hardwood specialist",
    body: "We treat every floor like it's in our own home — careful prep, honest recommendations, and a finish that holds up to real life. From a single room refresh to a full commercial install, we bring the same attention to detail.\n\nNo pushy sales, no fine-print surprises. Just clear guidance and quality workmanship you can stand on for years.",
    facts:
      "Wood|Focused specialty\n75 mi|Service radius from Peoria\nFamily|Owned & operated",
    img: "/images/about-craft.png",
    alt: "Craftsman hand-finishing a hardwood floor",
    links: "More about us|/about\nTalk through your project|/contact",
  });
  p.section({
    eyebrow: "What we do",
    heading: "Six ways we care for wood",
    body: "Separate expertise for every kind of project — each done to the same high standard.",
    linkLabel: "All services",
    linkHref: "/services",
  });
  p.cards({
    cols: 3,
    joined: true,
    items: services.slice(0, 6).map(s => ({
      image: s.image,
      title: s.title,
      blurb: s.short,
      href: `/${s.slug}`,
    })),
  });
  p.split({
    eyebrow: "New — flooring visualizer",
    heading: "See your room with a new floor.",
    body: "Answer a few quick questions about your space and style, and our assistant generates a visual concept to help you explore directions before we talk. It's an approximate guide — final color and finish decisions always use real samples in your own lighting.",
    cta: "Try the visualizer",
    ctaHref: "/visualizer",
    img: "/images/cta-room.png",
    alt: "Living room concept showing new hardwood flooring",
    side: "right",
    tone: "muted",
  });
  p.ref("area");
  p.values({
    eyebrow: "A thoughtful process",
    heading: "Clear guidance for your flooring project",
    items: [
      ["01", "Assess the space and existing floor"],
      ["02", "Compare materials, stains, and finishes"],
      ["03", "Plan the right next step for the project"],
    ],
    cols: 3,
    tone: "light",
    quote: true,
  });
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
    bg: "/images/about-craft.png",
  });
  p.add("section", {
    eyebrow: "Focused on wood",
    heading: "",
    body: "Installation, sanding, refinishing, stains, and finishes for homes and businesses across Peoria and Central Illinois.",
    linkLabel: "",
    linkHref: "",
    tone: "light",
    center: true,
  });
  p.split({
    eyebrow: "Who we are",
    heading: "Hardwood is all we do — and we do it right",
    body: "Peoria Hardwood Floors is a family-owned business serving homeowners and businesses throughout Peoria and the surrounding Central Illinois region. We specialize entirely in wood — installation, sanding, refinishing, staining, and durable finishing — so every project gets focused, experienced hands.\n\nWe believe a floor should be beautiful and honest. That means clear communication, realistic timelines, and recommendations based on what your floor truly needs. If a lower-cost sandless refresh will do the job, we'll tell you — we're not here to oversell.\n\nFrom a single worn room to a full commercial gymnasium, we bring the same standard of care and finish every time.",
    img: "/images/new-images/IMG_0216.jpg",
    alt: "Freshly refinished hardwood floor in a bright living room",
    side: "right",
  });
  p.values({
    eyebrow: "What we stand for",
    heading: "Values you can stand on",
    items: [
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
    ],
  });
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
    bg: "/images/hero-kitchen.png",
  });
  p.panel({
    mode: "rows",
    flip: true,
    box: true,
    itemStyle: "contact",
    kicker: "Reach us directly",
    items: rows([
      ["Call or text", site.phone, tel],
      ["Email us", site.email, `mailto:${site.email}`],
    ]),
    actions: "Review our services|/services",
    eyebrow: "Where we work",
    heading: "Peoria and Central Illinois.",
    body: "We serve Peoria and surrounding communities within roughly 75 miles. If you are nearby and unsure whether we cover your project, call — we are happy to talk it through.",
  });
  add(p);
}
// Services index
{
  const p = new Page("services", "Services");
  p.hero({
    heading: "Everything we do with wood.",
    sub: "Focused expertise for residential and commercial projects across Peoria and Central Illinois.",
    bg: "/images/new-images/IMG_1224.jpeg",
  });
  p.section({
    eyebrow: "Choose the right starting point",
    heading:
      "Start with the condition of the wood and the way you use the space.",
    body: "New floors call for material and subfloor planning. Existing floors may need a full refinish, a lower-disruption sandless refresh, or a focused repair. Commercial, deck, and cabinet work each has its own preparation and scheduling considerations.",
    linkLabel: "Talk through your project",
    linkHref: "/contact",
  });
  services.forEach((s, i) =>
    p.split({
      eyebrow: String(i + 1).padStart(2, "0"),
      heading: s.title,
      body: s.short,
      cta: "View service",
      ctaHref: `/${s.slug}`,
      img: s.image,
      alt: s.title,
      side: i % 2 === 1 ? "right" : "left",
    })
  );
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
    bg: "/images/new-images/IMG_0214.jpg",
  });
  p.panel({
    mode: "rows",
    eyebrow: "What affects cost",
    heading: "Good pricing starts with the right questions.",
    body: "Square footage is only part of the story. We account for preparation, materials, access, details, and the finish you want so the recommendation fits the project—not just a calculator. The calculator is planning guidance; final scope and pricing are assessed for the specific space.",
    actions:
      "Try the estimate calculator|/estimate-calculator\nReview services|/services\nTalk through your project|/contact",
    items: rows([
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
    ]),
  });
  p.panel({
    mode: "intro",
    tone: "muted",
    eyebrow: "Our promise",
    heading: "No surprises. No pressure.",
    checks:
      "A clear scope before scheduling\nMaterial and finish options explained in plain language\nRecommendations based on your home, business, and budget\nA local team serving Peoria and Central Illinois",
  });
  p.ref("cta");
  add(p);
}
// Estimate calculator (interactive in the original)
{
  const p = new Page("estimate-calculator", "Estimate");
  p.crumb = "Estimate Calculator";
  p.hero({
    heading: "Get a feel for the range.",
    sub: "Use this simple calculator for planning only. Your final price depends on site conditions, materials, prep, and project scope.",
    bg: "/images/service-refinishing.png",
  });
  // Same rates as the source's EstimateCalculatorClient.tsx
  p.add("calculator", {
    heading: "Project details",
    types: [
      "Sand & refinish|5.5|Approximate square feet",
      "New installation|8|Approximate square feet",
      "Sandless refresh|3.5|Approximate square feet",
      "Deck refinishing|4.5|Approximate square feet",
      "Cabinet refinishing|85|Number of doors / drawers",
    ].join("\n"),
    amount: 800,
    resultLabel: "Planning range",
    note: "This is a rough planning number, not a quote. It does not include unusual prep, repairs, stairs, furniture moving, or material upgrades.",
    ctaLabel: "Talk through your project",
    ctaHref: "/contact",
  });
  add(p);
}
// Service detail pages
const SERVICE_LINKS = {
  "hardwood-floor-installation-peoria-il": [
    "Compare flooring products|/products",
  ],
  "hardwood-floor-refinishing-peoria-il": [
    "Compare finishes|/finishes",
    "Explore stain directions|/stains",
  ],
  "sandless-floor-refinishing-peoria-il": [
    "Compare full refinishing|/hardwood-floor-refinishing-peoria-il",
  ],
  "commercial-sports-flooring-central-illinois": [
    "View selected work|/gallery",
  ],
  "deck-refinishing-peoria-il": ["View selected work|/gallery"],
  "cabinet-refinishing-peoria-il": ["Review finish directions|/finishes"],
};
for (const s of services) {
  const p = new Page(s.slug, s.title);
  p.crumb = `Services|/services\n${s.title}`;
  p.hero({ heading: s.hero, sub: s.short, bg: s.image });
  p.detail({
    eyebrow: "Who it's for",
    heading: s.title,
    body: s.who,
    note: "",
    stepsTitle: "How the process works",
    steps: s.process.join("\n"),
    factorsTitle: "What affects your price",
    factorsIntro:
      "Every project is unique — these are the main factors we weigh when quoting.",
    factors: s.factors.join("\n"),
    links: [
      ...(SERVICE_LINKS[s.slug] ?? []),
      "Talk through your project|/contact",
    ].join("\n"),
    faqTitle: "Frequently asked",
    faq: rows(s.faqs.map(f => [f.q, f.a])),
    asideTitle: "Discuss your project",
    asideText:
      "Tell us about your space and we'll give honest guidance and a realistic estimate — no pressure.",
    phone: site.phone,
    ctaLabel: "Try the estimate calculator",
    ctaHref: "/estimate-calculator",
  });
  p.cards({
    heading: "Explore other services",
    tone: "muted",
    items: services
      .filter(o => o.slug !== s.slug)
      .slice(0, 3)
      .map(o => ({
        image: o.image,
        title: o.title,
        blurb: o.short,
        href: `/${o.slug}`,
      })),
  });
  p.ref("cta");
  add(p);
}
// Finishes
{
  const p = new Page("finishes", "Finishes");
  p.hero({
    heading: "Finish is where the floor becomes yours.",
    sub: "We help you compare sheen, durability, maintenance, and the feel you want underfoot — then recommend the right system for your space.",
    bg: "/images/finish-water.png",
  });
  p.cards({
    items: finishes.map(f => ({
      image: f.image,
      eyebrow: f.family,
      title: f.name,
      blurb: f.notes,
      specs: [
        ["Sheen", f.sheen],
        ["Best for", f.bestFor],
      ],
    })),
  });
  p.panel({
    mode: "intro",
    tone: "muted",
    eyebrow: "A better decision",
    heading: "See samples in your own light.",
    body: "Screen colors and online photos are useful for direction, but they cannot show exactly how a finish will look in your home. We bring the conversation back to real samples, your wood species, and your lighting.",
    checks:
      "Compare sheen without guesswork\nUnderstand cure and maintenance needs\nChoose a finish that fits your everyday life",
    actions:
      "Explore refinishing|/hardwood-floor-refinishing-peoria-il\nTalk through your finish|/contact",
  });
  p.ref("cta");
  add(p);
}
// Stains
{
  const p = new Page("stains", "Stains");
  p.hero({
    heading: "Start with a tone. Finish with a sample.",
    sub: "Explore stain directions we work with, then narrow the choice with real samples on your actual wood.",
    bg: "/images/finish-oil.png",
  });
  p.cards({
    cols: 4,
    filters: true,
    items: stains.map(st => ({
      image: st.color,
      title: st.name,
      blurb: `${st.brand} · ${st.tone}`,
    })),
  });
  p.panel({
    mode: "intro",
    heading: "",
    body: "Stain chips are directional only. Final color varies with wood species, age, preparation, application, and lighting. We recommend choosing from samples made for your floor.",
    actions:
      "Compare finish options|/finishes\nDiscuss refinishing|/hardwood-floor-refinishing-peoria-il\n!Request a sample conversation|/contact",
  });
  add(p);
}
// Products
{
  const p = new Page("products", "Products");
  p.hero({
    heading: "A floor that feels like it belongs there.",
    sub: "Peoria Hardwood Floors offers a wide variety of wood flooring options, from classic unfinished oak to custom patterns, reclaimed boards, and specialty materials sourced for your project.",
    bg: "/images/new-images/IMG_1778.JPG",
  });
  p.section({
    eyebrow: "One page, every direction",
    heading: "More than a catalog. A place to start the conversation.",
    body: "We work with a range of manufacturers, mills, importers, and reclaimed flooring specialists. Options can be reviewed against your project specifications, including species, width, stain, finish, and texture where available.",
  });
  p.cards({
    numbered: true,
    items: products.map(pr => ({
      image: pr.image,
      title: pr.title,
      blurb: pr.body,
      bullets: pr.details,
    })),
    modals: {
      items: catalog,
      label: "View all products",
      cta: `Ask about this category|/contact; Call ${site.phone}|${tel}`,
    },
  });
  p.panel({
    mode: "rows",
    tone: "muted",
    eyebrow: "Need help narrowing it down?",
    heading: "Tell us what you are imagining.",
    body: "Feel free to reach out. We would be happy to help with your flooring needs, talk through samples, and source a product that fits your space, budget, and daily life.",
    actions: `Plan an installation|/hardwood-floor-installation-peoria-il\n!Talk with us|/contact\nCall ${site.phone}|${tel}`,
  });
  p.ref("cta");
  add(p);
}
// Visualizer: the form and preview are a block; the plugin's Settings > Visualizer picks the image backend.
{
  const p = new Page("visualizer", "Visualizer");
  p.crumb = "";
  p.hero({
    heading: "Picture a new direction for your room.",
    sub: "Upload a room photo, choose the look you like, and generate an AI-assisted visual concept of your space with new flooring. It's a visual concept to help you explore options — not an exact rendering, a guaranteed color match, or a construction-ready plan.",
  });
  p.add("visualizer", {
    cities: serviceAreas.join("\n"),
    submitLabel: "Create my floor visualization",
    ctaLabel: `Talk with ${site.name}`,
    ctaHref: "/contact",
  });
  add(p);
}
// Gallery
{
  const p = new Page("gallery", "Gallery");
  p.hero({
    heading: "Floors with a story to tell.",
    sub: "A look at installations, refinishing, stains, and detail work across Peoria and Central Illinois.",
    bg: "/images/new-images/IMG_0214.jpg",
  });
  p.panel({
    mode: "intro",
    eyebrow: "Visual context",
    heading: "Explore the kinds of work we discuss.",
    body: "Use the filters to browse installation, refinishing, finish, deck, cabinet, commercial, and detail-work examples. The images are visual direction; project scope and final selections depend on the space.",
    actions: "Review services|/services\nTalk through a project|/contact",
  });
  p.gallery(galleryImages);
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
reusables[0].block.props.logoUrl = logo.url;
reusables[1].block.props.logoUrl = logo.url;
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
      if (b.type === "split" && b.props.imageUrl) urls.add(b.props.imageUrl);
      if (b.type === "catalog" || b.type === "gallery")
        for (const l of b.props.items.split("\n")) {
          const u = l.split("|")[0];
          if (u && !u.startsWith("#")) urls.add(u);
        }
      if ((b.type === "hero" || b.type === "coverhero") && b.props.bgUrl)
        urls.add(b.props.bgUrl);
    }
  urls.add(logo.url); // the shared header carries the logo
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
    [
      "home",
      "about",
      "contact",
      "services",
      "pricing",
      "estimate-calculator",
      "visualizer",
    ],
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
  "- **Visualizer** (`/visualizer`): built as the AI flooring visualizer block. Turn it on and pick the image backend (Hugging Face token, your own API, or test mode) in Settings > RK Visualizer.",
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
