import { z } from "zod";
import { imageUrl, linkUrl, text } from "@/lib/schema/primitives";

export const splitProps = z.strictObject({
  eyebrow: text(0, 80),
  heading: text(1, 200),
  body: text(0, 3000),
  /** One per line: "Value|Label", e.g. "75 mi|Service radius" (up to 6). */
  facts: text(0, 600),
  cta: text(0, 60),
  ctaHref: linkUrl,
  imageMediaId: z.number().int().min(0).max(2_147_483_647).optional(),
  imageUrl: imageUrl.optional(),
  imageAlt: text(0, 300),
  side: z.enum(["left", "right"]),
  tone: z.enum(["light", "muted"]),
});
export type SplitProps = z.infer<typeof splitProps>;
export const splitDefaults: SplitProps = {
  eyebrow: "",
  heading: "Image beside copy",
  body: "",
  facts: "",
  cta: "",
  ctaHref: "",
  imageAlt: "",
  side: "left",
  tone: "light",
};
