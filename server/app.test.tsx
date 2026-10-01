import type { AddressInfo } from "node:net";
import type { Server } from "node:http";
import { readFileSync } from "node:fs";
import path from "node:path";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createApp } from "./app";
import { loadEnv, type Env } from "./env";
import { redact, setLogSink } from "./logger";
import { PageCache } from "./cache";

const full = JSON.parse(
  readFileSync(
    path.resolve(import.meta.dirname, "../contracts/valid/layout-full.json"),
    "utf8"
  )
).document;
const theme = {
  version: 1,
  primary: "#C7F36B",
  bg: "#F8F5ED",
  ink: "#1B2430",
  font: "Space Grotesk",
  social: { instagram: "https://instagram.com/rk" },
};
const baseEnv = {
  NODE_ENV: "test",
  WORDPRESS_PUBLIC_URL: "https://cms.example.com",
  WORDPRESS_API_URL: "https://cms.example.com/wp-json/",
  PUBLIC_SITE_URL: "https://site.example",
  WORDPRESS_AUTH_MODE: "proxy",
  WORDPRESS_APP_USER: "editor",
  WORDPRESS_APP_PASSWORD: "app-pass-123",
  BUILDER_EDITOR_PASSWORD: "correct horse battery",
  REVALIDATE_SECRET: "reval-secret-1",
  METRICS_TOKEN: "metrics-token-1",
  PUBLIC_CACHE_TTL_SECONDS: "60",
};
const mkEnv = (over: Record<string, string> = {}) =>
  loadEnv({ ...baseEnv, ...over });
const json = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
const item = (id: number) => ({
  id,
  title: `Item <${id}>`,
  excerpt: "e",
  link: "https://cms.example.com/x",
  categories: [],
  image: {
    url: "https://cms.example.com/i.jpg",
    width: 800,
    height: 500,
    alt: `Alt ${id}`,
  },
});

type Wp = {
  published: Record<string, unknown>;
  calls: string[];
  fail: boolean;
};
function wpFetch(wp: Wp) {
  return vi.fn(async (input: URL | RequestInfo, init?: RequestInit) => {
    const u = new URL(String(input));
    wp.calls.push(`${init?.method ?? "GET"} ${u.pathname}${u.search}`);
    if (wp.fail) throw new Error("ECONNREFUSED");
    const m = u.pathname.match(/rk\/v1\/public\/page\/([^/]+)$/);
    if (m) {
      const slug = decodeURIComponent(m[1]!);
      if (u.searchParams.get("preview") === "good-token-good-token-1")
        return json(200, {
          page: {
            id: 9,
            title: "Draft",
            slug,
            description: "d",
            modified: "t",
            image: null,
          },
          layout: full,
          theme,
          revision: 5,
          preview: true,
        });
      const layout = wp.published[slug];
      return layout
        ? json(200, {
            page: {
              id: 1,
              title: `Title "${slug}" <b>`,
              slug,
              description: 'A "quoted" <desc>',
              modified: "t",
              image: "https://cms.example.com/og.jpg",
            },
            layout,
            theme,
            revision: 2,
          })
        : json(404, {
            code: "rk_not_found",
            message: "nope",
            data: { status: 404 },
          });
    }
    if (u.pathname.includes("rk/v1/content/"))
      return json(200, { items: [item(1), item(2)], total: 2 });
    if (u.pathname.includes("rk/v1/builder/"))
      return json(200, {
        echoed: u.pathname,
        auth: (init?.headers as Record<string, string>)?.Authorization ?? "",
        body: init?.body ?? null,
      });
    if (u.pathname.endsWith("theme-config")) return json(200, { ok: true });
    return json(404, {});
  });
}

let server: Server | undefined;
let wp: Wp;
let fetchSpy: ReturnType<typeof wpFetch>;
let logs: string[];
async function boot(env: Env) {
  wp = {
    published: {
      home: full,
      about: {
        version: 1,
        blocks: [
          {
            id: "h",
            type: "heading",
            props: { text: "About <script>alert(1)</script>", level: 2 },
          },
        ],
      },
    },
    calls: [],
    fail: false,
  };
  fetchSpy = wpFetch(wp);
  const made = createApp(env, {
    fetch: fetchSpy as unknown as typeof fetch,
    staticDir: "/nonexistent",
  });
  await new Promise<void>(r => {
    server = made.app.listen(0, r);
  });
  const base = `http://127.0.0.1:${(server!.address() as AddressInfo).port}`;
  return {
    ...made,
    base,
    get: (p: string, init?: RequestInit) =>
      fetch(base + p, { redirect: "manual", ...init }),
  };
}
beforeEach(() => {
  logs = [];
  setLogSink(l => logs.push(l));
});
afterEach(async () => {
  if (server) await new Promise(r => server!.close(r));
  server = undefined;
});

