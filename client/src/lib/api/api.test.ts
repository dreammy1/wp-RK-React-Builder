import { afterEach, describe, expect, it, vi } from "vitest";
import { ApiError, describeError, kindFromResponse } from "./errors";
import { setApiConfig } from "./http";
import { api } from "./builder";
import { ContentStore } from "./contentStore";
import type { ContentQuery } from "@/render/content";

const json = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
afterEach(() => vi.unstubAllGlobals());
const configure = (mode: "proxy" | "nonce" = "proxy") =>
  setApiConfig({
    mode,
    apiBase: "/api/wp/rk/v1/",
    nonce: "n0nce",
    csrf: "c5rf",
    publicSiteUrl: "https://site.example",
  });

describe("error mapping", () => {
  it.each([
    [401, "rk_unauthorized", "unauthorized"],
    [403, "rk_forbidden", "forbidden"],
    [404, "rk_not_found", "not_found"],
    [400, "rk_invalid_layout", "invalid_layout"],
    [400, "rk_invalid_theme", "invalid_theme"],
    [409, "rk_revision_conflict", "conflict"],
    [413, "rk_payload_too_large", "too_large"],
    [500, "rk_server_error", "server"],
    [403, "rest_cookie_invalid_nonce", "unauthorized"],
    [502, undefined, "server"],
    [401, undefined, "unauthorized"],
  ])("%s %s → %s", (status, code, kind) =>
    expect(kindFromResponse(status as number, code as string | undefined)).toBe(
      kind
    )
  );
  it("describes errors without leaking server detail", () => {
    expect(describeError(new ApiError("conflict", "x"))).toMatch(
      /changed by someone else/
    );
    expect(describeError(new Error("boom"))).toBe(
      "Something unexpected went wrong."
    );
  });
});

describe("http client", () => {
  it("sends the CSRF header on writes (proxy) and the nonce (nonce mode), never on GET csrf", async () => {
    const f = vi.fn().mockImplementation(async () =>
      json(200, {
        ok: true,
        pageId: 1,
        revision: 2,
        status: "draft",
        updatedAt: "t",
      })
    );
    vi.stubGlobal("fetch", f);
    configure("proxy");
    await api.saveLayout(1, {
      layout: { version: 1, blocks: [] },
      expectedRevision: 1,
      status: "draft",
    });
    expect(f.mock.calls[0]![1].headers).toMatchObject({ "X-RK-CSRF": "c5rf" });
    expect(f.mock.calls[0]![1].headers["X-WP-Nonce"]).toBeUndefined();
    configure("nonce");
    await api.saveLayout(1, {
      layout: { version: 1, blocks: [] },
      expectedRevision: 1,
      status: "draft",
    });
    expect(f.mock.calls[1]![1].headers).toMatchObject({
      "X-WP-Nonce": "n0nce",
    });
    expect(JSON.parse(f.mock.calls[1]![1].body)).toEqual({
      layout: { version: 1, blocks: [] },
      expectedRevision: 1,
      status: "draft",
    });
  });
  it("maps a 409 to a conflict carrying the current revision", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        json(409, {
          code: "rk_revision_conflict",
          message: "stale",
          data: { status: 409, currentRevision: 4 },
        })
      )
    );
    configure();
    await expect(api.publish(1, 1)).rejects.toMatchObject({
      kind: "conflict",
      extra: { currentRevision: 4 },
    });
  });
  it("maps fetch failures to network errors", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new TypeError("offline")));
    configure();
    await expect(api.listPages()).rejects.toMatchObject({ kind: "network" });
  });
  it("rejects responses that break the contract", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(json(200, { pages: "nope" }))
    );
    configure();
    await expect(api.listPages()).rejects.toMatchObject({
      kind: "invalid_response",
    });
  });
  it("loadPage validates and migrates stored layout; invalid stored data is reported, not hidden", async () => {
    const base = {
      page: { id: 1, title: "Home", slug: "home", status: "draft" },
      theme: {
        version: 1,
        primary: "#000000",
        bg: "#ffffff",
        ink: "#111111",
        font: "Georgia",
      },
      revision: 3,
      updatedAt: "t",
    };
    configure();
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        json(200, {
          ...base,
          layout: {
            version: 1,
            blocks: [{ id: "a", type: "spacer", h: 12 }],
          },
        })
      )
    );
    const p = await api.loadPage(1);
    expect(p.migrated).toBe(true);
    expect(p.layout.blocks[0]).toEqual({
      id: "a",
      type: "spacer",
      props: { h: 12 },
    });
    expect(p.capabilities).toEqual({ manageTheme: true, publish: true });
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        json(200, {
          ...base,
          layout: {
            version: 1,
            blocks: [{ id: "a", type: "nope", props: {} }],
          },
        })
      )
    );
    await expect(api.loadPage(1)).rejects.toMatchObject({
      kind: "invalid_response",
    });
  });
});

describe("content store", () => {
  const q: ContentQuery = {
    source: "service",
    limit: 6,
    category: "",
    orderBy: "date",
    order: "asc",
  };
  it("de-duplicates concurrent requests, caches, and refetches after the TTL", async () => {
    let t = 0;
    const fetcher = vi.fn().mockResolvedValue({ items: [], total: 0 });
    const store = new ContentStore(fetcher, false, () => t);
    expect(store.get(q).status).toBe("loading");
    store.get(q);
    store.get({ ...q, cols: 4 } as ContentQuery);
    await new Promise(r => setTimeout(r, 10));
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(store.get(q).status).toBe("ready");
    t = 61_000;
    store.get(q);
    await new Promise(r => setTimeout(r, 10));
    expect(fetcher).toHaveBeenCalledTimes(2);
  });
  it("surfaces fetch failures as an error state", async () => {
    const store = new ContentStore(
      vi.fn().mockRejectedValue(new ApiError("network", "x"))
    );
    store.get(q);
    await new Promise(r => setTimeout(r, 10));
    expect(store.get(q)).toMatchObject({
      status: "error",
      error: expect.stringMatching(/reach the server/),
    });
  });
  it("demo mode is explicit and filters the offline fixture", async () => {
    const fetcher = vi.fn();
    const store = new ContentStore(fetcher, true);
    store.get({ ...q, category: "energy" });
    await new Promise(r => setTimeout(r, 10));
    expect(fetcher).not.toHaveBeenCalled();
    expect(
      store.get({ ...q, category: "energy" }).items.map(i => i.title)
    ).toEqual(["Solar & Battery", "EV Charging"]);
  });
});
