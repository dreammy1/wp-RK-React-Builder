import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it } from "vitest";
import {
  ContentContext,
  contentKey,
  staticContentSource,
  type ContentQuery,
  type ContentResult,
} from "@/render/content";
import { GalleryView } from "./View";
import { galleryDefaults, slugLabel, type GalleryProps } from "./schema";

const item = (
  id: number,
  title: string,
  cats: string[],
  url: string | null
) => ({
  id,
  title,
  excerpt: "",
  link: `https://x.test/${id}`,
  categories: cats,
  image: url ? { url, width: 800, height: 600, alt: title } : null,
});

const render = (props: GalleryProps, q: ContentQuery, r: ContentResult) =>
  renderToStaticMarkup(
    <ContentContext.Provider
      value={staticContentSource(new Map([[contentKey(q), r]]))}
    >
      <GalleryView props={props} mode="public" blockId="g1" />
    </ContentContext.Provider>
  );

describe("automatic gallery", () => {
  it("slugLabel turns a category slug into button text", () => {
    expect(slugLabel("hardwood-floors")).toBe("Hardwood Floors");
    expect(slugLabel("a_b  c")).toBe("A B C");
    expect(slugLabel("")).toBe("");
  });

  it("projects: featured images become photos, title is the caption, first category the filter; entries without a picture are skipped", () => {
    const q: ContentQuery = {
      source: "portfolio",
      limit: 12,
      category: "",
      orderBy: "date",
      order: "desc",
    };
    const html = render(
      { ...galleryDefaults, source: "portfolio", filters: true },
      q,
      {
        status: "ready",
        total: 3,
        items: [
          item(
            1,
            "Oak lounge",
            ["hardwood-floors"],
            "https://cms.example.com/a.jpg"
          ),
          item(2, "No picture", ["refinishing"], null),
          item(
            3,
            "Stair job",
            ["refinishing"],
            "https://cms.example.com/b.jpg"
          ),
        ],
      }
    );
    expect(html.match(/<figure/g)).toHaveLength(2);
    expect(html).toContain("Hardwood Floors · Oak lounge");
    expect(html).toContain('data-filter="Refinishing"');
    expect(html).not.toContain("No picture");
  });

  it("media library: no category, alt text as caption, so no filter bar", () => {
    const q: ContentQuery = {
      source: "media",
      limit: 5,
      category: "oak",
      orderBy: "date",
      order: "desc",
    };
    const html = render(
      {
        ...galleryDefaults,
        source: "media",
        limit: 5,
        filter: "oak",
        filters: true,
      },
      q,
      {
        status: "ready",
        total: 1,
        items: [item(9, "Oak close-up", [], "https://cms.example.com/c.jpg")],
      }
    );
    expect(html).toContain("Oak close-up");
    expect(html).not.toContain("pf-filters");
  });

  it("while loading, or on an error, nothing is invented", () => {
    const q: ContentQuery = {
      source: "media",
      limit: 12,
      category: "",
      orderBy: "date",
      order: "desc",
    };
    const html = render({ ...galleryDefaults, source: "media" }, q, {
      status: "loading",
      items: [],
      total: 0,
    });
    expect(html).not.toContain("<figure");
  });
});
