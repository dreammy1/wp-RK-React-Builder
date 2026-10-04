import type { FieldDef } from "../fields";

export const galleryFields: FieldDef[] = [
  { kind: "galleryItems", key: "items", label: "Photos" },
  { kind: "group", label: "Layout" },
  {
    kind: "select",
    key: "columns",
    label: "Columns",
    numeric: true,
    options: [
      { value: 2, label: "2" },
      { value: 3, label: "3" },
      { value: 4, label: "4" },
    ],
  },
  {
    kind: "select",
    key: "shape",
    label: "Photo shape",
    options: [
      { value: "rows", label: "Equal-height rows" },
      { value: "square", label: "Square" },
      { value: "landscape", label: "Landscape (4:3)" },
      { value: "portrait", label: "Portrait (3:4)" },
      { value: "wide", label: "Wide (16:9)" },
    ],
  },
  {
    kind: "select",
    key: "gap",
    label: "Space between photos",
    options: [
      { value: "sm", label: "Small" },
      { value: "md", label: "Medium" },
      { value: "lg", label: "Large" },
    ],
  },
  {
    kind: "checkbox",
    key: "featured",
    label: "Show the first photo large",
    defaultOn: true,
  },
  { kind: "group", label: "Behaviour" },
  {
    kind: "select",
    key: "captions",
    label: "Captions",
    options: [
      { value: "overlay", label: "Over the photo" },
      { value: "below", label: "Below the photo" },
      { value: "hover", label: "On hover" },
      { value: "none", label: "Hidden" },
    ],
  },
  {
    kind: "checkbox",
    key: "lightbox",
    label: "Open a photo large when clicked",
  },
  { kind: "checkbox", key: "filters", label: "Show category buttons" },
];
