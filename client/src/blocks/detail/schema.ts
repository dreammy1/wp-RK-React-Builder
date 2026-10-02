import { z } from "zod";
import { linkUrl, text } from "@/lib/schema/primitives";

export const detailProps = z.strictObject({
  eyebrow: text(0, 80),
  heading: text(1, 200),
  body: text(0, 2000),
  /** An optional highlighted note under the intro. */
  note: text(0, 600),
  stepsTitle: text(0, 120),
  /** One step per line. */
  steps: text(0, 3000),
  factorsTitle: text(0, 120),
  factorsIntro: text(0, 300),
  /** One point per line. */
  factors: text(0, 3000),
  /** One per line: "Label|/path". */
  links: text(0, 1500),
  faqTitle: text(0, 120),
  /** One per line: "Question|Answer". */
  faq: text(0, 6000),
  asideTitle: text(0, 120),
  asideText: text(0, 400),
  phone: text(0, 40),
  ctaLabel: text(0, 60),
  ctaHref: linkUrl,
});
export type DetailProps = z.infer<typeof detailProps>;
export const detailDefaults: DetailProps = {
  eyebrow: "Who it's for",
  heading: "Service name",
  body: "",
  note: "",
  stepsTitle: "How the process works",
  steps: "",
  factorsTitle: "What affects your price",
  factorsIntro: "Every project is unique — these are the main factors we weigh when quoting.",
  factors: "",
  links: "",
  faqTitle: "Frequently asked",
  faq: "",
  asideTitle: "Discuss your project",
  asideText: "Tell us about your space and we'll give honest guidance and a realistic estimate — no pressure.",
  phone: "",
  ctaLabel: "",
  ctaHref: "",
};
