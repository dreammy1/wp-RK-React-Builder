import { z } from "zod";
import { text } from "@/lib/schema/primitives";

/** Restricted text: plain paragraphs separated by blank lines. No HTML. */
export const textProps = z.strictObject({ text: text(0, 5000) });
export type TextProps = z.infer<typeof textProps>;
export const textDefaults: TextProps = {
  text: "Write a short paragraph of body copy here.",
};
