#!/usr/bin/env node
/**
 * Import site bundles into a throwaway real WordPress (Playground) and check the result end to end, including the
 * image downloads. Needs network. Use it to rehearse an import before running it on a live site.
 *
 *   pnpm build:plugin
 *   node scripts/wp-import-bundle.mjs dist/peoria/1-core.json dist/peoria/2-services.json [--theme --content]
 */
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { root, startPlayground } from "./lib/playground.mjs";

const args = process.argv.slice(2);
const files = args.filter(a => !a.startsWith("--"));
const opts = {
  theme: args.includes("--theme"),
  content: args.includes("--content"),
  contentStatus: args.includes("--publish-content") ? "publish" : "draft",
};
if (!files.length) {
  console.error(
    "usage: wp-import-bundle.mjs <bundle.json>... [--theme] [--content]"
  );
  process.exit(2);
}
let stop = () => {};
try {
  const pg = await startPlayground({
    port: Number(process.env.RK_IMPORT_PORT || 9441),
    pluginDir: resolve(root, "dist/plugin/rk-builder"),
  });
  stop = pg.stop;
  const auth =
    "Basic " +
    Buffer.from(`${pg.creds.admin.user}:${pg.creds.admin.pass}`).toString(
      "base64"
    );
  const call = async (method, path, body) => {
    const r = await fetch(`${pg.base}/wp-json/rk/v1/${path}`, {
      method,
      headers: { Authorization: auth, "Content-Type": "application/json" },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    return { status: r.status, json: await r.json().catch(() => null) };
  };
  for (const f of files) {
    const bundle = JSON.parse(readFileSync(f, "utf8"));
    const t0 = Date.now();
    const r = await call("POST", "builder/site-import", {
      bundle,
      options: { ...opts, dryRun: false },
    });
    console.log(
      `\n== ${f}  (${((Date.now() - t0) / 1000).toFixed(1)}s, HTTP ${r.status})`
    );
    if (r.status !== 200) {
      console.log(JSON.stringify(r.json, null, 2));
      process.exitCode = 1;
      continue;
    }
    const rep = r.json;
    console.log(
      `pages: +${rep.pages.create} new, ${rep.pages.update} updated, ${rep.pages.skipped.length} skipped`
    );
    console.log(
      `reusables: +${rep.reusables.create} new, ${rep.reusables.update} updated, ${rep.reusables.skipped.length} skipped`
    );
    console.log(
      `images: ${rep.media.imported} copied, ${rep.media.reused} reused, ${rep.media.failed.length} failed`
    );
    console.log(
      `theme applied: ${rep.theme.applied}; content: +${rep.content.created} / ~${rep.content.updated}`
    );
    for (const s of rep.pages.skipped)
      console.log("  skipped page", s.slug, s.issues.join("; "));
    for (const m of rep.media.failed.slice(0, 5))
      console.log("  failed image", m.url, "-", m.reason);
    for (const w of rep.warnings) console.log("  note:", w);
    if (
      rep.pages.skipped.length ||
      rep.media.failed.length ||
      rep.reusables.skipped.length
    )
      process.exitCode = 1;
  }
  const list = await call("GET", "builder/pages?per_page=50");
  console.log(
    `\npages in WordPress: ${list.json.pages.map(p => `${p.slug}(${p.status})`).join(", ")}`
  );
  const lib = await call("GET", "builder/reusables");
  console.log(`library: ${lib.json.items.map(i => i.name).join(", ")}`);
  const home = list.json.pages.find(p => p.slug === "home");
  if (home) {
    const tok = await call("POST", `builder/preview-token/${home.id}`, {});
    const html = await (await fetch(tok.json.url)).text();
    const checks = {
      "hero background is a local upload":
        /class="hero-bg" src="[^"]*\/wp-content\/uploads\//.test(html),
      "hero text": /Hardwood floors, crafted and cared for in Peoria/.test(
        html
      ),
      "reusable call-to-action rendered":
        /Ready to talk about your floors\?/.test(html),
      "reusable service area rendered": /Towns we serve/.test(html),
      "no GitHub image left in the page": !/raw\.githubusercontent\.com/.test(
        html
      ),
    };
    for (const [k, v] of Object.entries(checks)) {
      console.log(`${v ? "ok  " : "FAIL"} home preview: ${k}`);
      if (!v) process.exitCode = 1;
    }
  }
} catch (e) {
  console.error(e);
  process.exitCode = 1;
} finally {
  stop();
}
process.exit(process.exitCode ?? 0);
