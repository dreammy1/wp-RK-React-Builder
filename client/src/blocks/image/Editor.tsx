import type { FieldDef } from "../fields";

export const imageFields: FieldDef[] = [
  { kind: "media", key: "url", label: "Image" },
  {
    kind: "text",
    key: "alt",
    label: "Alt text",
    maxLength: 300,
    help: "Describe the image for people using screen readers.",
  },
  {
    kind: "checkbox",
    key: "decorative",
    label: "Decorative image (no alt text needed)",
  },
];
