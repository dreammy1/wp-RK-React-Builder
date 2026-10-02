/**
 * In-memory stand-in for the RK Builder WordPress plugin REST API.
 * Used for local development without WordPress and for fast, hermetic E2E runs.
 * It enforces the same contract with the shared Zod schemas; the real plugin is covered by
 * wp-plugin/tests (PHP) which validate the same contracts/ fixtures.
 */
import {
  createServer,
  type IncomingMessage,
  type ServerResponse,
} from "node:http";
import { randomBytes } from "node:crypto";
import { LayoutSchema } from "../../client/src/lib/schema/layout";
import {
  ThemeSchema,
  DEFAULT_THEME,
  type ThemeConfig,
} from "../../client/src/lib/schema/theme";
import { LIMITS } from "../../client/src/lib/schema/primitives";
import type { LayoutDocument } from "../../client/src/lib/schema/layout";

type Rev = {
  id: number;
  kind: "draft" | "publish" | "restore" | "unpublish";
  savedAt: string;
  author: string;
  layout: LayoutDocument;
};
type Page = {
  id: number;
  title: string;
  slug: string;
  status: "draft" | "publish" | "private";
  modified: string;
  draft: LayoutDocument;
  published: LayoutDocument | null;
  revision: number;
  publishedRevision: number | null;
  revisions: Rev[];
};

const USER = process.env.MOCK_WP_USER ?? "editor";
const PASS = process.env.MOCK_WP_PASSWORD ?? "mock-app-password";
const PORT = Number(process.env.MOCK_WP_PORT ?? 8099);
const BASE = process.env.MOCK_WP_PUBLIC_URL ?? `http://127.0.0.1:${PORT}`;
const REVALIDATE_URL = process.env.MOCK_WP_REVALIDATE_URL;
const REVALIDATE_SECRET = process.env.MOCK_WP_REVALIDATE_SECRET ?? "";
const MAX_REVISIONS = 20;

const empty = (): LayoutDocument => ({ version: 1, blocks: [] });
const hero = (heading: string): LayoutDocument => ({
  version: 1,
  blocks: [
    {
      id: "hero-seed01",
      type: "hero",
      props: {
        heading,
        sub: "Licensed electrical and energy contractors.",
        cta: "Get a quote",
        ctaHref: "/contact",
      },
    },
    {
      id: "services-seed1",
      type: "services",
      props: {
        title: "Our Services",
        source: "service",
        limit: 6,
        cols: 3,
        category: "",
        orderBy: "menu_order",
        order: "asc",
      },
    },
  ],
});

const pages = new Map<number, Page>();
type MockReusable = {
  id: number;
  name: string;
  block: { type: string; props: unknown };
};
const reusables = new Map<number, MockReusable>();
let nextReusableId = 700;
const reusablesFor = (l: LayoutDocument) => {
  const out: Record<string, MockReusable> = {};
  for (const b of l.blocks) {
    if (b.type !== "reusable") continue;
    const r = reusables.get(b.props.refId);
    if (r) out[String(r.id)] = r;
  }
  return out;
};
let theme: ThemeConfig = { ...DEFAULT_THEME };
const previewTokens = new Map<string, { pageId: number; expires: number }>();

export function reset() {
  pages.clear();
  reusables.clear();
  nextReusableId = 700;
  previewTokens.clear();
  theme = { ...DEFAULT_THEME };
  const seed = (
    id: number,
    title: string,
    slug: string,
    status: Page["status"],
    layout: LayoutDocument,
    published: boolean
  ) => {
    const now = new Date().toISOString();
    pages.set(id, {
      id,
      title,
      slug,
      status,
      modified: now,
      draft: layout,
      published: published ? layout : null,
      revision: layout.blocks.length ? 1 : 0,
      publishedRevision: published ? 1 : null,
      revisions: layout.blocks.length
        ? [
            {
              id: 1,
              kind: published ? "publish" : "draft",
              savedAt: now,
              author: "seed",
              layout,
            },
          ]
        : [],
    });
  };
  seed(42, "Home", "home", "publish", hero("Powering what’s next"), true);
  seed(43, "About", "about", "draft", empty(), false);
  seed(44, "Contact", "contact", "private", empty(), false);
}
reset();

