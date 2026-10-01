#!/usr/bin/env node
/**
 * Real-WordPress smoke test for the RK Builder plugin, using WordPress Playground (WASM, SQLite).
 *
 *   node scripts/wp-smoke.mjs
 *
 * Boots Playground with wp-plugin/rk-builder mounted + activated, creates users (admin, editor,
 * subscriber) with Application Passwords and a draft page, then drives the REST API over HTTP:
 * 401/403 boundaries, save -> load round trip, 409 on stale revision, publish -> public page,
 * draft edits not leaking, unpublish -> 404, preview token, CORS allow-list, content endpoint.
 * Exits non-zero on any failure. Needs network on first run (downloads WordPress + @wp-playground/cli).
 */
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { root, startPlayground } from "./lib/playground.mjs";
import assert from "node:assert/strict";

const PORT = Number(process.env.RK_SMOKE_PORT || 9411);
const layoutFull = JSON.parse(
  readFileSync(join(root, "contracts/valid/layout-full.json"), "utf8")
).document;
let BASE = `http://127.0.0.1:${PORT}`;
let stopPlayground = () => {};
process.on("SIGINT", () => process.exit(130));
const cleanup = () => stopPlayground();

let apiPrefix = "/wp-json";
const auth = c => ({
  Authorization:
    "Basic " + Buffer.from(`${c.user}:${c.pass}`).toString("base64"),
});
async function api(method, path, { body, headers = {}, as } = {}) {
  const url =
    apiPrefix === "/wp-json"
      ? `${BASE}/wp-json/rk/v1${path}`
      : `${BASE}/?rest_route=/rk/v1${path}`;
  const res = await fetch(
    apiPrefix === "/wp-json" ? url : url.replace("?preview", "&preview"),
    {
      method,
      headers: {
        ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
        ...(as ? auth(as) : {}),
        ...headers,
      },
      body:
        body === undefined
          ? undefined
          : typeof body === "string"
            ? body
            : JSON.stringify(body),
    }
  );
  const text = await res.text();
  let json;
  try {
    json = JSON.parse(text);
  } catch {
    json = text;
  }
  return { status: res.status, headers: res.headers, json };
}

let passed = 0;
async function check(name, fn) {
  try {
    await fn();
    passed++;
    console.log("  ok   " + name);
  } catch (e) {
    console.error(
      "  FAIL " +
        name +
        "\n       " +
        (e && e.message ? e.message.split("\n")[0] : e)
    );
    process.exitCode = 1;
  }
}

