import { z } from "zod";
import { linkUrl, text } from "@/lib/schema/primitives";

export const ctaProps = z.strictObject({
  heading: text(1, 160),
  cta: text(1, 60),
  ctaHref: linkUrl,
});
export type CtaProps = z.infer<typeof ctaProps>;
export const ctaDefaults: CtaProps = {
  heading: "Ready to start your project?",
  cta: "Contact us",
  ctaHref: "/contact",
};