const svg = (label: string) =>
  `data:image/svg+xml,${encodeURIComponent(`<svg xmlns='http://www.w3.org/2000/svg' width='800' height='500'><rect width='800' height='500' fill='#d3cdb9'/><text x='40' y='260' font-size='48'>${label}</text></svg>`)}`;
const img = (id: number, label: string) => ({
  url: `${BASE}/media/${id}.svg`,
  width: 800,
  height: 500,
  alt: label,
});
const CONTENT = {
  service: [
    {
      id: 1,
      title: "Residential Wiring",
      excerpt: "Safe, code-compliant installs for homes.",
      categories: ["residential"],
      image: img(1, "Wiring"),
    },
    {
      id: 2,
      title: "Solar & Battery",
      excerpt: "Design and install of PV + storage.",
      categories: ["energy"],
      image: img(2, "Solar"),
    },
    {
      id: 3,
      title: "EV Charging",
      excerpt: "Level-2 chargers, permits handled.",
      categories: ["energy"],
      image: null,
    },
    {
      id: 4,
      title: "Maintenance",
      excerpt: "Inspections and rapid callouts.",
      categories: ["commercial"],
      image: null,
    },
  ],
  portfolio: [
    {
      id: 11,
      title: "Maui Beach House",
      excerpt: "Full rewire + solar.",
      categories: ["residential"],
      image: img(11, "Beach House"),
    },
    {
      id: 12,
      title: "Downtown Retail",
      excerpt: "Panel upgrade, LED retrofit.",
      categories: ["commercial"],
      image: null,
    },
  ],
};

const send = (
  res: ServerResponse,
  status: number,
  body: unknown,
  headers: Record<string, string> = {}
) => {
  res.writeHead(status, { "Content-Type": "application/json", ...headers });
  res.end(JSON.stringify(body));
};
const err = (
  res: ServerResponse,
  status: number,
  code: string,
  message: string,
  data: object = {}
) => send(res, status, { code, message, data: { status, ...data } });
const iso = () => new Date().toISOString();
const authed = (req: IncomingMessage) =>
  req.headers.authorization ===
  `Basic ${Buffer.from(`${USER}:${PASS}`).toString("base64")}`;
const body = (req: IncomingMessage) =>
  new Promise<{ raw: string; json: unknown }>(resolve => {
    const chunks: Buffer[] = [];
    req.on("data", c => chunks.push(c));
    req.on("end", () => {
      const raw = Buffer.concat(chunks).toString();
      let json: unknown = null;
      try {
        json = raw ? JSON.parse(raw) : {};
      } catch {
        /* handled by caller */
      }
      resolve({ raw, json });
    });
  });

function revalidate(type: string, page?: Page) {
  if (!REVALIDATE_URL) return;
  void fetch(REVALIDATE_URL, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-RK-Revalidate-Secret": REVALIDATE_SECRET,
    },
    body: JSON.stringify({ type, pageId: page?.id, slug: page?.slug }),
  }).catch(() => undefined);
}
function record(p: Page, kind: Rev["kind"], layout: LayoutDocument) {
  p.revision += 1;
  p.modified = iso();
  p.revisions.unshift({
    id: p.revision,
    kind,
    savedAt: p.modified,
    author: USER,
    layout,
  });
  p.revisions = p.revisions.slice(0, MAX_REVISIONS);
}
const summary = (p: Page) => ({
  id: p.id,
  title: p.title,
  slug: p.slug,
  status: p.status,
  modified: p.modified,
  revision: p.revision,
  publishedRevision: p.publishedRevision,
});
const revSummary = (r: Rev) => ({
  id: r.id,
  kind: r.kind,
  savedAt: r.savedAt,
  author: r.author,
  blocks: r.layout.blocks.length,
});
const description = (l: LayoutDocument) => {
  const b = l.blocks.find(x => x.type === "hero" || x.type === "text");
  const t =
    b?.type === "hero" ? b.props.sub : b?.type === "text" ? b.props.text : "";
  return t.replace(/\s+/g, " ").slice(0, 160);
};

