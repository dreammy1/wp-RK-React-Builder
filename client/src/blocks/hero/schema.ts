import { z } from "zod";
import { linkUrl, text } from "@/lib/schema/primitives";

export const heroProps = z.strictObject({
  heading: text(1, 160),
  sub: text(0, 400),
  cta: text(0, 60),
  ctaHref: linkUrl,
});
export type HeroProps = z.infer<typeof heroProps>;
export const heroDefaults: HeroProps = {
  heading: "Powering what’s next",
  sub: "Licensed electrical and energy contractors serving the islands.",
  cta: "Get a quote",
  ctaHref: "/contact",
};
