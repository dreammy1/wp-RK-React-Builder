import { z } from "zod";
import { linkUrl, text } from "@/lib/schema/primitives";

export const calculatorProps = z.strictObject({
  heading: text(0, 120),
  /** One project type per line: "Label|Rate per unit|Unit label" (e.g. "Sand & refinish|5.5|Approximate square feet"). */
  types: text(0, 1200),
  /** Starting quantity. */
  amount: z.number().int().min(1).max(100000),
  resultLabel: text(0, 60),
  note: text(0, 400),
  ctaLabel: text(0, 60),
  ctaHref: linkUrl,
});
export type CalculatorProps = z.infer<typeof calculatorProps>;
export const calculatorDefaults: CalculatorProps = {
  heading: "Project details",
  types:
    "Sand & refinish|5.5|Approximate square feet\nNew installation|8|Approximate square feet",
  amount: 800,
  resultLabel: "Planning range",
  note: "This is a rough planning number, not a quote.",
  ctaLabel: "Talk through your project",
  ctaHref: "/contact",
};
