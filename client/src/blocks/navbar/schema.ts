import { z } from "zod";
import { imageUrl, linkUrl, text } from "@/lib/schema/primitives";

export const navbarProps = z.strictObject({
  brand: text(1, 80),
  logoMediaId: z.number().int().min(0).max(2_147_483_647).optional(),
  logoUrl: imageUrl.optional(),
  /** One link per line: "Label|/path". A line starting with "- " is a drop-down item of the line above. */
  links: text(0, 3000),
  phone: text(0, 40),
  phoneHref: linkUrl,
  /** Sits on top of the block below it (a cover hero), light text, until the visitor scrolls. */
  overlay: z.boolean(),
  /** Bar colour when it is solid: the page's own (auto), light, dark or the primary colour. */
  bg: z.enum(["auto", "light", "dark", "primary"]).optional(),
  size: z.enum(["compact", "regular", "tall"]).optional(),
  /** Menu next to the logo (left) or pushed towards the buttons (spread). */
  align: z.enum(["spread", "left"]).optional(),
  logoSize: z.enum(["sm", "md", "lg"]).optional(),
  /** Button look: automatic (follows the bar), filled or outlined. */
  buttons: z.enum(["auto", "solid", "outline"]).optional(),
  /** A second call-to-action button next to the phone button. */
  ctaText: text(0, 40).optional(),
  ctaHref: linkUrl.optional(),
  shadow: z.boolean().optional(),
  /** Gets slimmer and gains a shadow once the visitor scrolls. */
  shrink: z.boolean().optional(),
  /** Announcement bar above the header: a message, an optional link and a colour. */
  topText: text(0, 160).optional(),
  topLabel: text(0, 40).optional(),
  topHref: linkUrl.optional(),
  topTone: z.enum(["primary", "dark", "light"]).optional(),
  /** A close button; the choice is remembered in the visitor's browser until the message changes. */
  topDismiss: z.boolean().optional(),
});
export type NavbarProps = z.infer<typeof navbarProps>;
export const navbarDefaults: NavbarProps = {
  brand: "Your brand",
  links: "About|/about\nServices|/services\nContact|/contact",
  phone: "",
  phoneHref: "",
  overlay: true,
  bg: "auto",
  size: "regular",
  align: "spread",
  logoSize: "md",
  buttons: "auto",
  ctaText: "",
  ctaHref: "",
  shadow: false,
  shrink: false,
  topText: "",
  topLabel: "",
  topHref: "",
  topTone: "primary",
  topDismiss: false,
};
