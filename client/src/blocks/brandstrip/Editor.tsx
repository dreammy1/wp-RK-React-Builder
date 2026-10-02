import type { FieldDef } from "../fields";

export const brandstripFields: FieldDef[] = [
  { kind: "text", key: "label", label: "Label", maxLength: 120 },
  {
    kind: "textarea",
    key: "items",
    label: "Names",
    maxLength: 600,
    help: "One per line (up to 12)",
  },
];
