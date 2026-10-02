import type { FieldDef } from "../fields";

export const contactbandFields: FieldDef[] = [
  { kind: "text", key: "heading", label: "Headline", maxLength: 160 },
  { kind: "textarea", key: "sub", label: "Supporting copy", maxLength: 400 },
  { kind: "text", key: "phone", label: "Phone", maxLength: 40 },
  { kind: "text", key: "email", label: "Email", maxLength: 120 },
];
