import { z } from "zod";
import { text } from "@/lib/schema/primitives";

export const galleryProps = z.strictObject({
  /** One photo per line: "image|Category|Description" (up to 40). The first is shown large. */
  items: text(0, 12000),
  /** A row of category buttons above the grid. */
  filters: z.boolean().optional(),
});
export type GalleryProps = z.infer<typeof galleryProps>;
export const galleryDefaults: GalleryProps = { items: "", filters: false };
