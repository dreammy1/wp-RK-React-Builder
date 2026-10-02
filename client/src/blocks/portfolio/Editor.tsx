import type { FieldDef } from "../fields";
import { gridOptionFields } from "../gridOptions";

export const portfolioFields: FieldDef[] = [
  { kind: "group", label: "Content" },
  { kind: "text", key: "title", label: "Section title", maxLength: 120 },
  { kind: "number", key: "limit", label: "Items to show", min: 1, max: 24 },
  {
    kind: "select",
    key: "cols",
    label: "Columns",
    numeric: true,
    options: [
      { value: 2, label: "2 columns" },
      { value: 3, label: "3 columns" },
      { value: 4, label: "4 columns" },
    ],
  },
  {
    kind: "text",
    key: "category",
    label: "Category slug",
    maxLength: 60,
    help: "Leave blank to show every category.",
  },
  {
    kind: "select",
    key: "orderBy",
    label: "Sort by",
    options: [
      { value: "menu_order", label: "Custom order" },
      { value: "date", label: "Date" },
      { value: "title", label: "Title" },
    ],
  },
  {
    kind: "select",
    key: "order",
    label: "Direction",
    options: [
      { value: "asc", label: "Ascending" },
      { value: "desc", label: "Descending" },
    ],
  },
  ...gridOptionFields,
];
