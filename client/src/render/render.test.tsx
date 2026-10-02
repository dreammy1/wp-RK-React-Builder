import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it } from "vitest";
import { registry } from "@/blocks/registry";
import { BLOCK_TYPES } from "@/lib/schema/primitives";
import type { Block } from "@/lib/schema/layout";
import { BlockRenderer } from "./BlockRenderer";
import { ReusableContext, staticReusableSource } from "./reusable";
import {
  ContentContext,
  contentKey,
  staticContentSource,
  type ContentResult,
} from "./content";

const mk = (type: Block["type"], props?: object): Block =>
  ({
    id: `${type}-1`,
    type,
    props: { ...registry[type].defaults, ...props },
  }) as Block;
const html = (
  b: Block,
  results: [Block, ContentResult][] = [],
  mode: "editor" | "public" = "public"
) => {
  const m = new Map(
    results.map(([blk, r]) => [contentKey(blk.props as never), r])
  );
  return renderToStaticMarkup(
    <ContentContext.Provider value={staticContentSource(m)}>
      <BlockRenderer block={b} mode={mode} />
    </ContentContext.Provider>
  );
};

describe("block views (shared by canvas and public site)", () => {
  // Dynamic blocks are drawn by the PHP renderer; React only previews them inside the editor (see blocks/dynamic/View.tsx).
  const DYNAMIC = [
    "dynfield",
    "dynimage",
    "dyngallery",
    "dynrepeater",
    "dyninfo",
    "loopgrid",
  ];
  it.each(DYNAMIC)("%s renders nothing in public mode (PHP-only)", type => {
    expect(html(mk(type as (typeof BLOCK_TYPES)[number]))).toBe("");
  });
  it.each(BLOCK_TYPES.filter(t => t !== "reusable" && !DYNAMIC.includes(t)))(
    "%s renders without editor chrome",
    type => {
      const out = html(mk(type));
      expect(out.length).toBeGreaterThan(0);
      expect(out).not.toMatch(/contenteditable|draggable|data-testid/);
    }
  );
  describe("reusable references", () => {
    const record = {
      id: 7,
      name: "Footer CTA",
      block: {
        type: "cta" as const,
        props: { heading: "Call us", cta: "Go", ctaHref: "/contact" },
      },
    };
    const render = (mode: "editor" | "public", refId = 7) =>
      renderToStaticMarkup(
        <ReusableContext.Provider value={staticReusableSource([record])}>
          <BlockRenderer block={mk("reusable", { refId })} mode={mode} />
        </ReusableContext.Provider>
      );
    it("public output is exactly the referenced block's markup (no wrapper)", () => {
      expect(render("public")).toBe(
        html(
          {
            id: "x",
            type: "cta",
            props: record.block.props,
          } as Block,
          [],
          "public"
        )
      );
    });
    it("the editor marks it as shared; a missing reference is a visible note in the editor and empty publicly", () => {
      expect(render("editor")).toContain("Reusable · Footer CTA");
      expect(render("editor", 99)).toContain("reusable-missing");
      expect(render("public", 99)).toBe("");
    });
  });
  it("escapes text and keeps plain-text paragraphs", () => {
    const out = html(mk("text", { text: "One <b>x</b>\n\nTwo & three" }));
    expect(out).toBe(
      '<section class="site-text"><p>One &lt;b&gt;x&lt;/b&gt;</p><p>Two &amp; three</p></section>'
    );
  });
  it("uses the declared heading level", () => {
    expect(html(mk("heading", { level: 3, text: "Hi" }))).toContain(
      "<h3>Hi</h3>"
    );
  });
  it("gives images dimensions, lazy loading on the public site, and empty alt only when decorative", () => {
    const img = html(
      mk("image", {
        url: "/a.jpg",
        alt: "A",
        width: 10,
        height: 20,
        decorative: false,
      })
    );
    expect(img).toMatch(/alt="A" width="10" height="20" loading="lazy"/);
    expect(
      html(mk("image", { url: "/a.jpg", alt: "A", decorative: true }))
    ).toContain('alt=""');
    expect(
      html(
        mk("image", { url: "/a.jpg", alt: "A", decorative: false }),
        [],
        "editor"
      )
    ).not.toContain("loading=");
  });
  const grid = mk("services", { cols: 2, limit: 2 });
  it("grid: loading, error, empty and ready states", () => {
    expect(
      html(grid, [[grid, { status: "loading", items: [], total: 0 }]])
    ).toContain('aria-busy="true"');
    expect(
      html(grid, [[grid, { status: "error", items: [], total: 0, error: "x" }]])
    ).toContain("temporarily unavailable");
    expect(
      html(
        grid,
        [[grid, { status: "error", items: [], total: 0, error: "boom" }]],
        "editor"
      )
    ).toContain('role="alert"');
    expect(
      html(grid, [[grid, { status: "ready", items: [], total: 0 }]])
    ).toContain("No service");
    const ready = html(grid, [
      [
        grid,
        {
          status: "ready",
          total: 1,
          items: [
            {
              id: 1,
              title: "T",
              excerpt: "E",
              link: "/",
              categories: [],
              image: null,
            },
          ],
        },
      ],
    ]);
    expect(ready).toContain("<h3>T</h3>");
    expect(ready).toContain("repeat(2, minmax(0, 1fr))");
  });
  it("grid keys ignore display-only props (cols/title) so one request serves duplicates", () => {
    expect(
      contentKey(mk("services", { cols: 2, title: "A" }).props as never)
    ).toBe(contentKey(mk("services", { cols: 4, title: "B" }).props as never));
  });
});
