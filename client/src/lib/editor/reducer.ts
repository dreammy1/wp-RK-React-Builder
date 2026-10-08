import { registry } from "@/blocks/registry";
import type { Block, LayoutDocument } from "@/lib/schema/layout";
import { LIMITS, type BlockType } from "@/lib/schema/primitives";
import type { ThemeConfig } from "@/lib/schema/theme";
import { mergeAdvanced } from "./styleModel";

export type Snapshot = { layout: LayoutDocument; theme: ThemeConfig };

export type EditorState = {
  layout: LayoutDocument;
  theme: ThemeConfig;
  selectedId: string | null;
  past: Snapshot[];
  future: Snapshot[];
  /** Serialized snapshot last known to match the server (or a clean load). */
  baseline: string;
  lastCoalesce: { key: string; at: number } | null;
};

export type EditorAction =
  | {
      type: "add";
      blockType: BlockType;
      index?: number;
      id: string;
      /** Initial props instead of the type defaults (e.g. a reusable reference). */
      props?: Record<string, unknown>;
    }
  | {
      type: "convert";
      id: string;
      blockType: BlockType;
      props: Record<string, unknown>;
    }
  | { type: "remove"; id: string }
  | { type: "duplicate"; id: string; newId: string }
  | { type: "move"; id: string; toIndex: number }
  | { type: "moveBy"; id: string; delta: number }
  | {
      type: "patchProps";
      id: string;
      patch: Record<string, unknown>;
      now: number;
    }
  | {
      /** Replace (or clear, with undefined) a block's advanced presentation object. */
      type: "patchAdvanced";
      id: string;
      patch: Record<string, unknown>;
      now: number;
    }
  | { type: "patchTheme"; patch: Partial<ThemeConfig>; now: number }
  | { type: "select"; id: string | null }
  | { type: "undo" }
  | { type: "redo" }
  | { type: "replace"; snapshot: Snapshot; clean: boolean }
  | { type: "markSaved"; snapshot: Snapshot };

export const HISTORY_LIMIT = 100;
const COALESCE_MS = 1000;

export const serialize = (s: Snapshot) => JSON.stringify([s.layout, s.theme]);
export const isDirty = (state: EditorState) =>
  serialize({ layout: state.layout, theme: state.theme }) !== state.baseline;

export function initialState(snapshot: Snapshot): EditorState {
  return {
    ...snapshot,
    selectedId: snapshot.layout.blocks[0]?.id ?? null,
    past: [],
    future: [],
    baseline: serialize(snapshot),
    lastCoalesce: null,
  };
}

const snap = (s: EditorState): Snapshot => ({
  layout: s.layout,
  theme: s.theme,
});

function commit(
  state: EditorState,
  next: Partial<EditorState>,
  coalesceKey?: string,
  now = 0
): EditorState {
  const coalesced =
    coalesceKey !== undefined &&
    state.lastCoalesce?.key === coalesceKey &&
    now - state.lastCoalesce.at < COALESCE_MS;
  const past = coalesced
    ? state.past
    : [...state.past, snap(state)].slice(-HISTORY_LIMIT);
  return {
    ...state,
    ...next,
    past,
    future: [],
    lastCoalesce:
      coalesceKey !== undefined ? { key: coalesceKey, at: now } : null,
  };
}

const withBlocks = (state: EditorState, blocks: Block[]): LayoutDocument => ({
  ...state.layout,
  blocks,
});

export function makeBlockId(
  type: BlockType,
  rand: () => number = Math.random
): string {
  return `${type}-${Math.floor(rand() * 36 ** 6)
    .toString(36)
    .padStart(6, "0")}`;
}

export function makeBlock(type: BlockType, id: string): Block {
  return { id, type, props: structuredClone(registry[type].defaults) } as Block;
}

