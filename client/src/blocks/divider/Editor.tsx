import type { FieldDef } from "../fields";

export const dividerFields: FieldDef[] = [
  {
    kind: "select",
    key: "style",
    label: "Line style",
    options: [
      { value: "solid", label: "Solid" },
      { value: "dashed", label: "Dashed" },
    ],
  },
];
