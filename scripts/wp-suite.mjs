#!/usr/bin/env node
/**
 * RK Suite + RK Builder module on real WordPress (WordPress Playground).
 *
 *   pnpm build:plugin
 *   RK_SUITE_ZIP=~/Downloads/rk-suite-v1.16.9.zip pnpm test:suite
 *
 * Builds the merged suite (scripts/build-suite.mjs), boots WordPress with the RK Builder module enabled,
 * then checks the module, the editor in wp-admin, public rendering and every MCP tool over /rk/v1/mcp
 * (Application Password and RK API key). Skips when RK_SUITE_ZIP is not set.
 */
import { execFileSync } from "node:child_process";
import { join } from "node:path";
import assert from "node:assert/strict";
import { root, startPlayground } from "./lib/playground.mjs";

const zip = process.env.RK_SUITE_ZIP;
if (!zip) {
  console.log(
    "test:suite skipped: set RK_SUITE_ZIP to an RK Suite release zip"
  );
  process.exit(0);
}
execFileSync(
  "node",
  [join(root, "scripts/build-suite.mjs"), "--stage-only", zip],
  {
    stdio: "inherit",
  }
);

const PORT = Number(process.env.RK_SUITE_PORT || 9431);
let stop = () => {};
let passed = 0;
async function check(name, fn) {
  try {
    await fn();
    passed++;
    console.log("  ok   " + name);
  } catch (e) {
    console.error(
      "  FAIL " + name + "\n       " + String(e?.message ?? e).split("\n")[0]
    );
    process.exitCode = 1;
  }
}

