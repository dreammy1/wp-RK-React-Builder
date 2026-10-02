import type { FieldDef } from "../fields";

export const navbarFields: FieldDef[] = [
  { kind: "text", key: "brand", label: "Brand name", maxLength: 80 },
  {
    kind: "media",
    key: "logoUrl",
    idKey: "logoMediaId",
    label: "Logo",
    optional: true,
  },
  {
    kind: "textarea",
    key: "links",
    label: "Menu links",
    maxLength: 1500,
    help: "One per line: Label|/path (up to 12)",
  },
  { kind: "text", key: "phone", label: "Phone button text", maxLength: 40 },
  {
    kind: "url",
    key: "phoneHref",
    label: "Phone button link",
    maxLength: 500,
    help: "e.g. tel:+13098635246",
  },
  {
    kind: "checkbox",
    key: "overlay",
    label: "Overlay the hero below (transparent, light text)",
  },
];
