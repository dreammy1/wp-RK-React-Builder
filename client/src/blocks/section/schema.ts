import { z } from "zod";
import { linkUrl, text } from "@/lib/schema/primitives";

export const sectionProps = z.strictObject({
  eyebrow: text(0, 80),
  heading: text(0, 200),
  /** Paragraphs separated by a blank line. */
  body: text(0, 3000),
  linkLabel: text(0, 60),
  linkHref: linkUrl,
  tone: z.enum(["light", "muted"]),
  /** Centre the label and copy (a quiet strip between sections). */
  center: z.boolean().optional(),
  /** A small outlined tag with a map pin under the copy. */
  pill: text(0, 120).optional(),
});
export type SectionProps = z.infer<typeof sectionProps>;
export const sectionDefaults: SectionProps = {
  eyebrow: "",
  heading: "A section heading",
  body: "",
  linkLabel: "",
  linkHref: "",
  tone: "light",
  center: false,
  pill: "",
};
