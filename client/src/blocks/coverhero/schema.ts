import { z } from "zod";
import { imageUrl, linkUrl, text } from "@/lib/schema/primitives";

export const coverheroProps = z.strictObject({
  /** Breadcrumb trail after "Home": one per line, "Label|/path" (the last may have no path). */
  crumb: text(0, 400),
  eyebrow: text(0, 80),
  heading: text(1, 160),
  sub: text(0, 400),
  cta: text(0, 60),
  ctaHref: linkUrl,
  cta2: text(0, 60),
  cta2Href: linkUrl,
  bgMediaId: z.number().int().min(0).max(2_147_483_647).optional(),
  bgUrl: imageUrl.optional(),
  /** "screen" fills the viewport (home); "page" is a shorter, bottom-aligned page header. */
  size: z.enum(["screen", "page"]),
});
export type CoverheroProps = z.infer<typeof coverheroProps>;
export const coverheroDefaults: CoverheroProps = {
  crumb: "",
  eyebrow: "",
  heading: "A headline that earns the scroll",
  sub: "",
  cta: "",
  ctaHref: "",
  cta2: "",
  cta2Href: "",
  size: "screen",
};