try {
  const pg = await startPlayground({
    port: PORT,
    pluginDir: join(root, "dist", "suite", "rk-suite"),
    pluginSlug: "rk-suite",
    pluginMain: "rk-suite/rk-suite.php",
    beforeActivate:
      "update_option('rk_suite_enabled', array('rk-core'=>1,'rk-api'=>1,'rk-builder'=>1));",
  });
  stop = pg.stop;
  const { base, creds } = pg;
  const basic = c =>
    "Basic " + Buffer.from(`${c.user}:${c.pass}`).toString("base64");
  const url = `${base}/wp-json/rk/v1/mcp`;
  let rpcId = 0;
  const rpc = async (method, params, headers) => {
    const r = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", ...headers },
      body: JSON.stringify({ jsonrpc: "2.0", id: ++rpcId, method, params }),
    });
    return { status: r.status, json: await r.json().catch(() => null) };
  };
  const asAdmin = { Authorization: basic(creds.admin) };
  const asEditor = { Authorization: basic(creds.editor) };
  const asKey = { "X-RK-API-Key": creds.apiKey };
  const tool = async (name, args, headers = asAdmin) => {
    const r = await rpc("tools/call", { name, arguments: args }, headers);
    assert.equal(r.status, 200, JSON.stringify(r.json));
    assert.ok(r.json.result, JSON.stringify(r.json));
    return {
      isError: r.json.result.isError,
      data: JSON.parse(r.json.result.content[0].text),
    };
  };
  const id = creds.pageId;

  await check("RK Suite lists RK Builder as an enabled module", async () => {
    const r = await tool("wp_list_modules", {});
    const m = JSON.stringify(r.data);
    assert.match(m, /rk-builder/);
  });
  await check(
    "MCP tools/list includes the builder tools next to the suite's",
    async () => {
      const r = await rpc("tools/list", {}, asAdmin);
      const names = r.json.result.tools.map(t => t.name);
      for (const n of [
        "wp_builder_block_types",
        "wp_builder_list_pages",
        "wp_builder_get_layout",
        "wp_builder_save_layout",
        "wp_builder_preview_link",
        "wp_builder_publish",
        "wp_builder_unpublish",
        "wp_builder_get_theme",
        "wp_builder_save_theme",
        "wp_builder_export_site",
        "wp_builder_import_site",
        "wp_builder_list_reusables",
        "wp_builder_save_reusable",
        "wp_builder_list_media",
        "wp_list_pages",
      ])
        assert.ok(names.includes(n), `missing ${n}`);
    }
  );
  await check("anonymous MCP call is rejected", async () => {
    const r = await rpc("tools/list", {}, {});
    assert.equal(r.status, 401);
  });
  await check(
    "block_types describes the strict format incl. the new blocks",
    async () => {
      const { data } = await tool("wp_builder_block_types", {});
      assert.ok(data.blocks.hero.bgUrl);
      assert.ok(data.blocks.testimonial.quote);
      assert.ok(data.blocks.contact.phone);
    }
  );

  const layout = {
    version: 1,
    blocks: [
      {
        id: "hero",
        type: "hero",
        props: {
          heading: "Hardwood floors, done right in Peoria",
          sub: "Install, sand & refinish.",
          cta: "Get a free estimate",
          ctaHref: "#contact",
          bgMediaId: creds.mediaId,
          bgUrl: "/placeholder.svg",
        },
      },
      {
        id: "quote",
        type: "testimonial",
        props: {
          quote: "Looks incredible.",
          author: "Dana R.",
          role: "Peoria",
        },
      },
      {
        id: "contact",
        type: "contact",
        props: {
          heading: "Get in touch",
          intro: "",
          phone: "(309) 555-0142",
          email: "info@example.com",
          address: "",
          hours: "",
        },
      },
    ],
  };
  await check(
    "save_layout validates strictly (unknown prop → rk_invalid_layout, nothing saved)",
    async () => {
      const bad = structuredClone(layout);
      bad.blocks[0].props.evil = "x";
      const r = await tool("wp_builder_save_layout", { id, layout: bad });
      assert.equal(r.isError, true);
      assert.equal(r.data.code, "rk_invalid_layout");
      assert.equal(
        (await tool("wp_builder_get_layout", { id })).data.revision,
        0
      );
    }
  );
  await check(
    "save_layout saves a draft; get_layout round-trips it",
    async () => {
      const s = await tool("wp_builder_save_layout", { id, layout });
      assert.equal(s.isError, false, JSON.stringify(s.data));
      assert.equal(s.data.revision, 1);
      const g = await tool("wp_builder_get_layout", { id });
      assert.equal(g.data.layout.blocks.length, 3);
      assert.equal(g.data.page.status, "draft");
    }
  );
  await check(
    "stale expectedRevision → 409 conflict surfaced as a tool error",
    async () => {
      const r = await tool("wp_builder_save_layout", {
        id,
        layout,
        expectedRevision: 0,
      });
      assert.equal(r.isError, true);
      assert.equal(r.data.code, "rk_revision_conflict");
    }
  );
  await check("draft is not public; preview link shows it", async () => {
    assert.equal((await fetch(`${base}/smoke/`)).status, 404);
    const { data } = await tool("wp_builder_preview_link", { id });
    const html = await (await fetch(data.url)).text();
    assert.match(html, /Hardwood floors, done right in Peoria/);
    assert.match(html, /class="hero-bg"/);
    assert.match(html, /href="tel:3095550142"/);
    assert.match(html, /id="contact"/);
  });
  await check(
    "publish needs confirm=true; with it the page goes live",
    async () => {
      const no = await tool("wp_builder_publish", { id });
      assert.equal(no.isError, true);
      assert.equal(no.data.code, "rk_confirmation_required");
      assert.equal((await fetch(`${base}/smoke/`)).status, 404);
      const yes = await tool("wp_builder_publish", { id, confirm: true });
      assert.equal(yes.isError, false, JSON.stringify(yes.data));
      const res = await fetch(`${base}/smoke/`);
      assert.equal(res.status, 200);
      assert.match(await res.text(), /Looks incredible/);
    }
  );
  await check(
    "unpublish needs confirm=true and returns the page to 404",
    async () => {
      assert.equal((await tool("wp_builder_unpublish", { id })).isError, true);
      const r = await tool("wp_builder_unpublish", { id, confirm: true });
      assert.equal(r.isError, false, JSON.stringify(r.data));
      assert.equal((await fetch(`${base}/smoke/`)).status, 404);
    }
  );
  await check(
    "editor may edit but not change the theme; admin can",
    async () => {
      const t = (await tool("wp_builder_get_theme", {}, asEditor)).data;
      const e = await tool(
        "wp_builder_save_theme",
        { theme: t, confirm: true },
        asEditor
      );
      assert.equal(e.isError, true);
      assert.equal(e.data.code, "rk_forbidden");
      const a = await tool("wp_builder_save_theme", {
        theme: { ...t, primary: "#8a5a2b" },
        confirm: true,
      });
      assert.equal(a.isError, false, JSON.stringify(a.data));
      assert.equal(
        (await tool("wp_builder_get_theme", {})).data.primary,
        "#8a5a2b"
      );
    }
  );
  await check(
    "export_site → import_site: dry run by default, real import needs confirm, pages arrive as drafts",
    async () => {
      const ex = await tool("wp_builder_export_site", {});
      assert.equal(ex.isError, false, JSON.stringify(ex.data).slice(0, 200));
      assert.equal(ex.data.format, "rk-builder-site");
      const bundle = structuredClone(ex.data);
      bundle.pages = [
        {
          slug: "from-bundle",
          title: "From Bundle",
          layout: {
            version: 1,
            blocks: [
              {
                id: "s1",
                type: "spacer",
                props: { h: 40 },
              },
            ],
          },
        },
      ];
      bundle.media = [];
      const dry = await tool("wp_builder_import_site", { bundle });
      assert.equal(dry.isError, false, JSON.stringify(dry.data));
      assert.equal(dry.data.dryRun, true);
      assert.equal(dry.data.pages.create, 1);
      const list0 = await tool("wp_builder_list_pages", {
        search: "From Bundle",
      });
      assert.equal(list0.data.total, 0);
      const noConfirm = await tool("wp_builder_import_site", {
        bundle,
        dryRun: false,
      });
      assert.equal(noConfirm.isError, true);
      assert.equal(noConfirm.data.code, "rk_confirmation_required");
      const real = await tool("wp_builder_import_site", {
        bundle,
        dryRun: false,
        confirm: true,
      });
      assert.equal(real.isError, false, JSON.stringify(real.data));
      assert.equal(real.data.pages.done[0].action, "created");
      const list1 = await tool("wp_builder_list_pages", {
        search: "From Bundle",
      });
      assert.equal(list1.data.pages[0].status, "draft");
      const denied = await tool("wp_builder_export_site", {}, asEditor);
      assert.equal(denied.isError, true);
      assert.equal(denied.data.code, "rk_forbidden");
    }
  );
  await check(
    "reusable blocks via MCP: create, reference from a page, update needs confirm, public page follows the library",
    async () => {
      const made = await tool("wp_builder_save_reusable", {
        name: "Estimate CTA",
        block: {
          type: "cta",
          props: { heading: "Free estimate", cta: "Call", ctaHref: "#contact" },
        },
      });
      assert.equal(made.isError, false, JSON.stringify(made.data));
      const rid = made.data.item.id;
      const list = await tool("wp_builder_list_reusables", {});
      assert.ok(list.data.items.some(i => i.id === rid));
      const nested = await tool("wp_builder_save_reusable", {
        name: "Nope",
        block: { type: "reusable", props: { refId: rid } },
      });
      assert.equal(nested.isError, true);
      assert.equal(nested.data.code, "rk_invalid_reusable");
      const pg = await tool("wp_builder_save_layout", {
        id,
        layout: {
          version: 1,
          blocks: [{ id: "shared", type: "reusable", props: { refId: rid } }],
        },
      });
      assert.equal(pg.isError, false, JSON.stringify(pg.data));
      const noConfirm = await tool("wp_builder_save_reusable", {
        id: rid,
        block: {
          type: "cta",
          props: { heading: "Changed", cta: "Call", ctaHref: "#contact" },
        },
      });
      assert.equal(noConfirm.data.code, "rk_confirmation_required");
      const upd = await tool("wp_builder_save_reusable", {
        id: rid,
        confirm: true,
        block: {
          type: "cta",
          props: { heading: "Changed", cta: "Call", ctaHref: "#contact" },
        },
      });
      assert.equal(upd.isError, false, JSON.stringify(upd.data));
      const link = await tool("wp_builder_preview_link", { id });
      const html = await (await fetch(link.data.url)).text();
      assert.match(html, /Changed/);
      const del = await tool("wp_builder_save_layout", {
        id,
        layout: { version: 1, blocks: [] },
      });
      assert.equal(del.isError, false);
    }
  );
  await check(
    "RK API key works (runs as the first administrator)",
    async () => {
      const r = await tool("wp_builder_list_pages", {}, asKey);
      assert.equal(r.isError, false, JSON.stringify(r.data));
      assert.ok(r.data.pages.some(p => p.id === id));
    }
  );
  await check("list_media returns attachments", async () => {
    const r = await tool("wp_builder_list_media", {});
    assert.ok(r.data.items.some(m => m.id === creds.mediaId));
  });
  await check(
    "the REST routes still work for the editor under the suite (no namespace clash)",
    async () => {
      const r = await fetch(`${base}/wp-json/rk/v1/builder/layout/${id}`, {
        headers: asEditor,
      });
      assert.equal(r.status, 200);
      const p = await fetch(`${base}/wp-json/rk/v1/pages`, {
        headers: asAdmin,
      });
      assert.equal(p.status, 200);
    }
  );
  await check(
    "wp-admin opens the builder from the RK menu (logged-in cookie)",
    async () => {
      const jar = [];
      const login = await fetch(`${base}/wp-login.php`, {
        method: "POST",
        redirect: "manual",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
          Cookie: "wordpress_test_cookie=WP%20Cookie%20check",
        },
        body: new URLSearchParams({
          log: "admin",
          pwd: "rk-e2e-admin-password",
          "wp-submit": "Log In",
          testcookie: "1",
        }),
      });
      for (const c of login.headers.getSetCookie()) jar.push(c.split(";")[0]);
      // Enabling the module queues the one-time setup-wizard redirect; consume it first.
      await fetch(`${base}/wp-admin/`, { headers: { Cookie: jar.join("; ") } });
      const page = await fetch(
        `${base}/wp-admin/admin.php?page=rk-builder&page_id=${id}`,
        { headers: { Cookie: jar.join("; ") } }
      );
      const html = await page.text();
      assert.equal(page.status, 200);
      if (!/RK_BUILDER_BOOT/.test(html))
        throw new Error(
          `no boot; cookies=${jar.length}; opening=${html.includes("Opening RK Builder")}; denied=${/not allowed|permission/i.test(html)}; body=${html
            .replace(/<script[\s\S]*?<\/script>/g, "")
            .replace(/<[^>]+>/g, " ")
            .replace(/\s+/g, " ")
            .slice(0, 300)}`
        );
      assert.match(
        html,
        /plugins\/rk-suite\/modules\/rk-builder\/assets\/builder\.js/
      );
      const js = await fetch(html.match(/src="([^"]*builder\.js[^"]*)"/)[1]);
      assert.equal(js.status, 200);
    }
  );
  console.log(
    `\n${passed} checks passed${process.exitCode ? ", some FAILED" : ""}`
  );
} catch (e) {
  console.error(e);
  process.exitCode = 1;
} finally {
  stop();
}
process.exit(process.exitCode ?? 0);
