import { describe, expect, it } from "vitest";
import {
  advancedToCss,
  layoutStyles,
  AdvancedStyleSchema,
  type AdvancedStyle,
} from "./style";
import { LayoutSchema } from "./layout";
import { parseLayout } from "./migrate";

const id = "hero-01";

describe("advanced style schema", () => {
  it("accepts a fully-populated style", () => {
    const style = {
      spacing: { top: "24px", bottom: "24px" },
      size: { width: "narrow", minHeight: "320px", colSpan: 2 },
      background: {
        color: "#112233",
        gradient: "fade",
        imageUrl: "https://cms.example.com/a.jpg",
        imageFit: "cover",
        imagePosition: "top",
      },
      border: { width: "2px", color: "#000000", style: "dashed", radius: "12px" },
      shadow: { preset: "lg" },
      typography: { size: "40px", weight: 700, align: "center", color: "#ffffff" },
      visibility: { hidePhone: true },
      overrides: { phone: { spacing: { top: "8px" } } },
      cssClass: "hero-custom",
      name: "Hero",
    };
    expect(AdvancedStyleSchema.safeParse(style).success).toBe(true);
  });

  it("is optional and rejects unknown keys", () => {
    expect(AdvancedStyleSchema.safeParse(undefined).success).toBe(true);
    expect(AdvancedStyleSchema.safeParse({ nope: 1 }).success).toBe(false);
  });

  it("rejects anything that is not a strict pixel length", () => {
    for (const bad of ["24", "24em", "2.5px", "calc(100% - 4px)", "-4px", "24px;"])
      expect(
        AdvancedStyleSchema.safeParse({ spacing: { top: bad } }).success
      ).toBe(false);
    for (const good of ["0px", "8px", "120px"])
      expect(
        AdvancedStyleSchema.safeParse({ spacing: { top: good } }).success
      ).toBe(true);
  });

  it("rejects a colour that could break out of the declaration", () => {
    expect(
      AdvancedStyleSchema.safeParse({
        background: { color: "#000;}body{display:none" },
      }).success
    ).toBe(false);
    expect(
      AdvancedStyleSchema.safeParse({ background: { color: "transparent" } })
        .success
    ).toBe(true);
  });
});

describe("advanced style on a block", () => {
  const withStyle = (advanced?: unknown) => ({
    version: 1,
    blocks: [{ id, type: "hero", props: { heading: "Hi", sub: "", cta: "", ctaHref: "/" }, advanced }],
  });

  it("keeps documents without a style byte-for-byte identical", () => {
    const parsed = parseLayout(withStyle(undefined));
    expect(parsed.ok).toBe(true);
    if (parsed.ok) expect(parsed.value).toEqual(withStyle(undefined));
  });

  it("round-trips a styled block", () => {
    const doc = withStyle({ spacing: { top: "32px" }, cssClass: "hero-custom" });
    const parsed = parseLayout(doc);
    expect(parsed.ok).toBe(true);
    if (parsed.ok) expect(parsed.value).toEqual(doc);
  });

  it("still rejects an unknown block key", () => {
    expect(
      LayoutSchema.safeParse({
        version: 1,
        blocks: [
          { id, type: "hero", props: { heading: "H", sub: "", cta: "", ctaHref: "/" }, extra: 1 },
        ],
      }).success
    ).toBe(false);
  });
});

describe("advancedToCss", () => {
  it("emits nothing for an empty or absent style", () => {
    expect(advancedToCss(id, undefined)).toBe("");
    expect(advancedToCss(id, {})).toBe("");
  });

  it("scopes every rule to the block and the surface", () => {
    const css = advancedToCss(id, { spacing: { top: "24px" } }, "public");
    expect(css).toBe(`.site-root .rk-style-${id}{padding-top:24px}`);
    expect(advancedToCss(id, { spacing: { top: "24px" } }, "editor")).toContain(
      `.editor-canvas .rk-style-${id}`
    );
  });

  it("turns box style into padding, border and shadow declarations", () => {
    const css = advancedToCss(id, {
      spacing: { top: "8px", right: "16px", bottom: "8px", left: "16px" },
      border: { width: "2px", color: "#000000", style: "dashed", radius: "12px" },
      shadow: { preset: "md" },
    });
    expect(css).toContain("padding-top:8px");
    expect(css).toContain("padding-left:16px");
    expect(css).toContain("border:2px dashed #000000");
    expect(css).toContain("border-radius:12px");
    expect(css).toContain("box-shadow:0 6px 18px rgba(0,0,0,.10)");
  });

  it("maps width presets to max-widths", () => {
    expect(advancedToCss(id, { size: { width: "narrow" } })).toContain(
      "max-width:760px"
    );
    expect(advancedToCss(id, { size: { width: "full" } })).toContain(
      "max-width:none"
    );
  });

  it("wraps responsive overrides in a min-width media query", () => {
    const css = advancedToCss(id, {
      spacing: { top: "48px" },
      overrides: { phone: { spacing: { top: "12px" } } },
    });
    expect(css).toContain("@media (min-width:641px){.site-root .rk-style-" + id + "{padding-top:12px}}");
  });

  it("hides a block per breakpoint", () => {
    const css = advancedToCss(id, { visibility: { hidePhone: true } });
    expect(css).toContain("@media (min-width:641px)");
    expect(css).toContain("display:none!important");
  });

  it("never emits a raw prop string (no injection surface)", () => {
    // Only schema-valid values can get here; assert the generator adds no bare selectors.
    const css = advancedToCss(id, {
      background: { color: "#ff0000" },
      typography: { color: "#00ff00" },
    });
    expect(css).not.toContain("{#");
    expect(css.match(/{/g)?.length).toBe(css.match(/}/g)?.length);
  });
});

describe("layoutStyles", () => {
  it("returns nothing when no block is styled", () => {
    expect(layoutStyles([{ id: "a" }, { id: "b" }])).toBe("");
  });

  it("joins the CSS for every styled block only", () => {
    const blocks = [
      { id: "a", advanced: { spacing: { top: "8px" } } as AdvancedStyle },
      { id: "b" },
      { id: "c", advanced: { border: { radius: "8px" } } as AdvancedStyle },
    ];
    const css = layoutStyles(blocks);
    expect(css).toContain(".rk-style-a");
    expect(css).toContain(".rk-style-c");
    expect(css).not.toContain(".rk-style-b");
  });
});
