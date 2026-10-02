import type { FieldDef } from "../fields";

export const visualizerFields: FieldDef[] = [
  {
    kind: "textarea",
    key: "cities",
    label: "Service cities",
    maxLength: 800,
    help: "One per line (up to 20). Shown as the optional city question.",
  },
  { kind: "text", key: "submitLabel", label: "Button label", maxLength: 80 },
  {
    kind: "text",
    key: "ctaLabel",
    label: "Link label under the preview",
    maxLength: 80,
  },
  { kind: "url", key: "ctaHref", label: "Link target", maxLength: 300 },
];
