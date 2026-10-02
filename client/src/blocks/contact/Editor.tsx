import type { FieldDef } from "../fields";

export const contactFields: FieldDef[] = [
  { kind: "text", key: "heading", label: "Heading", maxLength: 160 },
  { kind: "textarea", key: "intro", label: "Intro", maxLength: 400 },
  { kind: "text", key: "phone", label: "Phone", maxLength: 40 },
  { kind: "text", key: "email", label: "Email", maxLength: 120 },
  { kind: "text", key: "address", label: "Address", maxLength: 200 },
  { kind: "text", key: "hours", label: "Opening hours", maxLength: 200 },
];
