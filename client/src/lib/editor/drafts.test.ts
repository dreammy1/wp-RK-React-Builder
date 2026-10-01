import { describe, expect, it } from "vitest";
import { DEFAULT_THEME } from "@/lib/schema/theme";
import { clearLocalDraft, readLocalDraft, saveLocalDraft } from "./drafts";

const fakeStorage = () => {
  const m = new Map<string, string>();
  return {
    m,
    getItem: (k: string) => m.get(k) ?? null,
    setItem: (k: string, v: string) => void m.set(k, v),
    removeItem: (k: string) => void m.delete(k),
  };
};
const snap = {
  layout: {
    version: 1 as const,
    blocks: [{ id: "s", type: "spacer" as const, props: { h: 40 } }],
  },
  theme: DEFAULT_THEME,
};

describe("local drafts", () => {
  it("stores a page-specific envelope and reads it back validated", () => {
    const st = fakeStorage();
    expect(
      saveLocalDraft(42, snap, 7, st, new Date("2026-10-02T00:00:00Z"))
    ).toBe(true);
    const env = JSON.parse(st.m.get("rk:draft:42")!);
    expect(env).toMatchObject({
      pageId: 42,
      schemaVersion: 1,
      baseRevision: 7,
      savedAt: "2026-10-02T00:00:00.000Z",
    });
    expect(readLocalDraft(42, st)).toEqual({
      pageId: 42,
      baseRevision: 7,
      savedAt: "2026-10-02T00:00:00.000Z",
      snapshot: snap,
    });
  });
  it("isolates drafts per page", () => {
    const st = fakeStorage();
    saveLocalDraft(1, snap, 1, st);
    expect(readLocalDraft(2, st)).toBeNull();
  });
  it("discards corrupt, mismatched or invalid envelopes", () => {
    const st = fakeStorage();
    st.m.set("rk:draft:5", "{not json");
    expect(readLocalDraft(5, st)).toBeNull();
    expect(st.m.has("rk:draft:5")).toBe(false);
    st.m.set(
      "rk:draft:6",
      JSON.stringify({
        pageId: 99,
        schemaVersion: 1,
        savedAt: "x",
        layout: snap.layout,
        theme: snap.theme,
      })
    );
    expect(readLocalDraft(6, st)).toBeNull();
    st.m.set(
      "rk:draft:7",
      JSON.stringify({
        pageId: 7,
        schemaVersion: 1,
        savedAt: "x",
        layout: { version: 1, blocks: [{ id: "x", type: "evil", props: {} }] },
        theme: snap.theme,
      })
    );
    expect(readLocalDraft(7, st)).toBeNull();
    st.m.set(
      "rk:draft:8",
      JSON.stringify({
        pageId: 8,
        schemaVersion: 2,
        savedAt: "x",
        layout: snap.layout,
        theme: snap.theme,
      })
    );
    expect(readLocalDraft(8, st)).toBeNull();
  });
  it("clears after a successful save", () => {
    const st = fakeStorage();
    saveLocalDraft(3, snap, 1, st);
    clearLocalDraft(3, st);
    expect(readLocalDraft(3, st)).toBeNull();
  });
  it("survives unavailable or throwing storage", () => {
    const boom = {
      getItem: () => {
        throw new Error("x");
      },
      setItem: () => {
        throw new Error("quota");
      },
      removeItem: () => {
        throw new Error("x");
      },
    };
    expect(saveLocalDraft(1, snap, 1, boom)).toBe(false);
    expect(readLocalDraft(1, boom)).toBeNull();
    expect(saveLocalDraft(1, snap, 1, null)).toBe(false);
  });
});
