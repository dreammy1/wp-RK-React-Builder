import { z } from "zod";
import { text } from "@/lib/schema/primitives";

/** Mirrors rk_builder_dyn_*_re() in includes/validation.php. */
export const SOURCE_RE =
  /^(title|excerpt|content|date|modified|author|terms:[a-z][a-z0-9_-]{0,31}|field:[a-z][a-z0-9_]{0,31})$/;
export const IMAGE_SOURCE_RE = /^(featured|field:[a-z][a-z0-9_]{0,31})$/;
export const FIELD_SOURCE_RE = /^(field:[a-z][a-z0-9_]{0,31})?$/;
export const POST_TYPE_RE = /^(current|[a-z][a-z0-9_]{0,19})$/;

const ratio = z.enum(["landscape", "wide", "square", "portrait", "auto"]);
const gap = z.enum(["sm", "md", "lg"]);

export const dynfieldProps = z.strictObject({
  source: z.string().regex(SOURCE_RE),
  tag: z.enum(["h1", "h2", "h3", "h4", "p", "div", "span"]),
  style: z.enum(["plain", "eyebrow", "lead", "badge"]),
  align: z.enum(["left", "center", "right"]),
  label: text(0, 60),
  prefix: text(0, 30),
  suffix: text(0, 30),
  link: z.boolean(),
  fallback: text(0, 120),
});
export type DynfieldProps = z.infer<typeof dynfieldProps>;
export const dynfieldDefaults: DynfieldProps = {
  source: "title",
  tag: "p",
  style: "plain",
  align: "left",
  label: "",
  prefix: "",
  suffix: "",
  link: false,
  fallback: "",
};

export const dynimageProps = z.strictObject({
  source: z.string().regex(IMAGE_SOURCE_RE),
  ratio,
  link: z.boolean(),
  fallback: z.enum(["hide", "placeholder"]),
});
export type DynimageProps = z.infer<typeof dynimageProps>;
export const dynimageDefaults: DynimageProps = {
  source: "featured",
  ratio: "landscape",
  link: false,
  fallback: "hide",
};

export const dyngalleryProps = z.strictObject({
  source: z.string().regex(FIELD_SOURCE_RE),
  cols: z.number().int().min(1).max(6),
  ratio,
  gap,
  limit: z.number().int().min(0).max(60),
});
export type DyngalleryProps = z.infer<typeof dyngalleryProps>;
export const dyngalleryDefaults: DyngalleryProps = {
  source: "",
  cols: 3,
  ratio: "square",
  gap: "md",
  limit: 0,
};

export const dynrepeaterProps = z.strictObject({
  source: z.string().regex(FIELD_SOURCE_RE),
  layout: z.enum(["list", "table", "cards"]),
  cols: z.number().int().min(1).max(4),
  heading: text(0, 120),
});
export type DynrepeaterProps = z.infer<typeof dynrepeaterProps>;
export const dynrepeaterDefaults: DynrepeaterProps = {
  source: "",
  layout: "list",
  cols: 2,
  heading: "",
};

export const dyninfoProps = z.strictObject({
  heading: text(0, 120),
  /** Comma-separated sources: `field:price,terms:category,date`. */
  sources: text(0, 600),
  labels: z.boolean(),
  layout: z.enum(["rows", "grid"]),
});
export type DyninfoProps = z.infer<typeof dyninfoProps>;
export const dyninfoDefaults: DyninfoProps = {
  heading: "",
  sources: "",
  labels: true,
  layout: "rows",
};

export const loopgridProps = z.strictObject({
  eyebrow: text(0, 80),
  heading: text(0, 200),
  intro: text(0, 300),
  /** `current` = the type of the entry or archive the template is drawing. */
  postType: z.string().regex(POST_TYPE_RE),
  taxonomy: z.string().regex(/^[a-z0-9_-]{0,32}$/),
  term: z.string().regex(/^[a-z0-9-]{0,60}$/),
  limit: z.number().int().min(1).max(48),
  orderBy: z.enum(["date", "title", "menu_order", "modified", "rand"]),
  order: z.enum(["asc", "desc"]),
  cols: z.number().int().min(1).max(4),
  mobileCols: z.number().int().min(1).max(2),
  gap,
  /** A published "loop" template that draws each card; 0 = the built-in card. */
  templateId: z.number().int().min(0).max(2147483647),
  equalHeight: z.boolean(),
  filters: z.boolean(),
  search: z.boolean(),
  pagination: z.boolean(),
  /** Entries that share a term with the one being shown (on a single-entry template). */
  related: z.boolean(),
  emptyText: text(0, 160),
  tone: z.enum(["light", "muted"]),
});
export type LoopgridProps = z.infer<typeof loopgridProps>;
export const loopgridDefaults: LoopgridProps = {
  eyebrow: "",
  heading: "",
  intro: "",
  postType: "current",
  taxonomy: "",
  term: "",
  limit: 9,
  orderBy: "date",
  order: "desc",
  cols: 3,
  mobileCols: 1,
  gap: "md",
  templateId: 0,
  equalHeight: true,
  filters: false,
  search: false,
  pagination: true,
  related: false,
  emptyText: "Nothing here yet.",
  tone: "light",
};
