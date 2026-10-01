import type { FieldDef } from "../fields";

export const headingFields: FieldDef[] = [
  { kind: "text", key: "text", label: "Heading", maxLength: 200 },
  {
    kind: "select",
    key: "level",
    label: "Level",
    numeric: true,
    options: [
      { value: 2, label: "Section (H2)" },
      { value: 3, label: "Sub-section (H3)" },
    ],
  },
];
