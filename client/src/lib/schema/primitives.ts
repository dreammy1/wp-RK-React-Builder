import { z } from "zod";

/** Hard limits shared by the editor, the public renderer and (mirrored in) the WordPress plugin. */
export const LIMITS = {
  maxBlocks: 100,
  maxPayloadBytes: 256 * 1024,
  maxUrl: 500,
  maxId: 64,
  schemaVersion: 1,
} as const;

export const BLOCK_TYPES = [
  "hero",
  "heading",
  "text",
  "image",
  "cta",
  "services",
  "portfolio",
  "spacer",
  "divider",
  "testimonial",
  "contact",
  "navbar",
  "coverhero",
  "sitefooter",
  "section",
  "split",
  "contactband",
  "panel",
  "values",
  "catalog",
  "detail",
  "gallery",
  "calculator",
  "brandstrip",
  "reviews",
  "visualizer",
  "dynfield",
  "dynimage",
  "dyngallery",
  "dynrepeater",
  "dyninfo",
  "loopgrid",
  "reusable",
] as const;
export type BlockType = (typeof BLOCK_TYPES)[number];

// eslint-disable-next-line no-control-regex
const CONTROL_CHARS = /[\u0000-\u001f\u007f\s]/;

/** Link targets: "", site-relative, fragment, http(s), mailto, tel. Never javascript:/data:. */
export function isSafeLink(value: string): boolean {
  if (value === "") return true;
  if (value.length > LIMITS.maxUrl) return false;
  if (CONTROL_CHARS.test(value) || value.includes("\\")) return false;
  if (value.startsWith("//")) return false;
  if (value.startsWith("/") || value.startsWith("#")) return true;
  try {
    const url = new URL(value);
    return ["http:", "https:", "mailto:", "tel:"].includes(url.protocol);
  } catch {
    return false;
  }
}

/** Image sources: site-relative or http(s) only. */
export function isSafeImageUrl(value: string): boolean {
  if (value.length === 0 || value.length > LIMITS.maxUrl) return false;
  if (CONTROL_CHARS.test(value) || value.includes("\\")) return false;
  if (value.startsWith("//")) return false;
  if (value.startsWith("/")) return true;
  try {
    const url = new URL(value);
    return url.protocol === "http:" || url.protocol === "https:";
  } catch {
    return false;
  }
}

export const linkUrl = z
  .string()
  .max(LIMITS.maxUrl)
  .refine(isSafeLink, "Unsafe or malformed link URL");

export const imageUrl = z
  .string()
  .max(LIMITS.maxUrl)
  .refine(isSafeImageUrl, "Unsafe or malformed image URL");

export const hexColor = z
  .string()
  .regex(/^#[0-9a-fA-F]{6}$/, "Expected a #RRGGBB color");

export const blockId = z
  .string()
  .regex(/^[a-z0-9][a-z0-9_-]{0,63}$/, "Invalid block id");

export const slug = z.string().regex(/^[a-z0-9-]{0,60}$/, "Invalid slug");

export const text = (min: number, max: number) => z.string().min(min).max(max);
