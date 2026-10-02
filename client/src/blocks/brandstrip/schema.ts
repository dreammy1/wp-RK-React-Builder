import { z } from "zod";
import { text } from "@/lib/schema/primitives";

export const brandstripProps = z.strictObject({
  label: text(0, 120),
  /** One name per line (up to 12). */
  items: text(0, 600),
});
export type BrandstripProps = z.infer<typeof brandstripProps>;
export const brandstripDefaults: BrandstripProps = {
  label: "We work with trusted products",
  items: "Brand one\nBrand two\nBrand three",
};
