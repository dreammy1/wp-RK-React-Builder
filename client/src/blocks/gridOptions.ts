import { z } from "zod";
import { linkUrl, text } from "@/lib/schema/primitives";
import type { FieldDef } from "./fields";

/** Display options shared by the Services and Portfolio grids. All optional, so older pages keep their look. */
export const gridOptionShape = {
  eyebrow: text(0, 80).optional(),
  intro: text(0, 300).optional(),
  tone: z.enum(["light", "muted"]).optional(),
  /** Cards in a row stretch to the same height and the button sits at the bottom. */
  equalHeight: z.boolean().optional(),
  imageRatio: z
    .enum(["auto", "landscape", "wide", "square", "portrait"])
    .optional(),
  showImage: z.boolean().optional(),
  showExcerpt: z.boolean().optional(),
  /** Cut the excerpt after this many lines (0 = show it all). */
  excerptLines: z.number().int().min(0).max(8).optional(),
  showCategories: z.boolean().optional(),
  cardLink: z.enum(["none", "title", "button"]).optional(),
  buttonLabel: text(0, 40).optional(),
  cardStyle: z.enum(["bordered", "soft", "plain"]).optional(),
  gap: z.enum(["sm", "md", "lg"]).optional(),
  mobileCols: z.number().int().min(1).max(2).optional(),
  viewAllLabel: text(0, 60).optional(),
  viewAllHref: linkUrl.optional(),
};

export type GridOptions = {
  [K in keyof typeof gridOptionShape]?: z.infer<
    (typeof gridOptionShape)[K]
  > extends infer T
    ? Exclude<T, undefined>
    : never;
};

/** Section classes. Mirrored by rk_builder_grid_classes(). */
export function gridClass(o: GridOptions): string {
  let c = "site-grid";
  if (o.tone === "muted") c += " tone-muted";
  if (o.equalHeight) c += " eq";
  if (o.imageRatio) c += ` ratio-${o.imageRatio}`;
  if (o.cardStyle) c += ` style-${o.cardStyle}`;
  if (o.gap) c += ` gap-${o.gap}`;
  if (o.mobileCols === 2) c += " m2";
  return c;
}

const when =
  (key: string, ok: (v: unknown) => boolean) =>
  (values: Record<string, unknown>) =>
    ok(values[key]);

/** Inspector controls for the display options (the block's own fields come first). */
export const gridOptionFields: FieldDef[] = [
  { kind: "group", label: "Heading" },
  {
    kind: "text",
    key: "eyebrow",
    label: "Small label above the title",
    maxLength: 80,
  },
  { kind: "textarea", key: "intro", label: "Intro text", maxLength: 300 },
  { kind: "group", label: "Cards" },
  {
    kind: "checkbox",
    key: "equalHeight",
    label: "Equal height cards",
    help: "Every card in a row is as tall as the tallest, and its button lines up at the bottom.",
  },
  { kind: "checkbox", key: "showImage", label: "Show the image" },
  {
    kind: "select",
    key: "imageRatio",
    label: "Image shape",
    showIf: v => v.showImage !== false,
    options: [
      { value: "landscape", label: "Landscape (16:10)" },
      { value: "wide", label: "Wide (16:9)" },
      { value: "square", label: "Square (1:1)" },
      { value: "portrait", label: "Portrait (4:5)" },
      { value: "auto", label: "Natural (as uploaded)" },
    ],
  },
  { kind: "checkbox", key: "showCategories", label: "Show categories as tags" },
  { kind: "checkbox", key: "showExcerpt", label: "Show the description" },
  {
    kind: "select",
    key: "excerptLines",
    label: "Description length",
    numeric: true,
    showIf: v => v.showExcerpt !== false,
    options: [
      { value: 0, label: "Full text" },
      { value: 2, label: "2 lines" },
      { value: 3, label: "3 lines" },
      { value: 4, label: "4 lines" },
      { value: 6, label: "6 lines" },
    ],
  },
  {
    kind: "select",
    key: "cardLink",
    label: "Link each card to its page",
    options: [
      { value: "none", label: "No link" },
      { value: "title", label: "Make the title a link" },
      { value: "button", label: "Add a button" },
    ],
  },
  {
    kind: "text",
    key: "buttonLabel",
    label: "Button label",
    maxLength: 40,
    help: "Leave empty for “Learn more”.",
    showIf: when("cardLink", v => v === "button"),
  },
  { kind: "group", label: "Look" },
  {
    kind: "select",
    key: "cardStyle",
    label: "Card style",
    options: [
      { value: "bordered", label: "Bordered" },
      { value: "soft", label: "Soft shadow" },
      { value: "plain", label: "Plain (no box)" },
    ],
  },
  {
    kind: "select",
    key: "gap",
    label: "Space between cards",
    options: [
      { value: "sm", label: "Tight" },
      { value: "md", label: "Normal" },
      { value: "lg", label: "Roomy" },
    ],
  },
  {
    kind: "select",
    key: "mobileCols",
    label: "Columns on phones",
    numeric: true,
    options: [
      { value: 1, label: "1 column" },
      { value: 2, label: "2 columns" },
    ],
  },
  {
    kind: "select",
    key: "tone",
    label: "Background",
    options: [
      { value: "light", label: "Light" },
      { value: "muted", label: "Muted gray" },
    ],
  },
  { kind: "group", label: "After the cards" },
  {
    kind: "text",
    key: "viewAllLabel",
    label: "“View all” button label",
    maxLength: 60,
  },
  {
    kind: "url",
    key: "viewAllHref",
    label: "“View all” link",
    maxLength: 500,
    help: "Both the label and the link are needed to show the button.",
  },
];

/** What a newly added grid starts with (existing pages without these keep their original look). */
export const gridOptionDefaults: Required<GridOptions> = {
  eyebrow: "",
  intro: "",
  tone: "light",
  equalHeight: true,
  imageRatio: "landscape",
  showImage: true,
  showExcerpt: true,
  excerptLines: 3,
  showCategories: false,
  cardLink: "button",
  buttonLabel: "Learn more",
  cardStyle: "bordered",
  gap: "md",
  mobileCols: 1,
  viewAllLabel: "",
  viewAllHref: "",
};