describe("environment validation", () => {
  it("lists every problem and never echoes secret values", () => {
    expect(() =>
      loadEnv({ NODE_ENV: "production", WORDPRESS_PUBLIC_URL: "nope" })
    ).toThrow(
      /WORDPRESS_PUBLIC_URL[\s\S]*WORDPRESS_API_URL[\s\S]*PUBLIC_SITE_URL/
    );
    try {
      mkEnv({
        NODE_ENV: "production",
        BUILDER_EDITOR_PASSWORD: "short-pw",
        PUBLIC_SITE_URL: "http://insecure.example",
      });
    } catch (e) {
      expect(String(e)).not.toContain("short-pw");
      expect(String(e)).toMatch(/at least 12/);
      expect(String(e)).toMatch(/https/);
    }
  });
  it("requires proxy credentials only in proxy mode and analytics vars together", () => {
    expect(() => mkEnv({ BUILDER_EDITOR_PASSWORD: "" })).toThrow(
      /BUILDER_EDITOR_PASSWORD/
    );
    expect(() =>
      loadEnv({
        ...baseEnv,
        WORDPRESS_AUTH_MODE: "nonce",
        BUILDER_EDITOR_PASSWORD: "",
        WORDPRESS_APP_USER: "",
        WORDPRESS_APP_PASSWORD: "",
      })
    ).not.toThrow();
    expect(() => mkEnv({ ANALYTICS_ENDPOINT: "https://a.example" })).toThrow(
      /together/
    );
  });
});

