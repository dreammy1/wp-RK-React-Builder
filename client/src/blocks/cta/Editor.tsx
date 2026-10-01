import type { FieldDef } from "../fields";

export const ctaFields: FieldDef[] = [
  { kind: "text", key: "heading", label: "Heading", maxLength: 160 },
  { kind: "text", key: "cta", label: "Button label", maxLength: 60 },
  { kind: "url", key: "ctaHref", label: "Button link", maxLength: 500 },
];
