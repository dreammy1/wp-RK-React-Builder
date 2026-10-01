import {
  useCallback,
  useEffect,
  useMemo,
  useReducer,
  useRef,
  useState,
} from "react";
import { api, type LoadedPage } from "@/lib/api/builder";
import { ApiError, isApiError, type ApiErrorKind } from "@/lib/api/errors";
import { ContentStore } from "@/lib/api/contentStore";
import { LayoutSchema } from "@/lib/schema/layout";
import { ThemeSchema, DEFAULT_THEME } from "@/lib/schema/theme";
import type { Issue } from "@/lib/schema/migrate";
import { registry } from "@/blocks/registry";
import { friendlyMessage } from "@/lib/schema/messages";
import { sendTelemetry } from "@/lib/telemetry";
import {
  clearLocalDraft,
  readLocalDraft,
  saveLocalDraft,
  type LocalDraft,
} from "./drafts";
import {
  initialState,
  isDirty,
  makeBlockId,
  reducer,
  serialize,
  type EditorState,
} from "./reducer";
import type { SaveStatus } from "./saveStatus";
import type { BlockType } from "@/lib/schema/primitives";

export type PageMeta = {
  id: number;
  title: string;
  slug: string;
  status: string;
};
export type LoadState =
  | { phase: "loading" }
  | {
      phase: "error";
      kind: ApiErrorKind;
      message: string;
      offlineDraft: LocalDraft | null;
    }
  | { phase: "ready" };

const EMPTY_STATE = initialState({
  layout: { version: 1, blocks: [] },
  theme: DEFAULT_THEME,
});