async function handle(req: IncomingMessage, res: ServerResponse) {
  const url = new URL(req.url ?? "/", BASE);
  const path = url.pathname.replace(/^\/wp-json\/rk\/v1\//, "");
  const method = req.method ?? "GET";
  const m = (re: RegExp) => path.match(re);

  if (url.pathname === "/__reset" && method === "POST") {
    reset();
    return send(res, 200, { ok: true });
  }
  if (url.pathname.startsWith("/media/")) {
    res.writeHead(200, { "Content-Type": "image/svg+xml" });
    return void res.end(decodeURIComponent(svg("Media").split(",")[1]!));
  }
  if (!url.pathname.startsWith("/wp-json/rk/v1/"))
    return err(res, 404, "rk_not_found", "Not found");

  // ---- public ----
  let x;
  if ((x = m(/^public\/page\/([^/]+)$/)) && method === "GET") {
    const p = [...pages.values()].find(
      q => q.slug === decodeURIComponent(x![1]!)
    );
    const tok = url.searchParams.get("preview");
    const t = tok ? previewTokens.get(tok) : undefined;
    if (p && t && t.pageId === p.id && t.expires > Date.now()) {
      return send(
        res,
        200,
        {
          page: {
            id: p.id,
            title: p.title,
            slug: p.slug,
            description: description(p.draft),
            modified: p.modified,
            image: null,
          },
          layout: p.draft,
          theme,
          revision: p.revision,
          preview: true,
          reusables: reusablesFor(p.draft),
        },
        { "Cache-Control": "no-store" }
      );
    }
    if (!p || p.status !== "publish" || !p.published)
      return err(res, 404, "rk_not_found", "Page not found.");
    return send(res, 200, {
      page: {
        id: p.id,
        title: p.title,
        slug: p.slug,
        description: description(p.published),
        modified: p.modified,
        image: null,
      },
      layout: p.published,
      theme,
      revision: p.publishedRevision,
      reusables: reusablesFor(p.published),
    });
  }
  if ((x = m(/^content\/(service|portfolio)$/)) && method === "GET") {
    const type = x[1] as "service" | "portfolio";
    const limit = Math.min(
      24,
      Math.max(1, Number(url.searchParams.get("limit") ?? 6))
    );
    const cat = url.searchParams.get("category");
    const orderby = url.searchParams.get("orderby") ?? "menu_order";
    const desc = url.searchParams.get("order") === "desc";
    let items = CONTENT[type].filter(i => !cat || i.categories.includes(cat));
    if (orderby === "title")
      items = [...items].sort((a, b) => a.title.localeCompare(b.title));
    if (desc) items = [...items].reverse();
    return send(res, 200, {
      total: items.length,
      items: items.slice(0, limit).map(i => ({
        ...i,
        link: `${BASE}/${type}/${i.id}`,
        image: i.image && {
          url: i.image.url,
          width: i.image.width,
          height: i.image.height,
          alt: i.image.alt,
        },
      })),
    });
  }
  if (path === "theme-config" && method === "GET") return send(res, 200, theme);

  // ---- authenticated ----
  if (!authed(req))
    return err(res, 401, "rk_unauthorized", "Authentication required.");

  if (path === "theme-config" && method === "POST") {
    const { json } = await body(req);
    const r = ThemeSchema.safeParse(json);
    if (!r.success)
      return err(res, 400, "rk_invalid_theme", "Invalid theme.", {
        issues: r.error.issues.map(i => ({
          path: i.path.join("."),
          message: i.message,
        })),
      });
    theme = r.data;
    revalidate("theme");
    return send(res, 200, { ok: true, theme });
  }
  if (path === "builder/pages" && method === "GET") {
    const q = (url.searchParams.get("search") ?? "").toLowerCase();
    const st = url.searchParams.get("status");
    const list = [...pages.values()]
      .filter(
        p =>
          (!q || p.title.toLowerCase().includes(q) || p.slug.includes(q)) &&
          (!st || p.status === st)
      )
      .map(summary);
    return send(res, 200, { pages: list, total: list.length });
  }
  if (path === "builder/reusables" && method === "GET")
    return send(res, 200, {
      items: [...reusables.values()].sort((a, b) =>
        a.name.localeCompare(b.name)
      ),
    });
  if (path === "builder/reusables" && method === "POST") {
    const { json } = await body(req);
    const j = json as Partial<MockReusable> | null;
    if (!j?.name || !j.block)
      return err(res, 400, "rk_invalid_reusable", "name and block required");
    const r = { id: nextReusableId++, name: j.name, block: j.block };
    reusables.set(r.id, r);
    return send(res, 201, { item: r });
  }
  if ((x = m(/^builder\/reusables\/(\d+)$/)) && method === "POST") {
    const r = reusables.get(Number(x[1]));
    if (!r) return err(res, 404, "rk_not_found", "Reusable block not found.");
    const { json } = await body(req);
    const j = json as Partial<MockReusable> | null;
    if (j?.name) r.name = j.name;
    if (j?.block) r.block = j.block;
    return send(res, 200, { item: r });
  }
  if (path === "builder/media" && method === "GET") {
    const q = (url.searchParams.get("search") ?? "").toLowerCase();
    const all = [
      { id: 501, title: "Crew on site", alt: "Crew installing panels" },
      { id: 502, title: "Switchboard", alt: "" },
    ].filter(i => !q || i.title.toLowerCase().includes(q));
    return send(res, 200, {
      items: all.map(i => ({
        ...i,
        url: `${BASE}/media/${i.id}.svg`,
        width: 800,
        height: 500,
      })),
    });
  }
  if ((x = m(/^builder\/layout\/(\d+)$/))) {
    const p = pages.get(Number(x[1]));
    if (!p) return err(res, 404, "rk_not_found", "Page not found.");
    if (method === "GET")
      return send(res, 200, {
        page: {
          id: p.id,
          title: p.title,
          slug: p.slug,
          status: p.status,
          link: `${BASE}/${p.slug}`,
        },
        layout: p.draft,
        theme,
        revision: p.revision,
        publishedRevision: p.publishedRevision,
        updatedAt: p.modified,
        capabilities: { manageTheme: true, publish: true },
      });
    const { raw, json } = await body(req);
    if (raw.length > LIMITS.maxPayloadBytes)
      return err(res, 413, "rk_payload_too_large", "Payload too large.");
    const b = json as {
      layout?: unknown;
      theme?: unknown;
      expectedRevision?: unknown;
      status?: unknown;
    } | null;
    if (!b || b.status !== "draft")
      return err(res, 400, "rk_invalid_layout", "status must be 'draft'.");
    const l = LayoutSchema.safeParse(b.layout);
    if (!l.success)
      return err(res, 400, "rk_invalid_layout", "Invalid layout.", {
        issues: l.error.issues.map(i => ({
          path: i.path.join("."),
          message: i.message,
        })),
      });
    if (b.theme !== undefined) {
      const t = ThemeSchema.safeParse(b.theme);
      if (!t.success)
        return err(res, 400, "rk_invalid_theme", "Invalid theme.", {
          issues: t.error.issues.map(i => ({
            path: i.path.join("."),
            message: i.message,
          })),
        });
      theme = t.data;
    }
    if (b.expectedRevision !== p.revision)
      return err(
        res,
        409,
        "rk_revision_conflict",
        "The page was changed by another editor.",
        { currentRevision: p.revision }
      );
    p.draft = l.data;
    record(p, "draft", l.data);
    return send(res, 200, {
      ok: true,
      pageId: p.id,
      revision: p.revision,
      status: p.status,
      updatedAt: p.modified,
    });
  }
  if ((x = m(/^builder\/revisions\/(\d+)$/)) && method === "GET") {
    const p = pages.get(Number(x[1]));
    return p
      ? send(res, 200, {
          revisions: p.revisions.map(revSummary),
          retained: MAX_REVISIONS,
        })
      : err(res, 404, "rk_not_found", "Page not found.");
  }
  if ((x = m(/^builder\/revisions\/(\d+)\/(\d+)$/)) && method === "GET") {
    const r = pages
      .get(Number(x[1]))
      ?.revisions.find(q => q.id === Number(x![2]));
    return r
      ? send(res, 200, { revision: revSummary(r), layout: r.layout })
      : err(res, 404, "rk_not_found", "Revision not found.");
  }
  if (
    (x = m(/^builder\/revisions\/(\d+)\/(\d+)\/restore$/)) &&
    method === "POST"
  ) {
    const p = pages.get(Number(x[1]));
    const r = p?.revisions.find(q => q.id === Number(x![2]));
    if (!p || !r) return err(res, 404, "rk_not_found", "Revision not found.");
    const { json } = await body(req);
    if ((json as { expectedRevision?: number }).expectedRevision !== p.revision)
      return err(
        res,
        409,
        "rk_revision_conflict",
        "The page was changed by another editor.",
        { currentRevision: p.revision }
      );
    p.draft = structuredClone(r.layout);
    record(p, "restore", p.draft);
    return send(res, 200, {
      ok: true,
      pageId: p.id,
      revision: p.revision,
      status: p.status,
      updatedAt: p.modified,
    });
  }
  if ((x = m(/^builder\/publish\/(\d+)$/)) && method === "POST") {
    const p = pages.get(Number(x[1]));
    if (!p) return err(res, 404, "rk_not_found", "Page not found.");
    const { json } = await body(req);
    if ((json as { expectedRevision?: number }).expectedRevision !== p.revision)
      return err(
        res,
        409,
        "rk_revision_conflict",
        "The page was changed by another editor.",
        { currentRevision: p.revision }
      );
    p.published = structuredClone(p.draft);
    p.status = "publish";
    record(p, "publish", p.published);
    p.publishedRevision = p.revision;
    revalidate("publish", p);
    return send(res, 200, {
      ok: true,
      pageId: p.id,
      revision: p.revision,
      status: p.status,
      updatedAt: p.modified,
      publishedRevision: p.publishedRevision,
      publishedAt: p.modified,
      link: `${BASE}/${p.slug}`,
    });
  }
  if ((x = m(/^builder\/unpublish\/(\d+)$/)) && method === "POST") {
    const p = pages.get(Number(x[1]));
    if (!p) return err(res, 404, "rk_not_found", "Page not found.");
    p.status = "draft";
    p.published = null;
    p.publishedRevision = null;
    record(p, "unpublish", p.draft);
    revalidate("unpublish", p);
    return send(res, 200, {
      ok: true,
      pageId: p.id,
      revision: p.revision,
      status: p.status,
      updatedAt: p.modified,
    });
  }
  if ((x = m(/^builder\/preview-token\/(\d+)$/)) && method === "POST") {
    const p = pages.get(Number(x[1]));
    if (!p) return err(res, 404, "rk_not_found", "Page not found.");
    const token = `mock.${randomBytes(18).toString("hex")}`;
    const expires = Date.now() + 15 * 60_000;
    previewTokens.set(token, { pageId: p.id, expires });
    return send(res, 200, {
      token,
      expiresAt: new Date(expires).toISOString(),
    });
  }
  return err(res, 404, "rk_not_found", "Not found");
}

createServer((req, res) => {
  handle(req, res).catch(e => {
    console.error(e);
    err(res, 500, "rk_server_error", "Mock error");
  });
}).listen(PORT, () => console.log(`mock WordPress on ${BASE} (user ${USER})`));
