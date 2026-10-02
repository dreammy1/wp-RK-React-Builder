import { z } from "zod";
import { linkUrl, text } from "@/lib/schema/primitives";

export const visualizerProps = z.strictObject({
  /** Service cities offered in the optional city question, one per line (up to 20). */
  cities: text(0, 800),
  submitLabel: text(1, 80),
  ctaLabel: text(0, 80),
  ctaHref: linkUrl,
});
export type VisualizerProps = z.infer<typeof visualizerProps>;
export const visualizerDefaults: VisualizerProps = {
  cities: "Peoria\nPeoria Heights\nDunlap\nChillicothe\nMorton",
  submitLabel: "Create my floor visualization",
  ctaLabel: "Talk with us",
  ctaHref: "/contact",
};
