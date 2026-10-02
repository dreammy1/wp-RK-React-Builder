import { readFileSync, readdirSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";
import { registry } from "@/blocks/registry";
import { BLOCK_TYPES, LIMITS, isSafeImageUrl, isSafeLink } from "./primitives";
import { LayoutSchema } from "./layout";
import { parseLayout, parseTheme } from "./migrate";
import {
  DEFAULT_THEME,
  themeToCssText,
  themeToCssVars,
  ThemeSchema,
} from "./theme";

const root = path.resolve(import.meta.dirname, "../../../..");
type Fixture = {
  kind: "layout" | "theme";
  description: string;
  document: unknown;
};
const load = (dir: string): [string, Fixture][] =>
  readdirSync(path.join(root, "contracts", dir))
    .filter(f => f.endsWith(".json"))
    .map(f => [
      f,
      JSON.parse(
        readFileSync(path.join(root, "contracts", dir, f), "utf8")
      ) as Fixture,
    ]);
const parse = (f: Fixture) =>
  f.kind === "layout" ? parseLayout(f.document) : parseTheme(f.document);

describe("shared contract fixtures", () => {
  for (const [name, fx] of load("valid")) {
    it(`accepts valid/${name}`, () => {
      const r = parse(fx);
      expect(r.ok, JSON.stringify(r)).toBe(true);
      if (r.ok) {
        expect(r.migrated).toBe(false);
        // Round trip: serialize → parse yields an equivalent document.
        expect(
          parse({ ...fx, document: JSON.parse(JSON.stringify(r.value)) })
        ).toEqual(r);
        expect(r.value).toEqual(fx.document);
      }
    });
  }
  for (const [name, fx] of load("invalid")) {
    it(`rejects invalid/${name}: ${fx.description}`, () => {
      const r = parse(fx);
      expect(r.ok).toBe(false);
      if (!r.ok) expect(r.issues.length).toBeGreaterThan(0);
    });
  }
});

describe("migrations", () => {
  it("upgrades prototype flat blocks to props", () => {
    const fx = JSON.parse(
      readFileSync(path.join(root, "contracts/legacy/layout-v0.json"), "utf8")
    ) as Fixture;
    const r = parseLayout(fx.document);
    expect(r.ok).toBe(true);
    if (r.ok) {
      expect(r.migrated).toBe(true);
      expect(r.value.blocks[0]).toEqual({
        id: "a1",
        type: "hero",
        props: { heading: "Hi", sub: "s", cta: "Go", ctaHref: "/x" },
      });
      expect(r.value.blocks[1]).toMatchObject({
        type: "services",
        props: { source: "service" },
      });
    }
  });
  it("treats a missing version with a blocks array as v0", () => {
    const r = parseLayout({ blocks: [{ id: "s", type: "spacer", h: 24 }] });
    expect(r).toMatchObject({ ok: true, migrated: true });
  });
  it("migrates the old theme shape (logo → logoUrl, versionless)", () => {
    const r = parseTheme({
      primary: "#000000",
      bg: "#ffffff",
      ink: "#111111",
      font: "Georgia",
      logo: "https://cms.example.com/l.png",
    });
    expect(r).toMatchObject({ ok: true, migrated: true });
    if (r.ok) expect(r.value.logoUrl).toBe("https://cms.example.com/l.png");
  });
  it("never repairs genuinely invalid data silently", () => {
    expect(
      parseLayout({
        version: 1,
        blocks: [{ id: "a", type: "hero", props: { heading: "" } }],
      }).ok
    ).toBe(false);
  });
});

describe("limits", () => {
  const spacer = (i: number) => ({
    id: `s${i}`,
    type: "spacer",
    props: { h: 40 },
  });
  it("allows exactly maxBlocks and rejects one more", () => {
    const mk = (n: number) => ({
      version: 1,
      blocks: Array.from({ length: n }, (_, i) => spacer(i)),
    });
    expect(LayoutSchema.safeParse(mk(LIMITS.maxBlocks)).success).toBe(true);
    expect(LayoutSchema.safeParse(mk(LIMITS.maxBlocks + 1)).success).toBe(
      false
    );
  });
  it("reports duplicate ids with a useful path", () => {
    const r = parseLayout({ version: 1, blocks: [spacer(1), spacer(1)] });
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.issues[0]).toMatchObject({ path: "blocks.1.id" });
  });
});

describe("url safety", () => {
  it.each([
    "javascript:alert(1)",
    " javascript:alert(1)",
    "JaVaScRiPt:alert(1)",
    "data:text/html,x",
    "vbscript:x",
    "//evil.example",
    "/a\\b",
    "http://",
    "ht tp://x",
  ])("rejects link %j", v => expect(isSafeLink(v)).toBe(false));
  it.each([
    "",
    "/contact",
    "#top",
    "https://example.com/x?y=1",
    "mailto:a@b.co",
    "tel:+15551234",
  ])("accepts link %j", v => expect(isSafeLink(v)).toBe(true));
  it.each([
    "data:image/png;base64,AAAA",
    "",
    "javascript:x",
    "mailto:a@b.co",
    "//cdn.example/x.png",
  ])("rejects image %j", v => expect(isSafeImageUrl(v)).toBe(false));
  it.each(["/a.png", "https://cms.example.com/a.png"])("accepts image %j", v =>
    expect(isSafeImageUrl(v)).toBe(true)
  );
});

describe("block registry ↔ schema", () => {
  it("covers every block type", () =>
    expect(Object.keys(registry).sort()).toEqual([...BLOCK_TYPES].sort()));
  for (const type of BLOCK_TYPES) {
    it(`${type}: defaults satisfy their own schema and every field maps to a prop`, () => {
      const def = registry[type];
      expect(def.schema.safeParse(def.defaults).success).toBe(true);
      expect(
        LayoutSchema.safeParse({
          version: 1,
          blocks: [{ id: `${type}-1`, type, props: def.defaults }],
        }).success
      ).toBe(true);
      const keys = Object.keys(def.defaults);
      for (const f of def.fields) {
        if ("optional" in f && f.optional) continue;
        expect(keys).toContain(f.key);
      }
    });
  }
});

describe("theme", () => {
  it("default theme is valid", () =>
    expect(ThemeSchema.safeParse(DEFAULT_THEME).success).toBe(true));
  it("emits only custom properties from validated tokens", () => {
    const vars = themeToCssVars(DEFAULT_THEME);
    expect(Object.keys(vars)).toEqual([
      "--site-primary",
      "--site-bg",
      "--site-ink",
      "--site-font",
    ]);
    expect(themeToCssText(DEFAULT_THEME, ".x")).toBe(
      ".x{--site-primary:#C7F36B;--site-bg:#F8F5ED;--site-ink:#1B2430;--site-font:'Space Grotesk', system-ui, sans-serif}"
    );
  });
  it("rejects css injection through colors", () => {
    expect(
      ThemeSchema.safeParse({
        ...DEFAULT_THEME,
        primary: "#000;}body{display:none",
      }).success
    ).toBe(false);
  });
});
