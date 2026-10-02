import { z } from "zod";
import { slug, text } from "@/lib/schema/primitives";
import { gridOptionDefaults, gridOptionShape } from "../gridOptions";

export const portfolioProps = z.strictObject({
  title: text(0, 120),
  source: z.literal("portfolio"),
  limit: z.number().int().min(1).max(24),
  cols: z.number().int().min(2).max(4),
  category: slug,
  orderBy: z.enum(["date", "title", "menu_order"]),
  order: z.enum(["asc", "desc"]),
  ...gridOptionShape,
});
export type PortfolioProps = z.infer<typeof portfolioProps>;
export const portfolioDefaults: PortfolioProps = {
  title: "Recent Work",
  source: "portfolio",
  limit: 6,
  cols: 3,
  category: "",
  orderBy: "menu_order",
  order: "asc",
  ...gridOptionDefaults,
};