export function reducer(state: EditorState, action: EditorAction): EditorState {
  const { blocks } = state.layout;
  switch (action.type) {
    case "add": {
      if (blocks.length >= LIMITS.maxBlocks) return state;
      const index = Math.min(
        Math.max(action.index ?? blocks.length, 0),
        blocks.length
      );
      const next = [...blocks];
      const block = makeBlock(action.blockType, action.id);
      if (action.props) block.props = action.props as never;
      next.splice(index, 0, block);
      return commit(state, {
        layout: withBlocks(state, next),
        selectedId: action.id,
      });
    }
    case "remove": {
      const index = blocks.findIndex(b => b.id === action.id);
      if (index < 0) return state;
      const next = blocks.filter(b => b.id !== action.id);
      const selectedId =
        state.selectedId === action.id
          ? (next[Math.min(index, next.length - 1)]?.id ?? null)
          : state.selectedId;
      return commit(state, { layout: withBlocks(state, next), selectedId });
    }
    case "duplicate": {
      const index = blocks.findIndex(b => b.id === action.id);
      if (index < 0 || blocks.length >= LIMITS.maxBlocks) return state;
      const copy = {
        ...structuredClone(blocks[index]!),
        id: action.newId,
      } as Block;
      const next = [...blocks];
      next.splice(index + 1, 0, copy);
      return commit(state, {
        layout: withBlocks(state, next),
        selectedId: action.newId,
      });
    }
    case "move": {
      const from = blocks.findIndex(b => b.id === action.id);
      if (from < 0) return state;
      const to = Math.min(Math.max(action.toIndex, 0), blocks.length - 1);
      if (to === from) return state;
      const next = [...blocks];
      const [moved] = next.splice(from, 1);
      next.splice(to, 0, moved!);
      return commit(state, { layout: withBlocks(state, next) });
    }
    case "moveBy": {
      const from = blocks.findIndex(b => b.id === action.id);
      if (from < 0) return state;
      return reducer(state, {
        type: "move",
        id: action.id,
        toIndex: from + action.delta,
      });
    }
    case "patchProps": {
      const index = blocks.findIndex(b => b.id === action.id);
      if (index < 0) return state;
      const target = blocks[index]!;
      const next = [...blocks];
      next[index] = {
        ...target,
        props: { ...target.props, ...action.patch },
      } as Block;
      const key = `props:${action.id}:${Object.keys(action.patch).sort().join(",")}`;
      return commit(
        state,
        { layout: withBlocks(state, next) },
        key,
        action.now
      );
    }
    case "patchAdvanced": {
      const index = blocks.findIndex(b => b.id === action.id);
      if (index < 0) return state;
      const target = blocks[index]!;
      const merged = mergeAdvanced(target.advanced, action.patch);
      const next = [...blocks];
      const rebuilt = { ...target } as Block & { advanced?: unknown };
      if (merged) rebuilt.advanced = merged;
      else delete rebuilt.advanced;
      next[index] = rebuilt;
      const key = `advanced:${action.id}:${Object.keys(action.patch).sort().join(",")}`;
      return commit(
        state,
        { layout: withBlocks(state, next) },
        key,
        action.now
      );
    }
    case "convert": {
      // Same position and id, different block: used to link a block to the library and to detach it again.
      const index = blocks.findIndex(b => b.id === action.id);
      if (index < 0) return state;
      const next = [...blocks];
      next[index] = {
        id: action.id,
        type: action.blockType,
        props: action.props,
      } as Block;
      return commit(state, { layout: withBlocks(state, next) });
    }
    case "patchTheme":
      return commit(
        state,
        { theme: { ...state.theme, ...action.patch } },
        `theme:${Object.keys(action.patch).sort().join(",")}`,
        action.now
      );
    case "select":
      return { ...state, selectedId: action.id };
    case "undo": {
      const prev = state.past[state.past.length - 1];
      if (!prev) return state;
      return {
        ...state,
        ...prev,
        past: state.past.slice(0, -1),
        future: [snap(state), ...state.future],
        selectedId: prev.layout.blocks.some(b => b.id === state.selectedId)
          ? state.selectedId
          : null,
        lastCoalesce: null,
      };
    }
    case "redo": {
      const nxt = state.future[0];
      if (!nxt) return state;
      return {
        ...state,
        ...nxt,
        past: [...state.past, snap(state)],
        future: state.future.slice(1),
        selectedId: nxt.layout.blocks.some(b => b.id === state.selectedId)
          ? state.selectedId
          : null,
        lastCoalesce: null,
      };
    }
    case "replace":
      return {
        ...initialState(action.snapshot),
        baseline: action.clean ? serialize(action.snapshot) : state.baseline,
        // keep history when a draft is restored on top of server state so it can be undone
        past: action.clean ? [] : [...state.past, snap(state)],
      };
    case "markSaved":
      // Baseline is the snapshot that was actually sent, so edits typed mid-request stay dirty.
      return { ...state, baseline: serialize(action.snapshot) };
  }
}
