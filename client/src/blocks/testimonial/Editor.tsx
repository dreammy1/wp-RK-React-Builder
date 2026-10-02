import type { FieldDef } from "../fields";

export const testimonialFields: FieldDef[] = [
  { kind: "textarea", key: "quote", label: "Quote", maxLength: 600 },
  { kind: "text", key: "author", label: "Name", maxLength: 80 },
  { kind: "text", key: "role", label: "Role or location", maxLength: 120 },
];
