import { isSafeImageUrl, isSafeLink } from "@/lib/schema/primitives";

export type NavLink = { label: string; href: string };

/** One link per line, "Label|/path". Lines with no label or an unsafe target are dropped. Mirrored by rk_builder_parse_links(). */
export function parseLinks(source: string, max = 12): NavLink[] {
  const out: NavLink[] = [];
  for (const raw of source.split("\n")) {
    const line = raw.trim();
    const cut = line.indexOf("|");
    if (cut < 1) continue;
    const label = line.slice(0, cut).trim();
    const href = line.slice(cut + 1).trim();
    if (label === "" || href === "" || !isSafeLink(href)) continue;
    out.push({ label, href });
    if (out.length >= max) break;
  }
  return out;
}

export type Fact = { value: string; label: string };

/** One fact per line, "Value|Label" (e.g. "75 mi|Service radius"). Mirrored by rk_builder_parse_facts(). */
export function parseFacts(source: string, max = 6): Fact[] {
  const out: Fact[] = [];
  for (const raw of source.split("\n")) {
    const line = raw.trim();
    const cut = line.indexOf("|");
    if (cut < 1) continue;
    const value = line.slice(0, cut).trim();
    const label = line.slice(cut + 1).trim();
    if (value === "" || label === "") continue;
    out.push({ value, label });
    if (out.length >= max) break;
  }
  return out;
}

/** Plain text → paragraphs on blank lines (same rule as the text block). */
export function paragraphs(source: string): string[] {
  return source.split(/\n{2,}/).filter(p => p.trim() !== "");
}

/**
 * Pipe-separated rows: one per line, exactly `n` trimmed fields (missing ones are empty, the last field keeps any
 * extra pipes). Blank lines are skipped. Mirrored by rk_builder_parse_rows().
 */
export function parseRows(source: string, n: number, max = 12): string[][] {
  const out: string[][] = [];
  for (const raw of source.split("\n")) {
    const line = raw.trim();
    if (line === "") continue;
    const parts = line.split("|");
    const head = parts.slice(0, n - 1).map(s => s.trim());
    const tail = parts
      .slice(n - 1)
      .join("|")
      .trim();
    const row = [...head, tail];
    while (row.length < n) row.push("");
    out.push(row);
    if (out.length >= max) break;
  }
  return out;
}

/** "a; b; c" → ["a","b","c"] (empty items dropped). */
export function semi(source: string, max = 12): string[] {
  return source
    .split(";")
    .map(s => s.trim())
    .filter(s => s !== "")
    .slice(0, max);
}

/** An image source that is safe to print, else "". */
export const safeImg = (url: string): string =>
  url !== "" && isSafeImageUrl(url) ? url : "";
/** A link target that is safe to print, else "". */
export const safeHref = (url: string): string =>
  url !== "" && isSafeLink(url) ? url : "";
export const HEX = /^#[0-9a-fA-F]{6}$/;

/** Filter tags in order of first appearance (without "All"). */
export const uniqueTags = (tags: string[]): string[] => [
  ...new Set(tags.filter(t => t !== "")),
];

/** The text after the last " · " in a card blurb, else "". Mirrored by rk_builder_blurb_tag(). */
export function blurbTag(blurb: string): string {
  const cut = blurb.lastIndexOf(" · ");
  return cut < 0 ? "" : blurb.slice(cut + 3).trim();
}
