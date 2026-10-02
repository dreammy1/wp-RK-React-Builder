import type { FieldDef } from "../fields";

export const splitFields: FieldDef[] = [
  { kind: "text", key: "eyebrow", label: "Eyebrow label", maxLength: 80 },
  { kind: "text", key: "heading", label: "Heading", maxLength: 200 },
  { kind: "textarea", key: "body", label: "Copy", maxLength: 3000, help: "Blank line between paragraphs" },
  { kind: "textarea", key: "facts", label: "Key facts", maxLength: 600, help: "One per line: Value|Label (up to 6)" },
  { kind: "text", key: "cta", label: "Button label", maxLength: 60 },
  { kind: "url", key: "ctaHref", label: "Button link", maxLength: 500 },
  { kind: "media", key: "imageUrl", idKey: "imageMediaId", label: "Image", optional: true },
  { kind: "text", key: "imageAlt", label: "Image description", maxLength: 300, help: "Leave empty if the image is only decorative" },
  {
    kind: "select",
    key: "side",
    label: "Image side",
    options: [
      { value: "left", label: "Left" },
      { value: "right", label: "Right" },
    ],
  },
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
