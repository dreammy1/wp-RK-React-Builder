import type { FieldDef } from "../fields";

export const reviewsFields: FieldDef[] = [
  { kind: "text", key: "eyebrow", label: "Eyebrow label", maxLength: 80 },
  { kind: "text", key: "heading", label: "Heading", maxLength: 200 },
  { kind: "text", key: "intro", label: "Intro", maxLength: 300 },
  {
    kind: "number",
    key: "limit",
    label: "Reviews to show",
    min: 1,
    max: 12,
    help: "Add or sync reviews in Dashboard > Reviews",
  },
  {
    kind: "number",
    key: "minRating",
    label: "Lowest rating to show",
    min: 1,
    max: 5,
  },
  { kind: "number", key: "cols", label: "Columns", min: 2, max: 3 },
  {
    kind: "checkbox",
    key: "showSummary",
    label: "Show stars and review count",
  },
  { kind: "checkbox", key: "showLinks", label: "Show Google review links" },
  {
    kind: "select",
    key: "tone",
    label: "Background",
    options: [
      { value: "light", label: "Light" },
      { value: "muted", label: "Muted gray" },
    ],
  },
];
