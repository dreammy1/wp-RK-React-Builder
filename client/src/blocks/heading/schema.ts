import { z } from "zod";
import { text } from "@/lib/schema/primitives";

export const headingProps = z.strictObject({
  text: text(1, 200),
  level: z.union([z.literal(2), z.literal(3)]),
});
export type HeadingProps = z.infer<typeof headingProps>;
export const headingDefaults: HeadingProps = {
  text: "Section heading",
  level: 2,
};
