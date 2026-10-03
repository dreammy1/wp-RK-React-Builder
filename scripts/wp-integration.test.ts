/**
 * Full-stack contract test against REAL WordPress (Playground) running the real plugin:
 *   TS client (api/*) → Node proxy (server/) → WordPress plugin → back, plus server-side rendering.
 * Opt-in: needs network on first run.   RK_WP_INTEGRATION=1 pnpm vitest run scripts/wp-integration.test.ts
 */
import { readFileSync } from "node:fs";
import path from "node:path";
import type { AddressInfo } from "node:net";
import type { Server } from "node:http";
import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { createApp } from "../server/app";
import { loadEnv } from "../server/env";
import { api } from "../client/src/lib/api/builder";
import { setApiConfig } from "../client/src/lib/api/http";
import { ApiError } from "../client/src/lib/api/errors";
import type { SaveRequest } from "../client/src/lib/schema/api";
// @ts-expect-error plain ESM helper without types
import { startPlayground } from "./lib/playground.mjs";

const run = process.env.RK_WP_INTEGRATION === "1";
const layoutFull = JSON.parse(
  readFileSync(
    path.resolve(import.meta.dirname, "../contracts/valid/layout-full.json"),
    "utf8"
  )
).document;
const APP_PORT = 3199;
const SECRET = "integration-revalidate-secret";

