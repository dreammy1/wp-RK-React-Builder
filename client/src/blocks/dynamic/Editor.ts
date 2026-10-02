import type { FieldDef } from "../fields";

const ratios = [
  { value: "landscape", label: "Landscape (16:10)" },
  { value: "wide", label: "Wide (16:9)" },
  { value: "square", label: "Square (1:1)" },
  { value: "portrait", label: "Portrait (4:5)" },
  { value: "auto", label: "Natural (as uploaded)" },
];
const gaps = [
  { value: "sm", label: "Tight" },
  { value: "md", label: "Normal" },
  { value: "lg", label: "Roomy" },
];
const isField = (v: Record<string, unknown>) =>
  String(v.source ?? "").startsWith("field:");

export const dynfieldFields: FieldDef[] = [
  { kind: "group", label: "Content" },
  {
    kind: "dynSource",
    key: "source",
    label: "Show",
    accept: "scalar",
    help: "Reads from the entry the template is drawing",
  },
  {
    kind: "text",
    key: "label",
    label: "Label before the value",
    maxLength: 60,
  },
  {
    kind: "text",
    key: "fallback",
    label: "Text when empty",
    maxLength: 120,
    help: "Leave empty to hide the block when there is no value",
  },
  {
    kind: "text",
    key: "prefix",
    label: "Before a number (for example $)",
    maxLength: 30,
    showIf: isField,
  },
  {
    kind: "text",
    key: "suffix",
    label: "After a number (for example sq ft)",
    maxLength: 30,
    showIf: isField,
  },
  { kind: "group", label: "Look" },
  {
    kind: "select",
    key: "tag",
    label: "Text style",
    options: [
      { value: "h1", label: "Page heading (H1)" },
      { value: "h2", label: "Heading (H2)" },
      { value: "h3", label: "Heading (H3)" },
      { value: "h4", label: "Heading (H4)" },
      { value: "p", label: "Paragraph" },
      { value: "div", label: "Block" },
      { value: "span", label: "Inline" },
    ],
  },
  {
    kind: "select",
    key: "style",
    label: "Look",
    options: [
      { value: "plain", label: "Plain" },
      { value: "eyebrow", label: "Small caps label" },
      { value: "lead", label: "Large intro text" },
      { value: "badge", label: "Badge" },
    ],
  },
  {
    kind: "select",
    key: "align",
    label: "Alignment",
    options: [
      { value: "left", label: "Left" },
      { value: "center", label: "Centre" },
      { value: "right", label: "Right" },
    ],
  },
  {
    kind: "checkbox",
    key: "link",
    label: "Link to the entry",
    help: "Useful for titles inside cards",
  },
];

export const dynimageFields: FieldDef[] = [
  {
    kind: "dynSource",
    key: "source",
    label: "Image",
    accept: "image",
  },
  { kind: "select", key: "ratio", label: "Image shape", options: ratios },
  { kind: "checkbox", key: "link", label: "Link to the entry" },
  {
    kind: "select",
    key: "fallback",
    label: "When there is no image",
    options: [
      { value: "hide", label: "Hide the block" },
      { value: "placeholder", label: "Show a grey placeholder" },
    ],
  },
];

export const dyngalleryFields: FieldDef[] = [
  {
    kind: "dynSource",
    key: "source",
    label: "Gallery field",
    accept: "gallery",
    help: "Add a Gallery field to the content type first",
  },
  { kind: "number", key: "cols", label: "Columns", min: 1, max: 6 },
  { kind: "select", key: "ratio", label: "Photo shape", options: ratios },
  { kind: "select", key: "gap", label: "Space between", options: gaps },
  {
    kind: "number",
    key: "limit",
    label: "Photos to show",
    min: 0,
    max: 60,
    help: "0 shows them all",
  },
];

export const dynrepeaterFields: FieldDef[] = [
  {
    kind: "dynSource",
    key: "source",
    label: "Repeater field",
    accept: "repeater",
    help: "Add a Repeater field to the content type first",
  },
  { kind: "text", key: "heading", label: "Heading", maxLength: 120 },
  {
    kind: "select",
    key: "layout",
    label: "Show rows as",
    options: [
      { value: "list", label: "List" },
      { value: "table", label: "Table" },
      { value: "cards", label: "Cards" },
    ],
  },
  {
    kind: "number",
    key: "cols",
    label: "Card columns",
    min: 1,
    max: 4,
    showIf: v => v.layout === "cards",
  },
];

export const dyninfoFields: FieldDef[] = [
  { kind: "text", key: "heading", label: "Heading", maxLength: 120 },
  {
    kind: "dynSources",
    key: "sources",
    label: "Details to list",
    help: "Empty values are skipped automatically",
  },
  { kind: "checkbox", key: "labels", label: "Show the names" },
  {
    kind: "select",
    key: "layout",
    label: "Layout",
    options: [
      { value: "rows", label: "Stacked rows" },
      { value: "grid", label: "Grid of boxes" },
    ],
  },
];

export const loopgridFields: FieldDef[] = [
  { kind: "group", label: "Heading" },
  { kind: "text", key: "eyebrow", label: "Small label", maxLength: 80 },
  { kind: "text", key: "heading", label: "Heading", maxLength: 200 },
  { kind: "text", key: "intro", label: "Intro text", maxLength: 300 },
  { kind: "group", label: "What to list" },
  { kind: "postType", key: "postType", label: "Content type" },
  {
    kind: "checkbox",
    key: "related",
    label: "Only entries related to this one",
    help: "On a single-entry template: other entries sharing a category",
  },
  { kind: "taxonomy", key: "taxonomy", label: "Only from a category group" },
  { kind: "taxonomyTerm", key: "term", label: "Category" },
  { kind: "number", key: "limit", label: "Entries per page", min: 1, max: 48 },
  {
    kind: "select",
    key: "orderBy",
    label: "Sort by",
    options: [
      { value: "date", label: "Newest" },
      { value: "modified", label: "Recently updated" },
      { value: "title", label: "Title" },
      { value: "menu_order", label: "Custom order" },
      { value: "rand", label: "Random" },
    ],
  },
  {
    kind: "select",
    key: "order",
    label: "Direction",
    options: [
      { value: "desc", label: "Descending" },
      { value: "asc", label: "Ascending" },
    ],
  },
  { kind: "group", label: "Cards" },
  {
    kind: "loopTemplate",
    key: "templateId",
    label: "Card design",
    help: "Design cards in Dashboard > Templates > Card",
  },
  { kind: "number", key: "cols", label: "Columns", min: 1, max: 4 },
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
  { kind: "select", key: "gap", label: "Space between cards", options: gaps },
  { kind: "checkbox", key: "equalHeight", label: "Equal height cards" },
  {
    kind: "select",
    key: "tone",
    label: "Background",
    options: [
      { value: "light", label: "Light" },
      { value: "muted", label: "Muted gray" },
    ],
  },
  { kind: "group", label: "For visitors" },
  {
    kind: "checkbox",
    key: "filters",
    label: "Category filter buttons",
  },
  { kind: "checkbox", key: "search", label: "Search box" },
  { kind: "checkbox", key: "pagination", label: "Page numbers" },
  {
    kind: "text",
    key: "emptyText",
    label: "Text when nothing matches",
    maxLength: 160,
  },
];
