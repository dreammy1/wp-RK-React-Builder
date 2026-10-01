import { z } from "zod";
import { parseLayout, parseTheme } from "@/lib/schema/migrate";
import type { Snapshot } from "./reducer";

const SCHEMA_VERSION = 1;
const keyFor = (pageId: number) => `rk:draft:${pageId}`;

const Envelope = z.object({
  pageId: z.number().int(),
  schemaVersion: z.number().int(),
  /** Server revision this draft was based on. */
  baseRevision: z.number().int().optional(),
  savedAt: z.string(),
  layout: z.unknown(),
  theme: z.unknown(),
});

export type LocalDraft = {
  pageId: number;
  baseRevision?: number;
  savedAt: string;
  snapshot: Snapshot;
};

type StorageLike = Pick<Storage, "getItem" | "setItem" | "removeItem">;

const safeStorage = (): StorageLike | null => {
  try {
    return typeof localStorage === "undefined" ? null : localStorage;
  } catch {
    return null;
  }
};

export function saveLocalDraft(
  pageId: number,
  snapshot: Snapshot,
  baseRevision: number,
  storage: StorageLike | null = safeStorage(),
  now = new Date()
): boolean {
  if (!storage) return false;
  try {
    storage.setItem(
      keyFor(pageId),
      JSON.stringify({
        pageId,
        schemaVersion: SCHEMA_VERSION,
        baseRevision,
        savedAt: now.toISOString(),
        layout: snapshot.layout,
        theme: snapshot.theme,
      })
    );
    return true;
  } catch {
    return false;
  }
}

/** Reads and validates a draft. Corrupt or mismatched envelopes are discarded, never trusted. */
export function readLocalDraft(
  pageId: number,
  storage: StorageLike | null = safeStorage()
): LocalDraft | null {
  if (!storage) return null;
  let raw: string | null;
  try {
    raw = storage.getItem(keyFor(pageId));
  } catch {
    return null;
  }
  if (!raw) return null;
  try {
    const env = Envelope.parse(JSON.parse(raw));
    if (env.pageId !== pageId || env.schemaVersion > SCHEMA_VERSION)
      throw new Error("mismatch");
    const layout = parseLayout(env.layout);
    const theme = parseTheme(env.theme);
    if (!layout.ok || !theme.ok) throw new Error("invalid");
    return {
      pageId,
      baseRevision: env.baseRevision,
      savedAt: env.savedAt,
      snapshot: { layout: layout.value, theme: theme.value },
    };
  } catch {
    clearLocalDraft(pageId, storage);
    return null;
  }
}

export function clearLocalDraft(
  pageId: number,
  storage: StorageLike | null = safeStorage()
) {
  try {
    storage?.removeItem(keyFor(pageId));
  } catch {
    /* ignore */
  }
}
