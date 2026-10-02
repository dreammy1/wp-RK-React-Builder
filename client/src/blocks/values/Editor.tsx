import type { FieldDef } from "../fields";

export const valuesFields: FieldDef[] = [
  { kind: "text", key: "eyebrow", label: "Eyebrow label", maxLength: 80 },
  { kind: "text", key: "heading", label: "Heading", maxLength: 200 },
  {
    kind: "textarea",
    key: "items",
    label: "Cards",
    maxLength: 3000,
    help: "One per line: Title|Text (up to 8)",
  },
  { kind: "number", key: "cols", label: "Columns", min: 2, max: 4 },
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
