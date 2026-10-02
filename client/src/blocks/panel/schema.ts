import { z } from "zod";
import { text } from "@/lib/schema/primitives";

export const panelProps = z.strictObject({
  /** "rows": copy on one side, divider rows on the other. "intro": heading on one side, copy + checks + links on the other. */
  mode: z.enum(["rows", "intro"]),
  eyebrow: text(0, 80),
  heading: text(0, 200),
  body: text(0, 3000),
  /** One check-marked point per line. */
  checks: text(0, 1500),
  /** One per line: "Label|/path". The first is a solid button in "rows" mode; the rest are text links. */
  actions: text(0, 1500),
  /** One per line: "Title|Text" or "Title|Text|/link". */
  items: text(0, 4000),
  itemStyle: z.enum(["feature", "contact"]),
  /** Put the rows on the left and the copy on the right. */
  flip: z.boolean(),
  /** Small label above the rows (only used when flipped). */
  kicker: text(0, 80),
  /** Give the copy side a gray box. */
  box: z.boolean(),
  tone: z.enum(["light", "muted"]),
});
export type PanelProps = z.infer<typeof panelProps>;
export const panelDefaults: PanelProps = {
  mode: "rows",
  eyebrow: "",
  heading: "A heading",
  body: "",
  checks: "",
  actions: "",
  items: "",
  itemStyle: "feature",
  flip: false,
  kicker: "",
  box: false,
  tone: "light",
};
