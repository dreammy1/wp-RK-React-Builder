import { z } from "zod";
import { text } from "@/lib/schema/primitives";

export const valuesProps = z.strictObject({
  eyebrow: text(0, 80),
  heading: text(1, 200),
  /** One card per line: "Title|Text" (up to 8). */
  items: text(0, 3000),
  cols: z.number().int().min(2).max(4),
  tone: z.enum(["light", "muted"]),
});
export type ValuesProps = z.infer<typeof valuesProps>;
export const valuesDefaults: ValuesProps = {
  eyebrow: "",
  heading: "What we stand for",
  items: "",
  cols: 4,
  tone: "muted",
};
