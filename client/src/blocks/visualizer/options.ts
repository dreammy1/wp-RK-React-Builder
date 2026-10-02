/** The visualizer's choices. Mirrored by rk_builder_viz_option_sets() in wp-plugin/rk-builder/includes/render/viz.php. */
export type VizOption = { value: string; label: string };
export type VizGroup = {
  name: string;
  legend: string;
  options: VizOption[];
};

const o = (value: string, label: string): VizOption => ({ value, label });

export const VIZ_GROUPS: VizGroup[] = [
  {
    name: "roomType",
    legend: "Room type",
    options: [
      o("kitchen", "Kitchen"),
      o("living_room", "Living room"),
      o("hallway", "Hallway"),
      o("bedroom", "Bedroom"),
      o("office", "Office"),
      o("retail_gym", "Retail / gym"),
    ],
  },
  {
    name: "projectType",
    legend: "Project type",
    options: [
      o("new_installation", "New installation"),
      o("refinish_existing_floor", "Refinish existing floor"),
      o("sandless_refresh", "Sandless refresh"),
      o("commercial_sports", "Commercial / sports"),
      o("deck", "Deck"),
      o("cabinets", "Cabinets"),
    ],
  },
  {
    name: "preferredStyle",
    legend: "Preferred style",
    options: [
      o("light_natural", "Light / natural"),
      o("warm_traditional", "Warm / traditional"),
      o("gray_weathered", "Gray / weathered"),
      o("dark_modern", "Dark / modern"),
      o("custom", "Custom"),
    ],
  },
];

export const VIZ_GROUPS_AFTER_STYLE: VizGroup[] = [
  {
    name: "woodSpecies",
    legend: "Wood species",
    options: [
      o("oak", "Oak"),
      o("maple", "Maple"),
      o("hickory", "Hickory"),
      o("mixed_unsure", "Mixed / Unsure"),
      o("existing_floor", "Existing Floor"),
    ],
  },
  {
    name: "floorDirection",
    legend: "Floor direction",
    options: [
      o("parallel", "Parallel"),
      o("perpendicular", "Perpendicular"),
      o("diagonal", "Diagonal"),
      o("herringbone", "Herringbone"),
      o("existing_direction", "Existing Direction"),
      o("unsure", "Unsure"),
    ],
  },
  {
    name: "finishPreference",
    legend: "Finish preference",
    options: [
      o("bona_traffic_hd", "Bona Traffic HD"),
      o("rubio_monocoat", "Rubio Monocoat"),
      o("polyurethane", "Polyurethane"),
      o("unsure", "Unsure"),
    ],
  },
  {
    name: "sheen",
    legend: "Sheen",
    options: [
      o("matte", "Matte"),
      o("satin", "Satin"),
      o("semi_gloss", "Semi-gloss"),
      o("unsure", "Unsure"),
    ],
  },
];

/** "Peoria Heights" -> "peoria_heights". Mirrored by rk_builder_viz_slug(). */
export const vizSlug = (label: string): string =>
  label
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "");
