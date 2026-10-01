import type { ContentItem } from "@/lib/schema/api";

/** Explicit offline fixture. Only used when the editor is opened with `?demo=1` or in tests. Never a fallback. */
const card = (
  id: number,
  title: string,
  excerpt: string,
  categories: string[]
): ContentItem => ({
  id,
  title,
  excerpt,
  link: `/demo/${id}`,
  categories,
  image: null,
});

export const DEMO_CONTENT: Record<"service" | "portfolio", ContentItem[]> = {
  service: [
    card(1, "Residential Wiring", "Safe, code-compliant installs for homes.", [
      "residential",
    ]),
    card(2, "Solar & Battery", "Design and install of PV + storage.", [
      "energy",
    ]),
    card(3, "EV Charging", "Level-2 chargers, permits handled.", ["energy"]),
    card(4, "Maintenance", "Inspections and rapid callouts.", ["commercial"]),
  ],
  portfolio: [
    card(11, "Maui Beach House", "Full rewire + solar.", ["residential"]),
    card(12, "Downtown Retail", "Panel upgrade, LED retrofit.", ["commercial"]),
    card(13, "Warehouse EV Bay", "12-charger install.", ["commercial"]),
  ],
};
