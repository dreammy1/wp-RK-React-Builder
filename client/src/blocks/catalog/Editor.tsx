import type { FieldDef } from "../fields";

export const catalogFields: FieldDef[] = [
  { kind: "text", key: "eyebrow", label: "Eyebrow label", maxLength: 80 },
  { kind: "text", key: "heading", label: "Heading", maxLength: 200 },
  { kind: "textarea", key: "intro", label: "Intro copy", maxLength: 1000 },
  {
    kind: "textarea",
    key: "items",
    label: "Cards",
    maxLength: 12000,
    help: "One per line: image|eyebrow|title|blurb|Key: Value; Key: Value|bullet; bullet|/link (image can be a #RRGGBB swatch)",
  },
  { kind: "checkbox", key: "filters", label: "Show filter buttons" },
  {
    kind: "checkbox",
    key: "joined",
    label: "Join the cards into one bordered grid",
  },
  {
    kind: "textarea",
    key: "modals",
    label: "Pop-ups",
    maxLength: 8000,
    help: "One per line, matched to the cards in order: image|Title|Intro|item; item; item",
  },
  {
    kind: "text",
    key: "modalLabel",
    label: "Pop-up button label",
    maxLength: 40,
  },
  {
    kind: "text",
    key: "modalCta",
    label: "Pop-up link",
    maxLength: 120,
    help: "Label|/link",
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
