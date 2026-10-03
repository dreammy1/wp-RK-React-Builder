import { z } from "zod";
import { imageUrl, text } from "@/lib/schema/primitives";

export const imageProps = z
  .strictObject({
    mediaId: z.number().int().min(0).max(2_147_483_647).optional(),
    url: imageUrl,
    alt: text(0, 300),
    decorative: z.boolean(),
    width: z.number().int().min(1).max(10000).optional(),
    height: z.number().int().min(1).max(10000).optional(),
    srcset: text(0, 1500).optional(),
  })
  .refine(p => p.decorative || p.alt.trim().length > 0, {
    message: "Alt text is required unless the image is decorative",
    path: ["alt"],
  });
export type ImageProps = z.infer<typeof imageProps>;
export const imageDefaults: ImageProps = {
  url: "/placeholder.svg",
  alt: "Describe what the image shows",
  decorative: false,
  width: 1200,
  height: 600,
};
