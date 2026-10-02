import type { FieldDef } from "../fields";

export const catalogFields: FieldDef[] = [
  { kind: "text", key: "eyebrow", label: "Eyebrow label", maxLength: 80 },
  { kind: "text", key: "heading", label: "Heading", maxLength: 200 },
  { kind: "textarea", key: "intro", label: "Intro copy", maxLength: 1000 },
  { kind: "catalogCards", key: "items", label: "Cards" },
  {
    kind: "checkbox",
    key: "filters",
    label: "Show filter buttons above the cards",
  },
  {
    kind: "checkbox",
    key: "joined",
    label: "Join the cards into one bordered grid",
  },
  { kind: "number", key: "cols", label: "Columns", min: 2, max: 4 },
  { kind: "checkbox", key: "numbered", label: "Number the photos" },
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
