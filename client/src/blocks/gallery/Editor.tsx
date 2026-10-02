import type { FieldDef } from "../fields";

export const galleryFields: FieldDef[] = [
  {
    kind: "textarea",
    key: "items",
    label: "Photos",
    maxLength: 12000,
    help: "One per line: image URL|Category|Description (up to 40)",
  },
  { kind: "checkbox", key: "filters", label: "Show category buttons" },
];
