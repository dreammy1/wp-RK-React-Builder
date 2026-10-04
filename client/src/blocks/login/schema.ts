import { z } from "zod";
import { imageUrl, text } from "@/lib/schema/primitives";

/**
 * A sign-in form. WordPress still checks the password: the form posts to wp-login.php (see includes/login.php).
 * `layout: "split"` fills the whole screen: a picture on one side, the form on the other.
 * The logo and business details are not stored here: the server fills them in from Themes and Site & SEO.
 */
export const loginProps = z.strictObject({
  heading: text(1, 160),
  intro: text(0, 400),
  button: text(1, 40),
  remember: z.boolean().optional(),
  forgot: z.boolean().optional(),
  layout: z.enum(["card", "split"]).optional(),
  side: z.enum(["left", "right"]).optional(),
  imageMediaId: z.number().int().min(0).max(2_147_483_647).optional(),
  imageUrl: imageUrl.optional(),
  imageAlt: text(0, 300).optional(),
  showBrand: z.boolean().optional(),
  showDetails: z.boolean().optional(),
});
export type LoginProps = z.infer<typeof loginProps>;
export const loginDefaults: LoginProps = {
  heading: "Welcome back",
  intro: "Sign in to continue.",
  button: "Sign in",
  remember: true,
  forgot: true,
  layout: "split",
  side: "left",
  imageAlt: "",
  showBrand: true,
  showDetails: true,
};
