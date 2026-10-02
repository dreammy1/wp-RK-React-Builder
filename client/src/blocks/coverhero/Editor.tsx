import type { FieldDef } from "../fields";

export const coverheroFields: FieldDef[] = [
  { kind: "textarea", key: "crumb", label: "Breadcrumb", maxLength: 400, help: "After Home, one per line: Label|/path" },
  { kind: "text", key: "eyebrow", label: "Eyebrow label", maxLength: 80 },
  { kind: "text", key: "heading", label: "Headline", maxLength: 160 },
  { kind: "textarea", key: "sub", label: "Supporting copy", maxLength: 400 },
  { kind: "text", key: "cta", label: "Primary button", maxLength: 60 },
  { kind: "url", key: "ctaHref", label: "Primary link", maxLength: 500 },
  { kind: "text", key: "cta2", label: "Secondary button", maxLength: 60 },
  { kind: "url", key: "cta2Href", label: "Secondary link", maxLength: 500 },
  { kind: "media", key: "bgUrl", idKey: "bgMediaId", label: "Background image", optional: true },
  {
    kind: "select",
    key: "size",
    label: "Size",
    options: [
      { value: "screen", label: "Full screen" },
      { value: "page", label: "Page header" },
    ],
  },
];