export function useEditorSession(pageId: number, demo: boolean) {
  const [load, setLoad] = useState<LoadState>({ phase: "loading" });
  const [state, dispatch] = useReducer(reducer, EMPTY_STATE);
  const [page, setPage] = useState<PageMeta | null>(null);
  const [serverRev, setServerRev] = useState(0);
  const [publishedRev, setPublishedRev] = useState<number | null>(null);
  const [serverTheme, setServerTheme] = useState(DEFAULT_THEME);
  const [caps, setCaps] = useState({ manageTheme: true, publish: true });
  const [status, setStatus] = useState<SaveStatus>("clean");
  const [issues, setIssues] = useState<Issue[]>([]);
  const [conflictRev, setConflictRev] = useState<number | null>(null);
  const [recovery, setRecovery] = useState<{
    draft: LocalDraft;
    server: LoadedPage;
  } | null>(null);
  const [offline, setOffline] = useState(false);
  const [notice, setNotice] = useState<string>("");
  const store = useMemo(() => new ContentStore(undefined, demo), [demo]);
  const stateRef = useRef(state);
  stateRef.current = state;
  const serverRevRef = useRef(serverRev);
  serverRevRef.current = serverRev;

  const dirty = isDirty(state);

  const applyLoaded = useCallback((p: LoadedPage) => {
    setPage({
      id: p.page.id,
      title: p.page.title,
      slug: p.page.slug,
      status: p.page.status,
    });
    setServerRev(p.revision);
    setPublishedRev(p.publishedRevision);
    setServerTheme(p.theme);
    setCaps(p.capabilities);
    dispatch({
      type: "replace",
      snapshot: { layout: p.layout, theme: p.theme },
      clean: true,
    });
    setIssues([]);
    setConflictRev(null);
    setStatus("clean");
  }, []);

  const loadPage = useCallback(async () => {
    setLoad({ phase: "loading" });
    try {
      const p = await api.loadPage(pageId);
      applyLoaded(p);
      const draft = readLocalDraft(pageId);
      if (
        draft &&
        serialize(draft.snapshot) !==
          serialize({ layout: p.layout, theme: p.theme })
      ) {
        setRecovery({ draft, server: p });
      } else if (draft) {
        clearLocalDraft(pageId);
      }
      if (p.migrated)
        setNotice(
          "This page used an older layout format and was upgraded in memory. Save to keep the upgrade."
        );
      setOffline(false);
      setLoad({ phase: "ready" });
    } catch (e) {
      const err = isApiError(e) ? e : new ApiError("server", String(e));
      sendTelemetry({ type: "load_failure", detail: `page: ${err.kind}` });
      setLoad({
        phase: "error",
        kind: err.kind,
        message: err.message,
        offlineDraft: err.kind === "network" ? readLocalDraft(pageId) : null,
      });
    }
  }, [pageId, applyLoaded]);

  useEffect(() => {
    void loadPage();
  }, [loadPage]);

  /** Opens a locally saved draft when WordPress is unreachable. */
  const openOffline = useCallback(
    (draft: LocalDraft) => {
      setPage({
        id: pageId,
        title: `Page ${pageId}`,
        slug: "",
        status: "unknown",
      });
      setServerRev(draft.baseRevision ?? 0);
      dispatch({ type: "replace", snapshot: draft.snapshot, clean: false });
      setOffline(true);
      setStatus("saved-local");
      setLoad({ phase: "ready" });
    },
    [pageId]
  );

  const resolveRecovery = useCallback(
    (choice: "restore" | "discard") => {
      if (!recovery) return;
      if (choice === "restore") {
        dispatch({
          type: "replace",
          snapshot: recovery.draft.snapshot,
          clean: false,
        });
        setStatus("dirty");
      } else {
        clearLocalDraft(pageId);
      }
      setRecovery(null);
    },
    [recovery, pageId]
  );

  // Keep the status chip in step with edits.
  useEffect(() => {
    if (load.phase !== "ready") return;
    setStatus(s => {
      if (s === "saving") return s;
      if (!dirty) return s === "saved" || s === "saved-local" ? s : "clean";
      return s === "clean" || s === "saved" ? "dirty" : s;
    });
  }, [dirty, load.phase]);

  // Local safety net: debounce dirty work onto this device.
  useEffect(() => {
    if (load.phase !== "ready" || !dirty) return;
    const t = setTimeout(
      () =>
        saveLocalDraft(
          pageId,
          { layout: state.layout, theme: state.theme },
          serverRev
        ),
      800
    );
    return () => clearTimeout(t);
  }, [state.layout, state.theme, dirty, load.phase, pageId, serverRev]);

  // Warn before closing with unsaved work.
  useEffect(() => {
    const h = (e: BeforeUnloadEvent) => {
      if (isDirty(stateRef.current)) {
        e.preventDefault();
      }
    };
    window.addEventListener("beforeunload", h);
    return () => window.removeEventListener("beforeunload", h);
  }, []);

  const failSave = useCallback(
    (e: unknown) => {
      const cur = stateRef.current;
      const local = () =>
        saveLocalDraft(
          pageId,
          { layout: cur.layout, theme: cur.theme },
          serverRev
        );
      if (!isApiError(e)) {
        setStatus("error");
        return;
      }
      switch (e.kind) {
        case "conflict":
          setConflictRev(e.extra.currentRevision ?? null);
          setStatus("conflict");
          local();
          break;
        case "unauthorized":
          local();
          setStatus("auth");
          break;
        case "forbidden":
          setStatus("forbidden");
          break;
        case "invalid_layout":
        case "invalid_theme":
          setIssues(e.extra.issues ?? [{ path: "", message: e.message }]);
          setStatus("invalid");
          break;
        case "network":
          setStatus(local() ? "saved-local" : "network");
          break;
        default:
          setStatus("error");
      }
    },
    [pageId, serverRev]
  );

  const save = useCallback(async (): Promise<boolean> => {
    const cur = stateRef.current;
    const layout = LayoutSchema.safeParse(cur.layout);
    const theme = ThemeSchema.safeParse(cur.theme);
    if (!layout.success || !theme.success) {
      const all = [
        ...(layout.success ? [] : layout.error.issues),
        ...(theme.success ? [] : theme.error.issues),
      ];
      setIssues(
        all.map(i => ({ path: i.path.join("."), message: friendlyMessage(i) }))
      );
      setStatus("invalid");
      return false;
    }
    setStatus("saving");
    setIssues([]);
    try {
      const themeChanged =
        JSON.stringify(cur.theme) !== JSON.stringify(serverTheme);
      const res = await api.saveLayout(pageId, {
        layout: layout.data,
        ...(themeChanged ? { theme: theme.data } : {}),
        expectedRevision: serverRev,
        status: "draft",
      });
      setServerRev(res.revision);
      if (themeChanged) setServerTheme(theme.data);
      dispatch({
        type: "markSaved",
        snapshot: { layout: cur.layout, theme: cur.theme },
      });
      clearLocalDraft(pageId);
      setOffline(false);
      setPage(p => (p ? { ...p, status: res.status } : p));
      setStatus("saved");
      return true;
    } catch (e) {
      failSave(e);
      return false;
    }
  }, [pageId, serverRev, serverTheme, failSave]);

  /** Conflict resolution: take the server copy, or keep mine and rebase onto the current revision. */
  const resolveConflict = useCallback(
    async (choice: "reload" | "reapply") => {
      if (choice === "reload") {
        clearLocalDraft(pageId);
        await loadPage();
        return;
      }
      try {
        const fresh = await api.loadPage(pageId);
        setServerRev(fresh.revision);
        setPublishedRev(fresh.publishedRevision);
        setServerTheme(fresh.theme);
        setConflictRev(null);
        setStatus("dirty");
        setNotice(
          `Your edits are kept and now based on revision ${fresh.revision}. Save to overwrite the other changes.`
        );
      } catch (e) {
        failSave(e);
      }
    },
    [pageId, loadPage, failSave]
  );

  const publish = useCallback(async () => {
    if (isDirty(stateRef.current) && !(await save())) return false;
    try {
      const res = await api.publish(pageId, serverRevRef.current);
      setServerRev(res.revision);
      setPublishedRev(res.publishedRevision);
      setPage(p => (p ? { ...p, status: res.status } : p));
      setNotice(
        `Published revision ${res.publishedRevision}. The public page refreshes within a minute.`
      );
      return true;
    } catch (e) {
      failSave(e);
      return false;
    }
  }, [pageId, save, failSave]);

  const unpublish = useCallback(async () => {
    try {
      const res = await api.unpublish(pageId);
      setServerRev(res.revision);
      setPage(p => (p ? { ...p, status: res.status } : p));
      setPublishedRev(null);
      setNotice("Page unpublished and returned to draft.");
    } catch (e) {
      failSave(e);
    }
  }, [pageId, failSave]);

  const actions = useMemo(
    () => ({
      add: (blockType: BlockType, index?: number) =>
        dispatch({ type: "add", blockType, index, id: makeBlockId(blockType) }),
      remove: (id: string) => dispatch({ type: "remove", id }),
      duplicate: (id: string) => {
        const b = stateRef.current.layout.blocks.find(x => x.id === id);
        if (b) dispatch({ type: "duplicate", id, newId: makeBlockId(b.type) });
      },
      move: (id: string, toIndex: number) =>
        dispatch({ type: "move", id, toIndex }),
      moveBy: (id: string, delta: number) =>
        dispatch({ type: "moveBy", id, delta }),
      patchProps: (id: string, patch: Record<string, unknown>) =>
        dispatch({ type: "patchProps", id, patch, now: Date.now() }),
      patchTheme: (patch: Partial<EditorState["theme"]>) =>
        dispatch({ type: "patchTheme", patch, now: Date.now() }),
      select: (id: string | null) => dispatch({ type: "select", id }),
      undo: () => dispatch({ type: "undo" }),
      redo: () => dispatch({ type: "redo" }),
    }),
    []
  );

  /** Per-block validation so the inspector and canvas can flag problems before the server does. */
  const blockErrors = useMemo(() => {
    const out = new Map<string, Record<string, string>>();
    for (const b of state.layout.blocks) {
      const r = registry[b.type].schema.safeParse(b.props);
      if (!r.success) {
        const m: Record<string, string> = {};
        for (const i of r.error.issues)
          m[String(i.path[0] ?? "")] ||= friendlyMessage(i);
        out.set(b.id, m);
      }
    }
    return out;
  }, [state.layout]);

  return {
    load,
    loadPage,
    openOffline,
    state,
    dirty,
    page,
    serverRev,
    publishedRev,
    caps,
    status,
    issues,
    conflictRev,
    recovery,
    resolveRecovery,
    offline,
    notice,
    clearNotice: () => setNotice(""),
    store,
    blockErrors,
    canUndo: state.past.length > 0,
    canRedo: state.future.length > 0,
    save,
    publish,
    unpublish,
    resolveConflict,
    actions,
    reloadAfterRestore: loadPage,
  };
}