describe.skipIf(!run)("client + proxy + real WordPress plugin", () => {
  let wp: {
    base: string;
    creds: Record<string, { user: string; pass: string } & number>;
    stop: () => void;
  };
  let server: Server;
  let app = "";
  let cookie = "";
  const realFetch = globalThis.fetch;
  const pageId = () => wp.creds.pageId as unknown as number;
  const mediaId = () => wp.creds.mediaId as unknown as number;

  beforeAll(async () => {
    wp = await startPlayground({
      port: 9412,
      revalidate: {
        url: `http://127.0.0.1:${APP_PORT}/api/revalidate`,
        secret: SECRET,
      },
    });
    const env = loadEnv({
      NODE_ENV: "test",
      WORDPRESS_PUBLIC_URL: wp.base,
      WORDPRESS_API_URL: `${wp.base}/wp-json/`,
      PUBLIC_SITE_URL: `http://127.0.0.1:${APP_PORT}`,
      WORDPRESS_AUTH_MODE: "proxy",
      WORDPRESS_APP_USER: wp.creds.editor.user,
      WORDPRESS_APP_PASSWORD: wp.creds.editor.pass,
      BUILDER_EDITOR_PASSWORD: "integration-editor-pass",
      REVALIDATE_SECRET: SECRET,
      PUBLIC_CACHE_TTL_SECONDS: "300",
    });
    const made = createApp(env, { staticDir: "/nonexistent" });
    await new Promise<void>(r => {
      server = made.app.listen(APP_PORT, r);
    });
    app = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;
    // The client uses relative URLs and browser cookies; emulate both in Node.
    vi.stubGlobal(
      "fetch",
      (input: RequestInfo | URL, init: RequestInit = {}) => {
        const url =
          typeof input === "string" && input.startsWith("/")
            ? app + input
            : input;
        return realFetch(url, {
          ...init,
          headers: {
            ...(init.headers as Record<string, string>),
            ...(cookie ? { cookie } : {}),
          },
        });
      }
    );
    const login = await realFetch(`${app}/api/auth/login`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ password: "integration-editor-pass" }),
    });
    cookie = login.headers.get("set-cookie")!.split(";")[0]!;
    const { csrf } = await login.json();
    setApiConfig({
      mode: "proxy",
      apiBase: "/api/wp/rk/v1/",
      csrf,
      publicSiteUrl: app,
    });
  }, 300_000);

  afterAll(async () => {
    vi.unstubAllGlobals();
    await new Promise(r => server?.close(r));
    wp?.stop();
  });

  it("lists pages and loads an unsaved page at revision 0 with correct capabilities", async () => {
    const list = await api.listPages({ search: "smoke" });
    expect(list.pages.map(p => p.slug)).toContain("smoke");
    const page = await api.loadPage(pageId());
    expect(page).toMatchObject({
      revision: 0,
      publishedRevision: null,
      capabilities: { manageTheme: false, publish: true },
    });
    expect(page.layout).toEqual({ version: 1, blocks: [] });
    expect(page.page.status).toBe("draft");
  });

  it("round-trips every block type through the real plugin unchanged", async () => {
    const res = await api.saveLayout(pageId(), {
      layout: layoutFull,
      expectedRevision: 0,
      status: "draft",
    });
    expect(res).toMatchObject({ ok: true, revision: 1 });
    const loaded = await api.loadPage(pageId());
    expect(loaded.layout).toEqual(layoutFull);
    expect(loaded.migrated).toBe(false);
    expect(loaded.revision).toBe(1);
  });

  it("maps real error responses: 409 conflict with current revision, 400 with issue paths", async () => {
    await expect(
      api.saveLayout(pageId(), {
        layout: layoutFull,
        expectedRevision: 0,
        status: "draft",
      })
    ).rejects.toMatchObject({
      kind: "conflict",
      extra: { currentRevision: 1 },
    });
    const bad = {
      layout: {
        version: 1,
        blocks: [
          {
            id: "x",
            type: "hero",
            props: {
              heading: "",
              sub: "",
              cta: "",
              ctaHref: "javascript:alert(1)",
            },
          },
        ],
      },
      expectedRevision: 1,
      status: "draft",
    } as unknown as SaveRequest;
    const err = await api.saveLayout(pageId(), bad).catch(e => e);
    expect(err).toBeInstanceOf(ApiError);
    expect(err).toMatchObject({ kind: "invalid_layout" });
    expect(err.extra.issues.length).toBeGreaterThan(0);
  });

  it("enforces the theme permission boundary for the proxied editor account", async () => {
    await expect(
      api.saveTheme({
        version: 1,
        primary: "#112233",
        bg: "#ffffff",
        ink: "#000000",
        font: "Georgia",
      })
    ).rejects.toMatchObject({ kind: "forbidden" });
  });

  it("serves media and live content in exactly the shape the client schemas expect", async () => {
    const media = await api.listMedia("seed");
    expect(media.items.find(m => m.id === mediaId())).toMatchObject({
      alt: "Seed alt text",
      width: 1,
      height: 1,
    });
    const all = await api.listContent({
      source: "service",
      limit: 6,
      category: "",
      orderBy: "menu_order",
      order: "asc",
    });
    expect(all.total).toBe(2); // the draft service is not public
    expect(all.items[0]!.image).toMatchObject({
      width: expect.any(Number),
      height: expect.any(Number),
    });
    expect(all.items.every(i => !i.excerpt.includes("<"))).toBe(true);
    const energy = await api.listContent({
      source: "service",
      limit: 6,
      category: "energy",
      orderBy: "title",
      order: "desc",
    });
    expect(energy.items.map(i => i.title)).toEqual(["Solar"]);
    const port = await api.listContent({
      source: "portfolio",
      limit: 3,
      category: "",
      orderBy: "date",
      order: "desc",
    });
    expect(port.items.map(i => i.title)).toEqual(["Beach House"]);
  });

  it("publishes, then the SSR frontend serves the snapshot with live content, metadata and image dimensions", async () => {
    expect((await realFetch(`${app}/smoke`)).status).toBe(404);
    const pub = await api.publish(pageId(), 1);
    expect(pub).toMatchObject({ status: "publish", publishedRevision: 2 });
    const res = await realFetch(`${app}/smoke`);
    const html = await res.text();
    expect(res.status).toBe(200);
    expect(html).toContain("<h1>Powering what’s next</h1>");
    expect(html).toContain("Wiring");
    expect(html).toMatch(/<img[^>]+width="1"[^>]+height="1"/);
    expect(html).toContain(
      '<link rel="canonical" href="http://127.0.0.1:3199/smoke">'
    );
    expect(html).toMatch(
      /<meta name="description" content="Licensed electrical/
    );
  });

  it("later draft saves do not change the public page; the preview link shows the draft", async () => {
    const small = {
      version: 1 as const,
      blocks: [
        {
          id: "only",
          type: "heading" as const,
          props: { text: "Draft only", level: 2 as const },
        },
      ],
    };
    await api.saveLayout(pageId(), {
      layout: small,
      expectedRevision: 2,
      status: "draft",
    });
    expect(await (await realFetch(`${app}/smoke`)).text()).not.toContain(
      "Draft only"
    );
    const t = await api.previewToken(pageId());
    const pv = await realFetch(
      `${app}/preview/smoke?token=${encodeURIComponent(t.token)}`
    );
    expect(pv.status).toBe(200);
    expect(pv.headers.get("x-robots-tag")).toMatch(/noindex/);
    expect(await pv.text()).toContain("Draft only");
    expect(
      (
        await realFetch(
          `${app}/preview/smoke?token=${encodeURIComponent(t.token.slice(0, -2))}xx`
        )
      ).status
    ).not.toBe(200);
  });

  it("lists, previews and restores revisions; restoring appends a new revision", async () => {
    const list = await api.listRevisions(pageId());
    expect(list.revisions.map(r => r.kind)).toEqual([
      "draft",
      "publish",
      "draft",
    ]);
    const first = await api.getRevision(pageId(), 1);
    expect(first.layout).toEqual(layoutFull);
    const restored = await api.restoreRevision(pageId(), 1, 3);
    expect(restored.revision).toBe(4);
    expect((await api.listRevisions(pageId())).revisions[0]!.kind).toBe(
      "restore"
    );
    expect((await api.loadPage(pageId())).layout).toEqual(layoutFull);
  });

  it("unpublish removes the public page immediately (proxy purge) ", async () => {
    await api.unpublish(pageId());
    expect((await realFetch(`${app}/smoke`)).status).toBe(404);
  });

  it("publishing directly in WordPress purges the frontend through the signed webhook", async () => {
    const auth =
      "Basic " +
      Buffer.from(`${wp.creds.editor.user}:${wp.creds.editor.pass}`).toString(
        "base64"
      );
    const cur = await (
      await realFetch(`${wp.base}/wp-json/rk/v1/builder/layout/${pageId()}`, {
        headers: { Authorization: auth },
      })
    ).json();
    expect((await realFetch(`${app}/smoke`)).status).toBe(404); // negative results aren't cached
    const r = await realFetch(
      `${wp.base}/wp-json/rk/v1/builder/publish/${pageId()}`,
      {
        method: "POST",
        headers: { Authorization: auth, "content-type": "application/json" },
        body: JSON.stringify({ expectedRevision: cur.revision }),
      }
    );
    expect(r.status).toBe(200);
    expect((await realFetch(`${app}/smoke`)).status).toBe(200); // warm the cache
    // unpublish directly: only the webhook can purge the cached copy now (TTL is 300s)
    const u = await realFetch(
      `${wp.base}/wp-json/rk/v1/builder/unpublish/${pageId()}`,
      {
        method: "POST",
        headers: { Authorization: auth, "content-type": "application/json" },
        body: "{}",
      }
    );
    expect(u.status).toBe(200);
    await vi.waitFor(
      async () => {
        expect((await realFetch(`${app}/smoke`)).status).toBe(404);
      },
      { timeout: 15_000, interval: 500 }
    );
  }, 30_000); // the purge wait above is up to 15s: the test needs more than vitest's default 5s
});
