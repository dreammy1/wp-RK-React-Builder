/**
 * Cross-language guarantee: the PHP public renderer and the React views (used by the editor canvas and the
 * Node SSR) must emit the same HTML for the same document, so one stylesheet styles both.
 * The PHP side adds only `rk-block rk-block-<type>` classes and `data-rk-block` attributes.
 */
import { execFileSync } from "node:child_process";
import { readFileSync, readdirSync } from "node:fs";
import path from "node:path";
import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it } from "vitest";
import { LayoutRenderer } from "../client/src/render/BlockRenderer";
import {
  ContentContext,
  contentKey,
  type ContentResult,
} from "../client/src/render/content";
import type { ContentItem } from "../client/src/lib/schema/api";
import { parseLayout } from "../client/src/lib/schema/migrate";

const root = path.resolve(import.meta.dirname, "..");
const cli = path.join(root, "wp-plugin/tests/render-cli.php");

/** Must match the deterministic grid content that render-cli.php stubs. */
const GRID_ITEMS: ContentItem[] = [
  {
    id: 1,
    title: "Alpha <service>",
    excerpt: "First & best",
    link: "https://cms.example.com/a",
    categories: ["energy"],
    image: {
      url: "https://cms.example.com/a.jpg",
      width: 800,
      height: 500,
      alt: "Alpha alt",
    },
  },
  {
    id: 2,
    title: "Beta",
    excerpt: "",
    link: "https://cms.example.com/b",
    categories: [],
    image: null,
  },
];

// React 19 hoists a preload hint for plain <img>; PHP expresses priority via fetchpriority only.
const normalise = (html: string) =>
  html
    .replace(/<link rel="preload" as="image"[^>]*\/>/g, "")
    .replace(/ data-rk-block="[a-z]+"/g, "")
    .replace(/ rk-block rk-block-[a-z]+/g, "")
    .replace(/class="rk-block rk-block-[a-z]+ /g, 'class="')
    .replace(/\s+/g, " ")
    .trim();

const fixtures = readdirSync(path.join(root, "contracts/valid")).filter(f =>
  f.startsWith("layout-")
);

describe("PHP renderer ↔ React views parity", () => {
  for (const file of fixtures) {
    it(`renders ${file} identically (modulo rk- hooks)`, () => {
      const doc = JSON.parse(
        readFileSync(path.join(root, "contracts/valid", file), "utf8")
      ).document;
      const parsed = parseLayout(doc);
      if (!parsed.ok) throw new Error("fixture invalid");
      const results = new Map<string, ContentResult>();
      for (const b of parsed.value.blocks) {
        if (b.type === "services" || b.type === "portfolio") {
          const items = GRID_ITEMS.filter(
            i => !b.props.category || i.categories.includes(b.props.category)
          ).slice(0, b.props.limit);
          results.set(contentKey(b.props), {
            status: "ready",
            items,
            total: items.length,
          });
        }
      }
      const react = renderToStaticMarkup(
        <ContentContext.Provider
          value={{
            get: q =>
              results.get(contentKey(q)) ?? {
                status: "ready",
                items: [],
                total: 0,
              },
          }}
        >
          <LayoutRenderer layout={parsed.value} mode="public" />
        </ContentContext.Provider>
      );
      const php = execFileSync(
        "php",
        [cli, path.join(root, "contracts/valid", file)],
        { encoding: "utf8" }
      );
      expect(normalise(php)).toBe(normalise(react));
    });
  }
});
