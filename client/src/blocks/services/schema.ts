import { z } from "zod";
import { slug, text } from "@/lib/schema/primitives";

export const servicesProps = z.strictObject({
  title: text(0, 120),
  source: z.literal("service"),
  limit: z.number().int().min(1).max(24),
  cols: z.number().int().min(2).max(4),
  category: slug,
  orderBy: z.enum(["date", "title", "menu_order"]),
  order: z.enum(["asc", "desc"]),
});
export type ServicesProps = z.infer<typeof servicesProps>;
export const servicesDefaults: ServicesProps = {
  title: "Our Services",
  source: "service",
  limit: 6,
  cols: 3,
  category: "",
  orderBy: "menu_order",
  order: "asc",
};
