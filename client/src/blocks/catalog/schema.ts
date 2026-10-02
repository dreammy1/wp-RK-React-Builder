import { z } from "zod";
import { text } from "@/lib/schema/primitives";

export const catalogProps = z.strictObject({
  eyebrow: text(0, 80),
  heading: text(0, 200),
  intro: text(0, 1000),
  /**
   * One card per line, seven fields: image|eyebrow|title|blurb|specs|bullets|link
   * image = an image URL, or a #RRGGBB color for a swatch; specs = "Key: Value; Key: Value"; bullets = "a; b; c".
   */
  items: text(0, 12000),
  cols: z.number().int().min(2).max(4),
  tone: z.enum(["light", "muted"]),
  /** Show 01, 02… on the photos. */
  numbered: z.boolean(),
  /** A row of filter buttons above the cards; a card's tag is the text after the last " · " in its blurb. */
  filters: z.boolean().optional(),
  /** Cards share one outer border and 1px dividers instead of sitting apart. */
  joined: z.boolean().optional(),
  /**
   * Pop-ups, one per line and matched to the cards in order: image|Title|Intro|item; item; item.
   * A card with a pop-up gets a button that opens it.
   */
  modals: text(0, 8000).optional(),
  modalLabel: text(0, 40).optional(),
  /** "Label|/link" shown at the foot of every pop-up. */
  modalCta: text(0, 120).optional(),
});
export type CatalogProps = z.infer<typeof catalogProps>;
export const catalogDefaults: CatalogProps = {
  eyebrow: "",
  heading: "",
  intro: "",
  items: "",
  cols: 3,
  tone: "light",
  numbered: false,
  filters: false,
  joined: false,
  modals: "",
  modalLabel: "View all products",
  modalCta: "",
};