describe("public server-rendered site", () => {
  it("serves meaningful HTML with metadata, escaping, theme vars, nonce'd CSP, and no editor code", async () => {
    const { get } = await boot(mkEnv());
    const res = await get("/about");
    const html = await res.text();
    expect(res.status).toBe(200);
    expect(html).toContain(
      "<h2>About &lt;script&gt;alert(1)&lt;/script&gt;</h2>"
    );
    expect(html).not.toContain("<script>alert(1)");
    expect(html).toContain(
      "<title>Title &quot;about&quot; &lt;b&gt; — RK</title>"
    );
    expect(html).toContain(
      '<link rel="canonical" href="https://site.example/about">'
    );
    expect(html).toContain('content="A &quot;quoted&quot; &lt;desc&gt;"');
    for (const m of [
      'property="og:title"',
      'property="og:image" content="https://cms.example.com/og.jpg"',
      'name="twitter:card" content="summary_large_image"',
    ])
      expect(html).toContain(m);
    expect(html).toMatch(
      /<style nonce="[^"]+">\.site-root\{--site-primary:#C7F36B/
    );
    const nonce = html.match(/<style nonce="([^"]+)">/)![1];
    expect(res.headers.get("content-security-policy")).toContain(
      `'nonce-${nonce}'`
    );
    expect(res.headers.get("x-content-type-options")).toBe("nosniff");
    expect(res.headers.get("cache-control")).toMatch(/s-maxage=60/);
    expect(html).not.toMatch(/block-handle|canvas-block|Save draft|inspector/);
  });
  it("renders live content with image dimensions and alt text, and uses '/' as the home canonical", async () => {
    const { get } = await boot(mkEnv());
    const html = await (await get("/")).text();
    expect(html).toContain(
      '<link rel="canonical" href="https://site.example/">'
    );
    expect(html).toContain("Item &lt;1&gt;");
    expect(html).toMatch(
      /<img src="https:\/\/cms\.example\.com\/i\.jpg" alt="Alt 1" width="800" height="500"/
    );
    expect(html).toMatch(
      /<img[^>]+alt="A crew installing solar panels"[^>]+width="1200"[^>]+height="800"/
    );
    expect(wp.calls.filter(c => c.includes("content/service")).length).toBe(1);
  });
  it("redirects /home to /, 404s for missing, reserved and malformed slugs", async () => {
    const { get } = await boot(mkEnv());
    expect((await get("/home")).status).toBe(301);
    for (const p of [
      "/missing",
      "/builder",
      "/api",
      "/..%2fetc",
      "/UPPER",
      "/a/b",
    ]) {
      const r = await get(p);
      expect(r.status, p).toBe(404);
      expect(await r.text()).toContain("Page not found");
    }
  });
  it("never exposes draft/private pages (WordPress answers 404) and does not cache 404s", async () => {
    const { get } = await boot(mkEnv());
    const a = await get("/secret-draft");
    expect(a.status).toBe(404);
    await get("/secret-draft");
    expect(wp.calls.filter(c => c.includes("secret-draft")).length).toBe(2);
  });
  it("caches within the TTL and revalidates through the secret-protected endpoint", async () => {
    const { get, base } = await boot(mkEnv());
    await get("/about");
    await get("/about");
    expect(wp.calls.filter(c => c.includes("public/page/about")).length).toBe(
      1
    );
    const bad = await fetch(`${base}/api/revalidate`, {
      method: "POST",
      headers: {
        "content-type": "application/json",
        "x-rk-revalidate-secret": "wrong",
      },
      body: JSON.stringify({ slug: "about" }),
    });
    expect(bad.status).toBe(401);
    const none = await fetch(`${base}/api/revalidate`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: "{}",
    });
    expect(none.status).toBe(401);
    const ok = await fetch(`${base}/api/revalidate`, {
      method: "POST",
      headers: {
        "content-type": "application/json",
        "x-rk-revalidate-secret": "reval-secret-1",
      },
      body: JSON.stringify({ type: "publish", slug: "about" }),
    });
    expect(ok.status).toBe(200);
    await get("/about");
    expect(wp.calls.filter(c => c.includes("public/page/about")).length).toBe(
      2
    );
  });
  it("serves stale content when WordPress fails, and a 503 page when nothing is cached", async () => {
    let t = 0;
    wp = undefined as never;
    const env = mkEnv();
    const w: Wp = {
      published: { home: full, about: full },
      calls: [],
      fail: false,
    };
    const spy = wpFetch(w);
    const { app } = createApp(env, {
      fetch: spy as unknown as typeof fetch,
      now: () => t,
      staticDir: "/nonexistent",
    });
    await new Promise<void>(r => {
      server = app.listen(0, r);
    });
    const base = `http://127.0.0.1:${(server!.address() as AddressInfo).port}`;
    expect((await fetch(`${base}/about`)).status).toBe(200);
    w.fail = true;
    t = 120_000;
    const stale = await fetch(`${base}/about`);
    expect(stale.status).toBe(200);
    expect(stale.headers.get("x-rk-stale")).toBe("1");
    const down = await fetch(`${base}/never-cached`);
    expect(down.status).toBe(503);
    expect(down.headers.get("retry-after")).toBe("30");
    expect(await down.text()).toContain("right back");
  });
  it("treats invalid stored layouts as unavailable rather than rendering them", async () => {
    const { get } = await boot(mkEnv());
    wp.published.broken = {
      version: 1,
      blocks: [
        {
          id: "x",
          type: "hero",
          props: { heading: "", ctaHref: "javascript:alert(1)" },
        },
      ],
    };
    expect((await get("/broken")).status).toBe(503);
  });
  it("draft previews need a token, are noindex and uncached", async () => {
    const { get } = await boot(mkEnv());
    expect((await get("/preview/draft")).status).toBe(404);
    expect((await get("/preview/draft?token=short")).status).toBe(404);
    const r = await get("/preview/draft?token=good-token-good-token-1");
    const html = await r.text();
    expect(r.status).toBe(200);
    expect(r.headers.get("cache-control")).toBe("no-store");
    expect(r.headers.get("x-robots-tag")).toMatch(/noindex/);
    expect(html).toContain('name="robots" content="noindex, nofollow"');
    expect(html).not.toContain('rel="canonical"');
    expect(html).toContain("Draft preview");
    // an invalid/expired token (WordPress answers with the published page) must not render at /preview
    expect((await get("/preview/about?token=" + "x".repeat(30))).status).toBe(
      404
    );
  });
});

