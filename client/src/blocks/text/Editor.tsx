import type { FieldDef } from "../fields";

export const textFields: FieldDef[] = [
  {
    kind: "textarea",
    key: "text",
    label: "Body copy",
    maxLength: 5000,
    help: "Plain text. Leave a blank line between paragraphs.",
  },
];
