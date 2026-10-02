import type { FieldDef } from "../fields";

export const panelFields: FieldDef[] = [
  {
    kind: "select",
    key: "mode",
    label: "Layout",
    options: [
      { value: "rows", label: "Copy + divider rows" },
      { value: "intro", label: "Heading + copy and checks" },
    ],
  },
  { kind: "text", key: "eyebrow", label: "Eyebrow label", maxLength: 80 },
  { kind: "text", key: "heading", label: "Heading", maxLength: 200 },
  { kind: "textarea", key: "body", label: "Copy", maxLength: 3000 },
  {
    kind: "textarea",
    key: "checks",
    label: "Check-marked points",
    maxLength: 1500,
    help: "One per line",
  },
  {
    kind: "textarea",
    key: "actions",
    label: "Buttons and links",
    maxLength: 1500,
    help: "One per line: Label|/path",
  },
  {
    kind: "textarea",
    key: "items",
    label: "Rows",
    maxLength: 4000,
    help: "One per line: Title|Text or Title|Text|/link",
  },
  {
    kind: "select",
    key: "itemStyle",
    label: "Row style",
    options: [
      { value: "feature", label: "Big title, small text" },
      { value: "contact", label: "Small label, big value" },
    ],
  },
  { kind: "checkbox", key: "flip", label: "Rows on the left" },
  { kind: "text", key: "kicker", label: "Label above rows", maxLength: 80 },
  { kind: "checkbox", key: "box", label: "Gray box around the copy" },
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
