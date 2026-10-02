import type { FieldDef } from "../fields";

const links = "One per line: Label|/path (up to 12)";
export const sitefooterFields: FieldDef[] = [
  { kind: "text", key: "brand", label: "Brand name", maxLength: 80 },
  { kind: "media", key: "logoUrl", idKey: "logoMediaId", label: "Logo (shown in white)", optional: true },
  { kind: "textarea", key: "tagline", label: "Tagline", maxLength: 300 },
  { kind: "text", key: "colATitle", label: "Column 1 title", maxLength: 60 },
  { kind: "textarea", key: "colALinks", label: "Column 1 links", maxLength: 1500, help: links },
  { kind: "text", key: "colBTitle", label: "Column 2 title", maxLength: 60 },
  { kind: "textarea", key: "colBLinks", label: "Column 2 links", maxLength: 1500, help: links },
  { kind: "text", key: "contactTitle", label: "Contact title", maxLength: 60 },
  { kind: "text", key: "phone", label: "Phone", maxLength: 40 },
  { kind: "text", key: "email", label: "Email", maxLength: 120 },
  { kind: "textarea", key: "address", label: "Address / service area", maxLength: 300 },
  { kind: "text", key: "copyright", label: "Copyright line", maxLength: 200 },
  { kind: "textarea", key: "note", label: "Small print", maxLength: 300 },
];
