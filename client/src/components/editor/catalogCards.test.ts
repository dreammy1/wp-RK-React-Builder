import { describe, expect, it } from "vitest";
import { parseRows } from "@/blocks/links";
import { parseCards, serializeCards } from "./CatalogCardsEditor";

const ITEMS = [
  "https://x.test/a.jpg|Eyebrow|Unfinished Hardwood|Start with raw boards. · Hardwood|Species: Oak; Width: 5in|Custom stain matching; Site-finished surface|",
  "#c8b79a||Swatch card|Just a swatch|||/contact",
  "https://x.test/c.jpg||Third|Third blurb|||",
].join("\n");
const MODALS = [
  "https://x.test/p.png|Unfinished Domestic|Domestic solid hardwood.|Yellow pine grade 2; Red oak character",
  "|||",
  "|Third pop-up|Text|One",
].join("\n");

describe("catalog card editor round trip", () => {
  it("reads every field of a card and its pop-up", () => {
    const [a, b, c] = parseCards(ITEMS, MODALS);
    expect(a).toMatchObject({
      image: "https://x.test/a.jpg",
      eyebrow: "Eyebrow",
      title: "Unfinished Hardwood",
      blurb: "Start with raw boards.",
      tag: "Hardwood",
      specs: [
        ["Species", "Oak"],
        ["Width", "5in"],
      ],
      bullets: ["Custom stain matching", "Site-finished surface"],
      popup: true,
      pTitle: "Unfinished Domestic",
      pItems: ["Yellow pine grade 2", "Red oak character"],
    });
    expect(b).toMatchObject({
      image: "#c8b79a",
      link: "/contact",
      popup: false,
    });
    expect(c).toMatchObject({ popup: true, pTitle: "Third pop-up" });
  });

  it("writes the same text back, and keeps pop-ups lined up with their cards", () => {
    const out = serializeCards(parseCards(ITEMS, MODALS));
    expect(parseRows(out.items, 7, 24)).toEqual(parseRows(ITEMS, 7, 24));
    const pops = parseRows(out.modals, 4, 24);
    expect(pops).toHaveLength(3);
    expect(pops[1]).toEqual(["", "", "", ""]);
    expect(pops[2]![1]).toBe("Third pop-up");
  });

  it("writes no pop-up rows at all when no card has one", () => {
    const cards = parseCards(ITEMS, MODALS).map(c => ({ ...c, popup: false }));
    expect(serializeCards(cards).modals).toBe("");
  });

  it("keeps the separators out of values", () => {
    const [c] = parseCards("|||x|||", "");
    const out = serializeCards([
      { ...c!, title: "A|B\nC", bullets: ["one; two"], specs: [["k;", "v|w"]] },
    ]);
    expect(out.items).toBe("||A/B C|x|k,: v/w|one, two|");
    expect(parseRows(out.items, 7, 24)).toHaveLength(1);
  });

  it("round-trips an empty block", () => {
    expect(serializeCards(parseCards("", ""))).toEqual({
      items: "",
      modals: "",
    });
  });
});
