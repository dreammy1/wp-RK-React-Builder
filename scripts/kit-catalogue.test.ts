import { execFileSync, spawnSync } from "node:child_process";
import { mkdtempSync, readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

const hasZip =
  spawnSync("zip", ["-v"]).status === 0 &&
  spawnSync("unzip", ["-v"]).status === 0;

describe.skipIf(!hasZip)("build-kit-catalogue", () => {
  it("writes a catalogue with checksum, size, preview and the key requirement", () => {
    const dir = mkdtempSync(join(tmpdir(), "rk-cat-"));
    const work = mkdtempSync(join(tmpdir(), "rk-cat-w-"));
    writeFileSync(
      join(work, "manifest.json"),
      JSON.stringify({
        format: "rk-builder-kit",
        formatVersion: 1,
        name: "Studio Kit",
        version: "2.0.0",
        author: "RK",
        industry: "Design",
        requires: { plugin: "1.25.0" },
      })
    );
    writeFileSync(join(work, "site.json"), "{}");
    execFileSync(
      "zip",
      ["-q", join(dir, "studio-2.0.0.zip"), "manifest.json", "site.json"],
      { cwd: work }
    );
    writeFileSync(join(dir, "studio-2.0.0.png"), "x");
    writeFileSync(join(dir, "not-a-kit.zip"), "nope");
    const res = spawnSync(
      "node",
      [
        "scripts/build-kit-catalogue.mjs",
        dir,
        "--base",
        "https://kits.example.com/files/",
        "--name",
        "Test",
        "--require-key",
        "studio-kit",
      ],
      { encoding: "utf8" }
    );
    expect(res.status).toBe(0);
    const c = JSON.parse(readFileSync(join(dir, "index.json"), "utf8"));
    expect(c.format).toBe("rk-kit-catalogue");
    expect(c.kits).toHaveLength(1);
    const k = c.kits[0];
    expect(k.id).toBe("studio-kit");
    expect(k.version).toBe("2.0.0");
    expect(k.requires).toBe("1.25.0");
    expect(k.download).toBe("https://kits.example.com/files/studio-2.0.0.zip");
    expect(k.preview).toBe("https://kits.example.com/files/studio-2.0.0.png");
    expect(k.sha256).toMatch(/^[a-f0-9]{64}$/);
    expect(k.bytes).toBeGreaterThan(50);
    expect(k.requiresKey).toBe(true);
    expect(res.stderr).toContain("not-a-kit.zip");
  });
  it("refuses a non-https base address", () => {
    const dir = mkdtempSync(join(tmpdir(), "rk-cat-"));
    const res = spawnSync(
      "node",
      ["scripts/build-kit-catalogue.mjs", dir, "--base", "http://x.example/"],
      { encoding: "utf8" }
    );
    expect(res.status).toBe(1);
  });
});
