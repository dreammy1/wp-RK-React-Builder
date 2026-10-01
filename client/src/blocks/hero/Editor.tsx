import type { FieldDef } from "../fields";

export const heroFields: FieldDef[] = [
  { kind: "text", key: "heading", label: "Headline", maxLength: 160 },
  { kind: "textarea", key: "sub", label: "Supporting copy", maxLength: 400 },
  { kind: "text", key: "cta", label: "Button label", maxLength: 60 },
  {
    kind: "url",
    key: "ctaHref",
    label: "Button link",
    maxLength: 500,
    help: "Relative path, https://, mailto: or tel:",
  },
];
