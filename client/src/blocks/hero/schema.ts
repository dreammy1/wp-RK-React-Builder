import { z } from "zod";
import { imageUrl, linkUrl, text } from "@/lib/schema/primitives";

export const heroProps = z.strictObject({
  heading: text(1, 160),
  sub: text(0, 400),
  cta: text(0, 60),
  ctaHref: linkUrl,
  /** Optional decorative background photo (the headline carries the meaning). */
  bgMediaId: z.number().int().min(0).max(2_147_483_647).optional(),
  bgUrl: imageUrl.optional(),
});
export type HeroProps = z.infer<typeof heroProps>;
export const heroDefaults: HeroProps = {
  heading: "Your headline goes here",
  sub: "One or two lines about what you do and who it is for.",
  cta: "Get a quote",
  ctaHref: "/contact",
};
