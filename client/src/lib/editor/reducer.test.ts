import { describe, expect, it } from "vitest";
import { DEFAULT_THEME } from "@/lib/schema/theme";
import { LIMITS } from "@/lib/schema/primitives";
import {
  initialState,
  isDirty,
  makeBlockId,
  reducer,
  type EditorAction,
  type EditorState,
} from "./reducer";

const start = () =>
  initialState({ layout: { version: 1, blocks: [] }, theme: DEFAULT_THEME });
const run = (s: EditorState, ...actions: EditorAction[]) =>
  actions.reduce(reducer, s);
const ids = (s: EditorState) => s.layout.blocks.map(b => b.id);
const three = () =>
  run(
    start(),
    { type: "add", blockType: "hero", id: "a" },
    { type: "add", blockType: "text", id: "b" },
    { type: "add", blockType: "cta", id: "c" }
  );

describe("editor reducer", () => {
  it("adds blocks with registry defaults at an index and selects the new block", () => {
    const s = run(three(), {
      type: "add",
      blockType: "divider",
      id: "d",
      index: 1,
    });
    expect(ids(s)).toEqual(["a", "d", "b", "c"]);
    expect(s.selectedId).toBe("d");
    expect(s.layout.blocks[1]).toEqual({
      id: "d",
      type: "divider",
      props: { style: "solid" },
    });
  });
  it("does not share default objects between blocks", () => {
    const s = run(
      start(),
      { type: "add", blockType: "hero", id: "a" },
      { type: "add", blockType: "hero", id: "b" },
      { type: "patchProps", id: "a", patch: { heading: "X" }, now: 0 }
    );
    expect((s.layout.blocks[1]!.props as { heading: string }).heading).not.toBe(
      "X"
    );
  });
  it("refuses to exceed the block limit", () => {
    let s = start();
    for (let i = 0; i < LIMITS.maxBlocks + 3; i++)
      s = reducer(s, { type: "add", blockType: "spacer", id: `s${i}` });
    expect(s.layout.blocks).toHaveLength(LIMITS.maxBlocks);
    expect(reducer(s, { type: "duplicate", id: "s0", newId: "dup" })).toBe(s);
  });
  it("reorders with move / moveBy and clamps at the edges", () => {
    expect(ids(run(three(), { type: "move", id: "c", toIndex: 0 }))).toEqual([
      "c",
      "a",
      "b",
    ]);
    expect(ids(run(three(), { type: "moveBy", id: "a", delta: 1 }))).toEqual([
      "b",
      "a",
      "c",
    ]);
    const edge = three();
    expect(run(edge, { type: "moveBy", id: "a", delta: -1 })).toBe(edge);
    expect(ids(run(three(), { type: "moveBy", id: "c", delta: 10 }))).toEqual([
      "a",
      "b",
      "c",
    ]);
  });
  it("duplicates deeply after the original with a fresh id", () => {
    const s = run(
      three(),
      { type: "patchProps", id: "a", patch: { heading: "Hi" }, now: 0 },
      { type: "duplicate", id: "a", newId: "a2" }
    );
    expect(ids(s)).toEqual(["a", "a2", "b", "c"]);
    const s2 = run(s, {
      type: "patchProps",
      id: "a2",
      patch: { heading: "Changed" },
      now: 5000,
    });
    expect((s2.layout.blocks[0]!.props as { heading: string }).heading).toBe(
      "Hi"
    );
  });
  it("deletes and moves selection to a neighbour", () => {
    const s = run(
      three(),
      { type: "select", id: "b" },
      { type: "remove", id: "b" }
    );
    expect(ids(s)).toEqual(["a", "c"]);
    expect(s.selectedId).toBe("c");
    expect(run(s, { type: "remove", id: "zzz" })).toBe(s);
  });
  it("undo/redo restores layout, and a new edit clears redo", () => {
    let s = run(three(), { type: "remove", id: "b" });
    s = reducer(s, { type: "undo" });
    expect(ids(s)).toEqual(["a", "b", "c"]);
    s = reducer(s, { type: "redo" });
    expect(ids(s)).toEqual(["a", "c"]);
    s = run(s, { type: "undo" }, { type: "add", blockType: "spacer", id: "x" });
    expect(s.future).toHaveLength(0);
  });
  it("coalesces rapid edits to the same field into one undo step", () => {
    let s = three();
    const base = s.past.length;
    for (let i = 0; i < 5; i++)
      s = reducer(s, {
        type: "patchProps",
        id: "a",
        patch: { heading: `h${i}` },
        now: 100 + i * 50,
      });
    expect(s.past.length).toBe(base + 1);
    s = reducer(s, { type: "undo" });
    expect((s.layout.blocks[0]!.props as { heading: string }).heading).toBe(
      "Powering what’s next"
    );
    // a pause > 1s starts a new step
    s = run(
      three(),
      { type: "patchProps", id: "a", patch: { heading: "x" }, now: 0 },
      { type: "patchProps", id: "a", patch: { heading: "y" }, now: 5000 }
    );
    expect(s.past.length).toBe(3 + 2);
  });
  it("tracks dirty state against the saved baseline, not history", () => {
    let s = three();
    expect(isDirty(s)).toBe(true);
    s = reducer(s, {
      type: "markSaved",
      snapshot: { layout: s.layout, theme: s.theme },
    });
    expect(isDirty(s)).toBe(false);
    s = reducer(s, { type: "remove", id: "a" });
    expect(isDirty(s)).toBe(true);
    expect(isDirty(reducer(s, { type: "undo" }))).toBe(false);
  });
  it("keeps edits made while a save was in flight dirty", () => {
    const s = three();
    const sent = { layout: s.layout, theme: s.theme };
    const edited = reducer(s, {
      type: "patchProps",
      id: "a",
      patch: { heading: "typed during save" },
      now: 9,
    });
    expect(
      isDirty(reducer(edited, { type: "markSaved", snapshot: sent }))
    ).toBe(true);
  });
  it("theme patches participate in undo and dirty tracking", () => {
    let s = initialState({
      layout: { version: 1, blocks: [] },
      theme: DEFAULT_THEME,
    });
    s = reducer(s, {
      type: "patchTheme",
      patch: { primary: "#000000" },
      now: 0,
    });
    expect(isDirty(s)).toBe(true);
    expect(reducer(s, { type: "undo" }).theme.primary).toBe(
      DEFAULT_THEME.primary
    );
  });
  it("generates well-formed unique ids", () => {
    const set = new Set(Array.from({ length: 500 }, () => makeBlockId("hero")));
    expect(set.size).toBe(500);
    for (const id of set) expect(id).toMatch(/^hero-[a-z0-9]{6}$/);
  });
});
