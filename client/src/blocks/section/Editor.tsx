import type { FieldDef } from "../fields";

export const sectionFields: FieldDef[] = [
  { kind: "text", key: "eyebrow", label: "Eyebrow label", maxLength: 80 },
  { kind: "text", key: "heading", label: "Heading", maxLength: 200 },
  {
    kind: "textarea",
    key: "body",
    label: "Copy",
    maxLength: 3000,
    help: "Blank line between paragraphs",
  },
  { kind: "text", key: "linkLabel", label: "Link label", maxLength: 60 },
  { kind: "url", key: "linkHref", label: "Link target", maxLength: 500 },
  { kind: "text", key: "pill", label: "Tag with map pin", maxLength: 120 },
  { kind: "checkbox", key: "center", label: "Centre the text" },
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