describe("editor proxy: auth, CSRF, allow-list", () => {
  async function login(base: string) {
    const r = await fetch(`${base}/api/auth/login`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ password: "correct horse battery" }),
    });
    const cookie = r.headers.get("set-cookie")!;
    const { csrf } = await r.json();
    return {
      cookie: cookie.split(";")[0]!,
      csrf: csrf as string,
      setCookie: cookie,
    };
  }
  it("rejects anonymous access to every proxied route", async () => {
    const { base } = await boot(mkEnv());
    for (const [m, p] of [
      ["GET", "/api/wp/rk/v1/builder/pages"],
      ["POST", "/api/wp/rk/v1/builder/layout/1"],
      ["POST", "/api/wp/rk/v1/theme-config"],
      ["POST", "/api/wp/rk/v1/builder/publish/1"],
    ]) {
      const r = await fetch(base + p, {
        method: m,
        headers: { "content-type": "application/json" },
        body: m === "POST" ? "{}" : undefined,
      });
      expect(r.status, `${m} ${p}`).toBe(401);
      expect((await r.json()).code).toBe("rk_unauthorized");
    }
    expect(wp.calls.filter(c => c.includes("builder/"))).toHaveLength(0);
  });
  it("login: wrong password rejected, httpOnly SameSite=Strict cookie on success, rate limited", async () => {
    const { base } = await boot(mkEnv());
    const bad = await fetch(`${base}/api/auth/login`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ password: "nope" }),
    });
    expect(bad.status).toBe(401);
    const { setCookie } = await login(base);
    expect(setCookie).toMatch(/HttpOnly/);
    expect(setCookie).toMatch(/SameSite=Strict/);
    expect(setCookie).toMatch(/Secure/);
    for (let i = 0; i < 6; i++)
      await fetch(`${base}/api/auth/login`, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ password: "nope" }),
      });
    const limited = await fetch(`${base}/api/auth/login`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ password: "correct horse battery" }),
    });
    expect(limited.status).toBe(429);
  });
  it("requires the CSRF token and a same-site Origin on writes, and holds the WP credential server-side", async () => {
    const { base } = await boot(mkEnv());
    const { cookie, csrf } = await login(base);
    const post = (headers: Record<string, string>) =>
      fetch(`${base}/api/wp/rk/v1/builder/layout/7`, {
        method: "POST",
        headers: { "content-type": "application/json", cookie, ...headers },
        body: JSON.stringify({
          layout: { version: 1, blocks: [] },
          expectedRevision: 1,
          status: "draft",
        }),
      });
    expect((await post({})).status).toBe(403);
    expect((await post({ "x-rk-csrf": "wrong" })).status).toBe(403);
    expect(
      (await post({ "x-rk-csrf": csrf, origin: "https://evil.example" })).status
    ).toBe(403);
    const ok = await post({
      "x-rk-csrf": csrf,
      origin: "https://site.example",
    });
    expect(ok.status).toBe(200);
    const body = await ok.json();
    expect(body.auth).toBe(
      "Basic " + Buffer.from("editor:app-pass-123").toString("base64")
    );
    // …and the credential never reaches the browser
    expect(JSON.stringify([...ok.headers])).not.toContain("app-pass-123");
    expect(JSON.parse(body.body)).toMatchObject({ expectedRevision: 1 });
  });
  it("only forwards allow-listed routes", async () => {
    const { base } = await boot(mkEnv());
    const { cookie } = await login(base);
    for (const p of [
      "/api/wp/wp/v2/users",
      "/api/wp/rk/v1/builder/layout/abc",
      "/api/wp/rk/v1/content/evil",
      "/api/wp/rk/v1/../../wp/v2/users",
      "/api/wp/rk/v1/builder/pages/extra",
    ]) {
      const r = await fetch(base + p, { headers: { cookie } });
      expect(r.status, p).toBe(404);
    }
    expect(wp.calls).toHaveLength(0);
  });
  it("rejects oversized and malformed bodies with stable codes", async () => {
    const { base } = await boot(mkEnv());
    const { cookie, csrf } = await login(base);
    const h = { "content-type": "application/json", cookie, "x-rk-csrf": csrf };
    const big = await fetch(`${base}/api/wp/rk/v1/builder/layout/1`, {
      method: "POST",
      headers: h,
      body: JSON.stringify({ pad: "x".repeat(310 * 1024) }),
    });
    expect(big.status).toBe(413);
    expect((await big.json()).code).toBe("rk_payload_too_large");
    const bad = await fetch(`${base}/api/wp/rk/v1/builder/layout/1`, {
      method: "POST",
      headers: h,
      body: "{oops",
    });
    expect(bad.status).toBe(400);
  });
  it("publishing purges the public cache so the next view revalidates", async () => {
    const { base, get } = await boot(mkEnv());
    await get("/about");
    const { cookie, csrf } = await login(base);
    await fetch(`${base}/api/wp/rk/v1/builder/publish/1`, {
      method: "POST",
      headers: {
        "content-type": "application/json",
        cookie,
        "x-rk-csrf": csrf,
      },
      body: JSON.stringify({ expectedRevision: 1 }),
    });
    await get("/about");
    expect(wp.calls.filter(c => c.includes("public/page/about")).length).toBe(
      2
    );
  });
  it("surfaces WordPress outages as 504 rk_server_error and counts them", async () => {
    const { base, metrics } = await boot(mkEnv());
    const { cookie } = await login(base);
    wp.fail = true;
    const r = await fetch(`${base}/api/wp/rk/v1/builder/pages`, {
      headers: { cookie },
    });
    expect(r.status).toBe(504);
    expect((await r.json()).code).toBe("rk_server_error");
    expect(metrics.render()).toMatch(
      /rk_wp_requests_total\{[^}]*code="unreachable"/
    );
  });
  it("logs out (session invalid afterwards) and never logs secrets", async () => {
    const { base } = await boot(mkEnv());
    const { cookie, csrf } = await login(base);
    await fetch(`${base}/api/wp/rk/v1/builder/pages`, { headers: { cookie } });
    await fetch(`${base}/api/auth/logout`, {
      method: "POST",
      headers: { cookie, "x-rk-csrf": csrf },
    });
    expect(
      (
        await fetch(`${base}/api/wp/rk/v1/builder/pages`, {
          headers: { cookie },
        })
      ).status
    ).toBe(401);
    const all = logs.join("\n");
    for (const secret of [
      "app-pass-123",
      "correct horse battery",
      csrf,
      cookie.split("=")[1]!,
    ])
      expect(all).not.toContain(secret);
  });
  it("nonce mode mounts no proxy and no login", async () => {
    const { base } = await boot(
      loadEnv({
        ...baseEnv,
        WORDPRESS_AUTH_MODE: "nonce",
        WORDPRESS_APP_USER: "",
        WORDPRESS_APP_PASSWORD: "",
        BUILDER_EDITOR_PASSWORD: "",
      })
    );
    expect((await fetch(`${base}/api/auth/session`)).status).toBe(404);
    expect((await fetch(`${base}/api/wp/rk/v1/builder/pages`)).status).toBe(
      404
    );
    expect((await (await fetch(`${base}/api/config`)).json()).authMode).toBe(
      "nonce"
    );
  });
});

