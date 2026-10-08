import { describe, expect, it } from "vitest";
import { DEFAULT_THEME } from "@/lib/schema/theme";
import {
  buildStylePatch,
  hasOverrides,
  mergeAdvanced,
  styleForBreakpoint,
} from "./styleModel";
import { initialState, isDirty, reducer } from "./reducer";

const start = () =>
  initialState({
    layout: {
      version: 1,
      blocks: [
        {
          id: "a",
          type: "hero",
          props: { heading: "Hi", sub: "", cta: "", ctaHref: "/" },
        },
      ],
    },
    theme: DEFAULT_THEME,
  });

describe("mergeAdvanced", () => {
  it("adds a group without touching the others", () => {
    const merged = mergeAdvanced(
      { spacing: { top: "8px" } },
      { border: { radius: "12px" } }
    );
    expect(merged).toEqual({
      spacing: { top: "8px" },
      border: { radius: "12px" },
    });
  });

  it("removes a group when it is cleared and drops an empty object", () => {
    expect(
      mergeAdvanced({ spacing: { top: "8px" } }, { spacing: undefined })
    ).toBe(undefined);
  });

  it("merges breakpoint overrides one level deep", () => {
    const first = mergeAdvanced(undefined, {
      overrides: { phone: { spacing: { top: "4px" } } },
    });
    const second = mergeAdvanced(first, {
      overrides: { phone: { border: { radius: "8px" } } },
    });
    expect(second?.overrides?.phone).toEqual({
      spacing: { top: "4px" },
      border: { radius: "8px" },
    });
  });

  it("drops a breakpoint bucket when its last override is removed", () => {
    const only = mergeAdvanced(undefined, {
      overrides: { tablet: { spacing: { top: "4px" } } },
    });
    expect(
      mergeAdvanced(only, { overrides: { tablet: { spacing: undefined } } })
    ).toBe(undefined);
  });
});

describe("buildStylePatch", () => {
  it("writes to the base on desktop", () => {
    expect(buildStylePatch("spacing", { top: "8px" }, "base")).toEqual({
      spacing: { top: "8px" },
    });
  });

  it("nests under the breakpoint on tablet and phone", () => {
    expect(buildStylePatch("spacing", { top: "8px" }, "phone")).toEqual({
      overrides: { phone: { spacing: { top: "8px" } } },
    });
  });

  it("clears by sending undefined", () => {
    expect(buildStylePatch("spacing", {}, "base")).toEqual({
      spacing: undefined,
    });
  });
});

describe("styleForBreakpoint", () => {
  const style = {
    spacing: { top: "48px", bottom: "48px" },
    overrides: { phone: { spacing: { top: "12px" } } },
  };

  it("returns the base unchanged on desktop", () => {
    expect(styleForBreakpoint(style, "base")).toEqual(style);
  });

  it("overlays the override on the base for the device", () => {
    expect(styleForBreakpoint(style, "phone")?.spacing).toEqual({
      top: "12px",
      bottom: "48px",
    });
  });

  it("returns an empty style for a device with no override", () => {
    expect(styleForBreakpoint(style, "tablet")).toEqual({});
  });

  it("reports whether any override exists", () => {
    expect(hasOverrides(style)).toBe(true);
    expect(hasOverrides({ spacing: { top: "8px" } })).toBe(false);
    expect(hasOverrides(undefined)).toBe(false);
  });
});

describe("reducer patchAdvanced", () => {
  it("stores and clears a block's style, marking it dirty and undoable", () => {
    let s = start();
    s = reducer(s, {
      type: "patchAdvanced",
      id: "a",
      patch: { spacing: { top: "24px" } },
      now: 0,
    });
    expect(s.layout.blocks[0]!.advanced).toEqual({ spacing: { top: "24px" } });
    expect(isDirty(s)).toBe(true);

    s = reducer(s, { type: "undo" });
    expect(s.layout.blocks[0]!.advanced).toBeUndefined();

    s = reducer(s, { type: "redo" });
    expect(s.layout.blocks[0]!.advanced).toEqual({ spacing: { top: "24px" } });
  });

  it("removes the advanced key entirely once every value is cleared", () => {
    let s = start();
    s = reducer(s, {
      type: "patchAdvanced",
      id: "a",
      patch: { spacing: { top: "8px" } },
      now: 0,
    });
    s = reducer(s, {
      type: "patchAdvanced",
      id: "a",
      patch: { spacing: undefined },
      now: 5000,
    });
    expect("advanced" in s.layout.blocks[0]!).toBe(false);
  });

  it("leaves other blocks alone", () => {
    let s = start();
    s = reducer(s, { type: "add", blockType: "text", id: "b" });
    s = reducer(s, {
      type: "patchAdvanced",
      id: "a",
      patch: { border: { radius: "8px" } },
      now: 0,
    });
    expect(s.layout.blocks.find(b => b.id === "b")!.advanced).toBeUndefined();
  });
});
