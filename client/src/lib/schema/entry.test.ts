import { describe, expect, it } from "vitest";
import { Entry } from "./api";

const base = {
  id: 1,
  type: "service",
  title: "Plain",
  slug: "plain",
  status: "publish",
  excerpt: "",
  content: "",
  image: null,
  menuOrder: 0,
  link: "https://x.test/plain/",
  modified: "2026-01-01T00:00:00Z",
  seo: { title: "", description: "", image: "", noindex: false },
};

describe("Entry response", () => {
  it("accepts maps and the empty map PHP encodes as []", () => {
    expect(Entry.safeParse({ ...base, terms: {}, fields: {} }).success).toBe(
      true
    );
    const r = Entry.safeParse({ ...base, terms: [], fields: [] });
    expect(r.success).toBe(true);
    if (r.success) {
      expect(r.data.fields).toEqual({});
      expect(r.data.terms).toEqual({});
    }
  });
  it("still refuses a non-empty list", () => {
    expect(Entry.safeParse({ ...base, terms: {}, fields: ["x"] }).success).toBe(
      false
    );
  });
});
