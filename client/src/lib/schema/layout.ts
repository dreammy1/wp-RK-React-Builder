import { z } from "zod";
import { heroProps } from "@/blocks/hero/schema";
import { headingProps } from "@/blocks/heading/schema";
import { textProps } from "@/blocks/text/schema";
import { imageProps } from "@/blocks/image/schema";
import { ctaProps } from "@/blocks/cta/schema";
import { servicesProps } from "@/blocks/services/schema";
import { portfolioProps } from "@/blocks/portfolio/schema";
import { spacerProps } from "@/blocks/spacer/schema";
import { dividerProps } from "@/blocks/divider/schema";
import { testimonialProps } from "@/blocks/testimonial/schema";
import { contactProps } from "@/blocks/contact/schema";
import { navbarProps } from "@/blocks/navbar/schema";
import { coverheroProps } from "@/blocks/coverhero/schema";
import { sitefooterProps } from "@/blocks/sitefooter/schema";
import { sectionProps } from "@/blocks/section/schema";
import { splitProps } from "@/blocks/split/schema";
import { contactbandProps } from "@/blocks/contactband/schema";
import { panelProps } from "@/blocks/panel/schema";
import { valuesProps } from "@/blocks/values/schema";
import { catalogProps } from "@/blocks/catalog/schema";
import { detailProps } from "@/blocks/detail/schema";
import { galleryProps } from "@/blocks/gallery/schema";
import { calculatorProps } from "@/blocks/calculator/schema";
import { visualizerProps } from "@/blocks/visualizer/schema";
import { brandstripProps } from "@/blocks/brandstrip/schema";
import { reviewsProps } from "@/blocks/reviews/schema";
import { reusableProps } from "@/blocks/reusable/schema";
import { blockId, LIMITS } from "./primitives";

const b = <T extends string, P extends z.ZodType>(type: T, props: P) =>
  z.strictObject({ id: blockId, type: z.literal(type), props });

export const BlockSchema = z.discriminatedUnion("type", [
  b("hero", heroProps),
  b("heading", headingProps),
  b("text", textProps),
  b("image", imageProps),
  b("cta", ctaProps),
  b("services", servicesProps),
  b("portfolio", portfolioProps),
  b("spacer", spacerProps),
  b("divider", dividerProps),
  b("testimonial", testimonialProps),
  b("contact", contactProps),
  b("navbar", navbarProps),
  b("coverhero", coverheroProps),
  b("sitefooter", sitefooterProps),
  b("section", sectionProps),
  b("split", splitProps),
  b("contactband", contactbandProps),
  b("panel", panelProps),
  b("values", valuesProps),
  b("catalog", catalogProps),
  b("detail", detailProps),
  b("gallery", galleryProps),
  b("calculator", calculatorProps),
  b("brandstrip", brandstripProps),
  b("reviews", reviewsProps),
  b("visualizer", visualizerProps),
  b("reusable", reusableProps),
]);
export type Block = z.infer<typeof BlockSchema>;

export const LayoutSchema = z
  .strictObject({
    version: z.literal(LIMITS.schemaVersion),
    blocks: z.array(BlockSchema).max(LIMITS.maxBlocks),
  })
  .superRefine((layout, ctx) => {
    const seen = new Set<string>();
    layout.blocks.forEach((block, i) => {
      if (seen.has(block.id)) {
        ctx.addIssue({
          code: "custom",
          message: `Duplicate block id "${block.id}"`,
          path: ["blocks", i, "id"],
        });
      }
      seen.add(block.id);
    });
  });
export type LayoutDocument = z.infer<typeof LayoutSchema>;

export const EMPTY_LAYOUT: LayoutDocument = { version: 1, blocks: [] };
