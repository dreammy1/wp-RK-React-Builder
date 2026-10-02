import { z } from "zod";
import { imageUrl, linkUrl, text } from "@/lib/schema/primitives";

export const navbarProps = z.strictObject({
  brand: text(1, 80),
  logoMediaId: z.number().int().min(0).max(2_147_483_647).optional(),
  logoUrl: imageUrl.optional(),
  /** One link per line: "Label|/path". */
  links: text(0, 1500),
  phone: text(0, 40),
  phoneHref: linkUrl,
  /** Sits on top of the block below it (a cover hero), light text, until the visitor scrolls. */
  overlay: z.boolean(),
});
export type NavbarProps = z.infer<typeof navbarProps>;
export const navbarDefaults: NavbarProps = {
  brand: "Your brand",
  links: "About|/about\nServices|/services\nContact|/contact",
  phone: "",
  phoneHref: "",
  overlay: true,
};