describe("operations endpoints", () => {
  it("metrics require the bearer token; telemetry is validated and counted", async () => {
    const { base, get } = await boot(mkEnv());
    expect((await get("/metrics")).status).toBe(404);
    await fetch(`${base}/api/telemetry`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ type: "vitals", lcp: 1200, cls: 0.02, path: "/" }),
    });
    await fetch(`${base}/api/telemetry`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ type: "evil", lcp: -1 }),
    });
    const m = await (
      await get("/metrics", {
        headers: { authorization: "Bearer metrics-token-1" },
      })
    ).text();
    expect(m).toContain('rk_client_events_total{type="vitals"} 1');
    expect(m).toContain("rk_web_vitals_lcp_ms_sum 1200");
    expect(m).not.toContain("evil");
  });
  it("healthz, robots and the telemetry script are served", async () => {
    const { get } = await boot(mkEnv());
    expect(await (await get("/healthz")).json()).toEqual({ ok: true });
    expect(await (await get("/robots.txt")).text()).toContain(
      "Disallow: /builder"
    );
    expect(await (await get("/rum.js")).text()).toContain(
      "largest-contentful-paint"
    );
  });
  it("analytics script is injected only when configured", async () => {
    const a = await boot(
      mkEnv({
        ANALYTICS_ENDPOINT: "https://analytics.example",
        ANALYTICS_WEBSITE_ID: "site-1",
      })
    );
    const html = await (await a.get("/about")).text();
    expect(html).toContain(
      'src="https://analytics.example/umami" data-website-id="site-1"'
    );
    expect(html).not.toContain("%VITE_");
  });
});

describe("page cache", () => {
  it("expires fresh → stale → gone and purges by slug", () => {
    let t = 0;
    const c = new PageCache<string>(1000, 5000, () => t);
    c.set("a", "A", "a");
    c.set("b", "B", "b");
    expect(c.get("a")).toEqual({ value: "A", fresh: true });
    t = 2000;
    expect(c.get("a")).toEqual({ value: "A", fresh: false });
    t = 7000;
    expect(c.get("a")).toBeNull();
    t = 0;
    c.set("a", "A", "a");
    c.purgeSlug("a");
    expect(c.get("a")).toBeNull();
    expect(c.get("b")?.value).toBe("B");
  });
  it("redacts sensitive keys in logs", () => {
    expect(
      redact({
        password: "x",
        nested: { Authorization: "Bearer y", token: "z", ok: 1 },
        list: [{ nonce: "n" }],
      })
    ).toEqual({
      password: "[redacted]",
      nested: { Authorization: "[redacted]", token: "[redacted]", ok: 1 },
      list: [{ nonce: "[redacted]" }],
    });
  });
});
