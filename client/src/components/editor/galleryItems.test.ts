import { describe, expect, it } from "vitest";
import {
  parsePhotos,
  photoFromMedia,
  serializePhotos,
} from "./GalleryItemsEditor";

describe("gallery photo list", () => {
  it("round-trips the text format", () => {
    const text =
      "https://x.example/a.jpg|Installation|Warm floor\nhttps://x.example/b.jpg||Second\nhttps://x.example/c.jpg";
    const photos = parsePhotos(text);
    expect(photos).toEqual([
      {
        src: "https://x.example/a.jpg",
        category: "Installation",
        caption: "Warm floor",
      },
      { src: "https://x.example/b.jpg", category: "", caption: "Second" },
      { src: "https://x.example/c.jpg", category: "", caption: "" },
    ]);
    expect(serializePhotos(photos)).toBe(text);
  });
  it("keeps the separators out of values", () => {
    const out = serializePhotos([
      {
        src: "https://x.example/a.jpg",
        category: "A|B",
        caption: "two\nlines",
      },
    ]);
    expect(out).toBe("https://x.example/a.jpg|A/B|two lines");
    expect(parsePhotos(out)).toHaveLength(1);
  });
  it("drops empty addresses and stops at 300 photos", () => {
    const many = Array.from({ length: 305 }, (_, i) => ({
      src: `https://x.example/${i}.jpg`,
      category: "",
      caption: "",
    }));
    expect(serializePhotos(many).split("\n")).toHaveLength(300);
    expect(serializePhotos([{ src: " ", category: "x", caption: "y" }])).toBe(
      ""
    );
  });
  it("a picked image starts with its alt text, else its title", () => {
    const base = { id: 1, url: "https://x.example/a.jpg", alt: "", title: "" };
    expect(photoFromMedia({ ...base, alt: "Alt", title: "T" }).caption).toBe(
      "Alt"
    );
    expect(photoFromMedia({ ...base, title: "T" }).caption).toBe("T");
    expect(photoFromMedia(base).caption).toBe("");
  });
});
