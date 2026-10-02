import type { FieldDef } from "../fields";

export const calculatorFields: FieldDef[] = [
  { kind: "text", key: "heading", label: "Form heading", maxLength: 120 },
  {
    kind: "textarea",
    key: "types",
    label: "Project types",
    maxLength: 1200,
    help: "One per line: Label|Rate per unit|Unit label. The first line is the default.",
  },
  {
    kind: "number",
    key: "amount",
    label: "Starting quantity",
    min: 1,
    max: 100000,
  },
  { kind: "text", key: "resultLabel", label: "Result label", maxLength: 60 },
  {
    kind: "textarea",
    key: "note",
    label: "Note under the result",
    maxLength: 400,
  },
  { kind: "text", key: "ctaLabel", label: "Button label", maxLength: 60 },
  { kind: "url", key: "ctaHref", label: "Button link", maxLength: 300 },
];
