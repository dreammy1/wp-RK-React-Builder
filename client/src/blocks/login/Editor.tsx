import type { FieldDef } from "../fields";

export const loginFields: FieldDef[] = [
  {
    kind: "select",
    key: "layout",
    label: "Layout",
    options: [
      { value: "split", label: "Full screen: picture and form" },
      { value: "card", label: "Form in a card" },
    ],
    help: "Full screen hides the site header and footer on this page.",
  },
  { kind: "text", key: "heading", label: "Heading", maxLength: 160 },
  { kind: "textarea", key: "intro", label: "Intro", maxLength: 400 },
  { kind: "text", key: "button", label: "Button label", maxLength: 40 },
  {
    kind: "media",
    key: "imageUrl",
    idKey: "imageMediaId",
    label: "Side picture (full screen layout)",
    optional: true,
  },
  {
    kind: "text",
    key: "imageAlt",
    label: "Picture description",
    maxLength: 300,
    help: "Leave empty if the picture is only decorative",
  },
  {
    kind: "select",
    key: "side",
    label: "Picture side",
    options: [
      { value: "left", label: "Left" },
      { value: "right", label: "Right" },
    ],
  },
  {
    kind: "checkbox",
    key: "showBrand",
    label: "Show the logo and site name",
    help: "Taken from Themes (logo) and Site & SEO. Filled in on the live page.",
  },
  {
    kind: "checkbox",
    key: "showDetails",
    label: "Show business details (address, phone, email, hours)",
    help: "Taken from Site & SEO > Business details.",
  },
  {
    kind: "checkbox",
    key: "remember",
    label: "Show “Remember me”",
    defaultOn: true,
  },
  {
    kind: "checkbox",
    key: "forgot",
    label: "Show “Forgot your password?”",
    defaultOn: true,
  },
];
