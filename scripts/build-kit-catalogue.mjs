#!/usr/bin/env node
/**
 * Writes the catalogue file of a Kit Library from a folder of kit zips.
 *
 *   node scripts/build-kit-catalogue.mjs <folder> --base https://kits.example.com/catalogue/
 *        [--name "My Kits"] [--out <folder>/index.json] [--require-key studio-kit,other-kit]
 *
 * For every *.zip in the folder it reads manifest.json (name, version, author, industry, license, demo, plugin needed),
 * the SHA-256 and the size, and links a picture with the same name (kit.jpg / .jpeg / .png / .webp) as its preview.
 * Upload the folder (zips, pictures and index.json) to any static host or CDN over https, then paste the address of
 * index.json into a site's Kit Library. `--base` is where the folder will live; leave it out to keep every address
 * relative (the plugin reads them against the catalogue's own address).
 */
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import {
  existsSync,
  readdirSync,
  readFileSync,
  statSync,
  writeFileSync,
} from "node:fs";
import { basename, join, resolve } from "node:path";

const argv = process.argv.slice(2);
const flag = n => {
  const i = argv.indexOf(n);
  return i >= 0 ? argv[i + 1] : undefined;
};
const dir = argv.find(
  a =>
    !a.startsWith("--") && argv[argv.indexOf(a) - 1]?.startsWith("--") !== true
);
if (!dir || !existsSync(dir) || !statSync(dir).isDirectory()) {
  console.error(
    "build-kit-catalogue: pass the folder that holds the kit zips."
  );
  process.exit(1);
}
const base = flag("--base") ?? "";
if (base && !/^https:\/\/[^\s]+$/.test(base)) {
  console.error("build-kit-catalogue: --base must be an https:// address.");
  process.exit(1);
}
const withBase = f =>
  base
    ? base.replace(/\/?$/, "/") + encodeURIComponent(f)
    : encodeURIComponent(f);
const needKey = new Set(
  (flag("--require-key") ?? "").split(",").filter(Boolean)
);
const slug = s =>
  s
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 60) || "kit";

const kits = [];
for (const f of readdirSync(dir)
  .filter(n => n.toLowerCase().endsWith(".zip"))
  .sort()) {
  const p = join(dir, f);
  let manifest;
  try {
    manifest = JSON.parse(
      execFileSync("unzip", ["-p", p, "manifest.json"], {
        maxBuffer: 4 * 1024 * 1024,
      }).toString()
    );
  } catch {
    console.warn(`skipped ${f}: no readable manifest.json (is it an RK kit?)`);
    continue;
  }
  if (manifest.format !== "rk-builder-kit") {
    console.warn(`skipped ${f}: manifest.json is not an rk-builder-kit`);
    continue;
  }
  const id = slug(String(manifest.name ?? basename(f, ".zip")));
  const preview = ["jpg", "jpeg", "png", "webp"]
    .map(e => `${basename(f, ".zip")}.${e}`)
    .find(n => existsSync(join(dir, n)));
  kits.push({
    id,
    name: String(manifest.name ?? id),
    description: String(manifest.description ?? ""),
    version: String(manifest.version ?? "1.0.0"),
    author: String(manifest.author ?? ""),
    industry: String(manifest.industry ?? ""),
    license: String(manifest.license ?? ""),
    demo: String(manifest.demo ?? ""),
    requires: String(manifest.requires?.plugin ?? ""),
    tags: manifest.industry ? [String(manifest.industry)] : [],
    download: withBase(f),
    sha256: createHash("sha256").update(readFileSync(p)).digest("hex"),
    bytes: statSync(p).size,
    ...(preview ? { preview: withBase(preview) } : {}),
    ...(needKey.has(id) ? { requiresKey: true } : {}),
  });
}
const out = {
  format: "rk-kit-catalogue",
  version: 1,
  name: flag("--name") ?? "Kit library",
  updatedAt: new Date().toISOString(),
  kits,
};
const target = resolve(flag("--out") ?? join(dir, "index.json"));
writeFileSync(target, JSON.stringify(out, null, 2) + "\n");
console.log(
  `wrote ${target} (${kits.length} kit${kits.length === 1 ? "" : "s"})`
);