try {
  const pg = await startPlayground({ port: PORT });
  BASE = pg.base;
  stopPlayground = pg.stop;
  const creds = pg.creds;
  const id = creds.pageId;
  // Prefer pretty permalinks; fall back to ?rest_route= if /wp-json is not routed.
  const probe = await fetch(`${BASE}/wp-json/`);
  if (probe.status !== 200) apiPrefix = "?rest_route";
  console.log(`WordPress up at ${BASE} (api via ${apiPrefix}), page ${id}`);

  await check("anonymous -> 401 rk_unauthorized", async () => {
    const r = await api("GET", `/builder/layout/${id}`);
    assert.equal(r.status, 401);
    assert.equal(r.json.code, "rk_unauthorized");
  });
  await check("subscriber -> 403 rk_forbidden", async () => {
    const r = await api("GET", `/builder/layout/${id}`, {
      as: creds.subscriber,
    });
    assert.equal(r.status, 403, JSON.stringify(r.json));
    assert.equal(r.json.code, "rk_forbidden");
  });
  await check("editor loads empty layout at revision 0", async () => {
    const r = await api("GET", `/builder/layout/${id}`, { as: creds.editor });
    assert.equal(r.status, 200, JSON.stringify(r.json));
    assert.equal(r.json.revision, 0);
    assert.deepEqual(r.json.layout, { version: 1, blocks: [] });
    assert.equal(r.json.capabilities.manageTheme, false);
  });
  await check("save -> load round trip equals layout-full", async () => {
    const s = await api("POST", `/builder/layout/${id}`, {
      as: creds.editor,
      body: { layout: layoutFull, expectedRevision: 0, status: "draft" },
    });
    assert.equal(s.status, 200, JSON.stringify(s.json));
    assert.equal(s.json.revision, 1);
    const g = await api("GET", `/builder/layout/${id}`, { as: creds.editor });
    assert.deepEqual(g.json.layout, layoutFull);
  });
  await check(
    "stale expectedRevision -> 409 with currentRevision",
    async () => {
      const r = await api("POST", `/builder/layout/${id}`, {
        as: creds.editor,
        body: { layout: layoutFull, expectedRevision: 0, status: "draft" },
      });
      assert.equal(r.status, 409);
      assert.equal(r.json.code, "rk_revision_conflict");
      assert.equal(r.json.data.currentRevision, 1);
    }
  );
  await check(
    "unknown block -> 400 rk_invalid_layout with issues",
    async () => {
      const r = await api("POST", `/builder/layout/${id}`, {
        as: creds.editor,
        body: {
          layout: {
            version: 1,
            blocks: [{ id: "x", type: "columns", props: {} }],
          },
          expectedRevision: 1,
          status: "draft",
        },
      });
      assert.equal(r.status, 400);
      assert.equal(r.json.code, "rk_invalid_layout");
      assert.ok(r.json.data.issues.length >= 1);
    }
  );
  await check("editor cannot change theme (403) but admin can", async () => {
    const theme = {
      version: 1,
      primary: "#112233",
      bg: "#ffffff",
      ink: "#000000",
      font: "Georgia",
    };
    const e = await api("POST", `/theme-config`, {
      as: creds.editor,
      body: theme,
    });
    assert.equal(e.status, 403);
    assert.equal(e.json.code, "rk_forbidden");
    const a = await api("POST", `/theme-config`, {
      as: creds.admin,
      body: theme,
    });
    assert.equal(a.status, 200, JSON.stringify(a.json));
    assert.equal(a.json.ok, true);
    const pub = await api("GET", `/theme-config`);
    assert.equal(pub.status, 200);
    assert.equal(pub.json.primary, "#112233");
  });
  await check("revisions list + restore creates a new revision", async () => {
    const l = await api("GET", `/builder/revisions/${id}`, {
      as: creds.editor,
    });
    assert.equal(l.status, 200);
    assert.equal(l.json.revisions[0].kind, "draft");
    assert.equal(l.json.revisions[0].author, "Smoke Editor");
    const r = await api("POST", `/builder/revisions/${id}/1/restore`, {
      as: creds.editor,
      body: { expectedRevision: 1 },
    });
    assert.equal(r.status, 200, JSON.stringify(r.json));
    assert.equal(r.json.revision, 2);
  });
  await check(
    "public page 404 before publish (existence not leaked)",
    async () => {
      const r = await api("GET", `/public/page/smoke`);
      assert.equal(r.status, 404);
      assert.equal(r.json.code, "rk_not_found");
    }
  );
  await check(
    "publish -> public page serves snapshot; later draft save does not change it",
    async () => {
      const p = await api("POST", `/builder/publish/${id}`, {
        as: creds.editor,
        body: { expectedRevision: 2 },
      });
      assert.equal(p.status, 200, JSON.stringify(p.json));
      assert.equal(p.json.status, "publish");
      assert.equal(p.json.publishedRevision, 3);
      const pub = await api("GET", `/public/page/smoke`);
      assert.equal(pub.status, 200, JSON.stringify(pub.json));
      assert.equal(pub.json.layout.blocks.length, 9);
      assert.match(pub.headers.get("cache-control") || "", /s-maxage=60/);
      const small = {
        version: 1,
        blocks: [{ id: "only", type: "spacer", props: { h: 40 } }],
      };
      const s = await api("POST", `/builder/layout/${id}`, {
        as: creds.editor,
        body: { layout: small, expectedRevision: 3, status: "draft" },
      });
      assert.equal(s.status, 200, JSON.stringify(s.json));
      assert.equal(s.json.status, "publish");
      const again = await api("GET", `/public/page/smoke`);
      assert.equal(
        again.json.layout.blocks.length,
        9,
        "draft leaked to public"
      );
    }
  );
  await check(
    "preview token serves the draft with no-store; tampered token does not",
    async () => {
      const t = await api("POST", `/builder/preview-token/${id}`, {
        as: creds.editor,
      });
      assert.equal(t.status, 200);
      assert.ok(t.json.token);
      const pv = await api(
        "GET",
        `/public/page/smoke?preview=${encodeURIComponent(t.json.token)}`
      );
      assert.equal(pv.status, 200);
      assert.equal(pv.json.preview, true);
      assert.equal(pv.json.layout.blocks.length, 1);
      assert.match(pv.headers.get("cache-control") || "", /no-store/);
      const bad = await api(
        "GET",
        `/public/page/smoke?preview=${encodeURIComponent(t.json.token.slice(0, -2) + "xx")}`
      );
      assert.equal(bad.status, 404);
      assert.equal(bad.json.code, "rk_preview_invalid");
      assert.equal(bad.json.data.status, 404);
      assert.equal(bad.json.layout, undefined);
    }
  );
  await check("unpublish -> public 404 again", async () => {
    const u = await api("POST", `/builder/unpublish/${id}`, {
      as: creds.editor,
    });
    assert.equal(u.status, 200, JSON.stringify(u.json));
    assert.equal(u.json.status, "draft");
    assert.equal((await api("GET", `/public/page/smoke`)).status, 404);
  });
  await check(
    "content endpoint: published services only, plain-text excerpt",
    async () => {
      const r = await api("GET", `/content/service?limit=6`);
      assert.equal(r.status, 200);
      assert.equal(r.json.total, 2);
      assert.ok(r.json.items.every(i => !i.excerpt.includes("<")));
      assert.equal((await api("GET", `/content/service?limit=99`)).status, 400);
      assert.equal((await api("GET", `/content/post`)).status, 404);
    }
  );
  await check(
    "CORS: allow-listed origin echoed exactly with credentials; evil/null origin gets nothing; never *",
    async () => {
      const ok = await api("GET", `/theme-config`, {
        headers: { Origin: "https://editor.example.com" },
      });
      assert.equal(
        ok.headers.get("access-control-allow-origin"),
        "https://editor.example.com"
      );
      assert.equal(ok.headers.get("access-control-allow-credentials"), "true");
      for (const o of ["https://evil.example", "null"]) {
        const r = await api("GET", `/theme-config`, { headers: { Origin: o } });
        assert.equal(
          r.headers.get("access-control-allow-origin"),
          null,
          `origin ${o}`
        );
      }
    }
  );
  console.log(
    `\n${passed} checks passed${process.exitCode ? ", some FAILED" : ""}`
  );
} catch (e) {
  console.error(e);
  process.exitCode = 1;
} finally {
  cleanup();
}
process.exit(process.exitCode ?? 0);
