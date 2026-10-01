import { useCallback, useEffect, useRef, useState } from "react";
import {
  AlertCircle,
  ArrowLeft,
  Download,
  ExternalLink,
  Eye,
  History,
  Layers3,
  Palette,
  Redo2,
  RotateCcw,
  Save,
  Send,
  Undo2,
} from "lucide-react";
import { registry } from "@/blocks/registry";
import { api } from "@/lib/api/builder";
import { getApiConfig } from "@/lib/api/http";
import { describeError } from "@/lib/api/errors";
import { pageHref } from "@/lib/router";
import { useEditorSession } from "@/lib/editor/useEditorSession";
import { STATUS_LABEL, STATUS_TONE } from "@/lib/editor/saveStatus";
import { themeToCssVars } from "@/lib/schema/theme";
import { LIMITS, type BlockType } from "@/lib/schema/primitives";
import { LayoutRenderer } from "@/render/BlockRenderer";
import { ContentProvider } from "../ContentProvider";
import { LoginForm } from "../Login";
import { Modal } from "../Modal";
import { Canvas } from "./Canvas";
import {
  ConflictDialog,
  ExportDialog,
  PublishDialog,
  RecoveryDialog,
  RevisionsDialog,
  downloadJson,
} from "./Dialogs";
import { Inspector } from "./Inspector";
import { Palette as BlockPalette } from "./Palette";
import { ThemePanel } from "./ThemePanel";

type Dialog = null | "export" | "revisions" | "publish";

export function EditorPage({
  pageId,
  demo,
  navigate,
}: {
  pageId: number;
  demo: boolean;
  navigate: (to: string) => void;
}) {
  const s = useEditorSession(pageId, demo);
  const [mode, setMode] = useState<"edit" | "preview">("edit");
  const [tab, setTab] = useState<"block" | "theme">("block");
  const [dialog, setDialog] = useState<Dialog>(null);
  const [dragType, setDragType] = useState<BlockType | null>(null);
  const [toast, setToast] = useState<{
    text: string;
    undo?: () => void;
  } | null>(null);
  const toastTimer = useRef<ReturnType<typeof setTimeout>>(undefined);
  const [previewError, setPreviewError] = useState("");
  const { state, actions } = s;
  const selected = state.layout.blocks.find(b => b.id === state.selectedId);

  const showToast = useCallback((text: string, undo?: () => void) => {
    setToast({ text, undo });
    clearTimeout(toastTimer.current);
    toastTimer.current = setTimeout(() => setToast(null), 7000);
  }, []);

  const remove = (id: string) => {
    const b = state.layout.blocks.find(x => x.id === id);
    actions.remove(id);
    showToast(`${b ? registry[b.type].label : "Block"} deleted`, actions.undo);
  };

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const mod = e.metaKey || e.ctrlKey;
      if (!mod) return;
      const k = e.key.toLowerCase();
      if (k === "s") {
        e.preventDefault();
        void s.save();
      } else if (k === "z" && !isTextTarget(e.target)) {
        e.preventDefault();
        if (e.shiftKey) actions.redo();
        else actions.undo();
      } else if (k === "y" && !isTextTarget(e.target)) {
        e.preventDefault();
        actions.redo();
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  });

  const guardedBack = () => {
    if (
      s.dirty &&
      !window.confirm(
        "You have unsaved changes on this page. Leave anyway? (A copy is kept on this device.)"
      )
    )
      return;
    navigate(pageHref());
  };

  const openDraftPreview = async () => {
    setPreviewError("");
    if (s.dirty && !(await s.save())) return;
    try {
      const t = await api.previewToken(pageId);
      const base = api.publicSiteUrl().replace(/\/$/, "");
      window.open(
        `${base}/preview/${encodeURIComponent(s.page?.slug ?? "")}?token=${encodeURIComponent(t.token)}`,
        "_blank",
        "noopener"
      );
    } catch (e) {
      setPreviewError(describeError(e));
    }
  };

  if (s.load.phase === "loading")
    return (
      <Centered>
        <p role="status">Loading page {pageId}…</p>
      </Centered>
    );
  if (s.load.phase === "error") {
    const l = s.load;
    const titles = {
      unauthorized: "Sign in required",
      forbidden: "You can’t edit this page",
      not_found: "Page not found",
      network: "Can’t reach WordPress",
      invalid_response: "The server sent invalid data",
    } as Record<string, string>;
    return (
      <Centered>
        <div className="card-panel" role="alert">
          <h1>{titles[l.kind] ?? "Could not load the page"}</h1>
          <p className="muted">
            {l.kind === "invalid_response"
              ? l.message
              : describeError({
                  kind: l.kind,
                  name: "ApiError",
                  message: l.message,
                } as never)}
          </p>
          {l.kind === "unauthorized" && getApiConfig().mode === "proxy" && (
            <LoginForm onDone={s.loadPage} />
          )}
          {l.kind === "unauthorized" && getApiConfig().mode === "nonce" && (
            <p>Reload this page after signing in to WordPress.</p>
          )}
          {l.offlineDraft && (
            <div className="notice warn">
              An offline draft from{" "}
              {new Date(l.offlineDraft.savedAt).toLocaleString()} is available.{" "}
              <button
                className="save-btn"
                onClick={() => s.openOffline(l.offlineDraft!)}
              >
                Open offline draft
              </button>
            </div>
          )}
          <div className="dialog-actions">
            <button className="top-btn" onClick={() => navigate(pageHref())}>
              All pages
            </button>
            <button className="save-btn" onClick={s.loadPage}>
              Try again
            </button>
          </div>
        </div>
      </Centered>
    );
  }

  const tone = STATUS_TONE[s.status];
  const invalidCount = s.blockErrors.size;
  const full = state.layout.blocks.length >= LIMITS.maxBlocks;
  const themeVars = themeToCssVars(state.theme) as React.CSSProperties;
  const isPublished = s.page?.status === "publish";

  return (
    <div className="app-shell">
      <header className="topbar">
        <button
          className="icon-btn"
          onClick={guardedBack}
          aria-label="Back to all pages"
        >
          <ArrowLeft size={15} aria-hidden="true" />
        </button>
        <div className="doc-meta">
          <strong className="doc-title">{s.page?.title}</strong>
          {s.page?.slug && <span>/{s.page.slug}</span>}
          <span className={`badge ${s.page?.status}`}>
            {s.page?.status === "publish" ? "published" : s.page?.status}
          </span>
          <span>
            rev {s.serverRev}
            {s.publishedRev !== null ? ` · live ${s.publishedRev}` : ""}
          </span>
          {demo && <span className="badge warn">demo content</span>}
        </div>
        <div className="top-actions">
          <div className="mode-switch" role="group" aria-label="Editor mode">
            <button
              className={mode === "edit" ? "active" : ""}
              aria-pressed={mode === "edit"}
              onClick={() => setMode("edit")}
            >
              <Layers3 size={14} aria-hidden="true" /> Edit
            </button>
            <button
              className={mode === "preview" ? "active" : ""}
              aria-pressed={mode === "preview"}
              onClick={() => {
                setMode("preview");
                actions.select(null);
              }}
            >
              <Eye size={14} aria-hidden="true" /> Preview
            </button>
          </div>
          <button
            className="icon-btn"
            disabled={!s.canUndo}
            onClick={actions.undo}
            aria-label="Undo"
          >
            <Undo2 size={15} aria-hidden="true" />
          </button>
          <button
            className="icon-btn"
            disabled={!s.canRedo}
            onClick={actions.redo}
            aria-label="Redo"
          >
            <Redo2 size={15} aria-hidden="true" />
          </button>
          <button className="top-btn" onClick={() => setDialog("revisions")}>
            <History size={14} aria-hidden="true" /> History
          </button>
          <button className="top-btn" onClick={() => setDialog("export")}>
            <Download size={14} aria-hidden="true" /> Export
          </button>
          <button
            className="top-btn"
            onClick={openDraftPreview}
            disabled={s.offline}
          >
            <ExternalLink size={14} aria-hidden="true" /> Preview link
          </button>
          <button
            className="save-btn"
            onClick={() => void s.save()}
            disabled={
              s.status === "saving" || (!s.dirty && s.status !== "auth")
            }
          >
            <Save size={14} aria-hidden="true" />{" "}
            {s.status === "saving" ? "Saving" : "Save draft"}
          </button>
          <button
            className="top-btn publish"
            onClick={() => setDialog("publish")}
            disabled={!s.caps.publish || s.offline || invalidCount > 0}
          >
            <Send size={14} aria-hidden="true" />{" "}
            {isPublished ? "Update live page" : "Publish"}
          </button>
        </div>
      </header>

      <div className={`statusbar ${tone}`}>
        <span role="status" aria-live="polite" data-testid="save-status">
          {STATUS_LABEL[s.status]}
        </span>
        {invalidCount > 0 && (
          <span>
            {" "}
            · {invalidCount} block{invalidCount === 1 ? "" : "s"} need attention
          </span>
        )}
        {s.offline && <span> · Offline draft</span>}
      </div>
      {s.status === "invalid" && s.issues.length > 0 && (
        <div className="notice error inline" role="alert">
          <AlertCircle size={15} aria-hidden="true" />{" "}
          <span>
            {s.issues
              .slice(0, 3)
              .map(i => `${i.path || "document"}: ${i.message}`)
              .join(" · ")}
          </span>
        </div>
      )}
      {(s.notice || previewError) && (
        <div className="notice info inline" role="status">
          {previewError || s.notice}{" "}
          <button
            className="top-btn"
            onClick={() => {
              s.clearNotice();
              setPreviewError("");
            }}
          >
            Dismiss
          </button>
        </div>
      )}

      <ContentProvider store={s.store}>
        {mode === "preview" ? (
          <main className="preview-stage">
            <div className="preview-toolbar">
              <span>
                <Eye size={14} aria-hidden="true" /> Preview (unsaved edits
                included)
              </span>
              <button onClick={() => setMode("edit")}>
                Return to editor <RotateCcw size={14} aria-hidden="true" />
              </button>
            </div>
            <div className="site-root preview-canvas" style={themeVars}>
              <LayoutRenderer layout={state.layout} mode="public" />
              <footer className="site-footer">
                <span>{s.page?.title}</span>
                <span>Layout v{state.layout.version}</span>
              </footer>
            </div>
          </main>
        ) : (
          <div className="workspace">
            <BlockPalette
              full={full}
              onDragStart={setDragType}
              onAdd={t => {
                const i = state.layout.blocks.findIndex(
                  b => b.id === state.selectedId
                );
                actions.add(t, i >= 0 ? i + 1 : undefined);
              }}
            />
            <main
              className="canvas-area"
              aria-label="Page canvas"
              style={themeVars}
            >
              <div className="canvas-topline">
                <div>
                  <span className="eyebrow">
                    canvas / {s.page?.slug || "page"}
                  </span>
                  <h1>{s.page?.title}</h1>
                </div>
                <div className="canvas-stats">
                  <span>
                    <b>{state.layout.blocks.length}</b> blocks
                  </span>
                  <span>
                    <b>{s.serverRev}</b> revision
                  </span>
                </div>
              </div>
              <div className="canvas-frame">
                <Canvas
                  layout={state.layout}
                  selectedId={state.selectedId}
                  errors={s.blockErrors}
                  dragType={dragType}
                  onDragEnd={() => setDragType(null)}
                  onSelect={id => {
                    actions.select(id);
                    setTab("block");
                  }}
                  onAdd={(t, i) => actions.add(t, i)}
                  onMove={actions.move}
                  onMoveBy={actions.moveBy}
                  onDuplicate={actions.duplicate}
                  onRemove={remove}
                />
              </div>
              <div className="canvas-caption">
                <span>Live canvas</span>
                <span>Drag blocks, or use the arrow buttons to reorder</span>
              </div>
            </main>
            <aside className="inspector" aria-label="Inspector">
              <div className="inspector-tabs" role="tablist">
                <button
                  role="tab"
                  aria-selected={tab === "block"}
                  className={tab === "block" ? "active" : ""}
                  onClick={() => setTab("block")}
                >
                  Inspector
                </button>
                <button
                  role="tab"
                  aria-selected={tab === "theme"}
                  className={tab === "theme" ? "active" : ""}
                  onClick={() => setTab("theme")}
                >
                  <Palette size={14} aria-hidden="true" /> Theme
                </button>
              </div>
              {tab === "block" ? (
                <Inspector
                  block={selected}
                  errors={(selected && s.blockErrors.get(selected.id)) || {}}
                  onPatch={p => selected && actions.patchProps(selected.id, p)}
                  onDelete={() => selected && remove(selected.id)}
                />
              ) : (
                <ThemePanel
                  theme={state.theme}
                  canEdit={s.caps.manageTheme}
                  onPatch={actions.patchTheme}
                />
              )}
            </aside>
          </div>
        )}
      </ContentProvider>

      {toast && (
        <div className="toast" role="status">
          {toast.text}
          {toast.undo && (
            <button
              onClick={() => {
                toast.undo?.();
                setToast(null);
              }}
            >
              Undo
            </button>
          )}
        </div>
      )}

      {dialog === "export" && (
        <ExportDialog
          layout={state.layout}
          theme={state.theme}
          onClose={() => setDialog(null)}
        />
      )}
      {dialog === "revisions" && (
        <RevisionsDialog
          pageId={pageId}
          theme={state.theme}
          store={s.store}
          currentRevision={s.serverRev}
          dirty={s.dirty}
          onRestored={s.reloadAfterRestore}
          onClose={() => setDialog(null)}
        />
      )}
      {dialog === "publish" && (
        <PublishDialog
          title={s.page?.title ?? ""}
          revision={s.serverRev}
          blocks={state.layout.blocks.length}
          dirty={s.dirty}
          publishedRevision={s.publishedRev}
          onConfirm={s.publish}
          onUnpublish={s.unpublish}
          onClose={() => setDialog(null)}
        />
      )}
      {s.recovery && (
        <RecoveryDialog
          draft={s.recovery.draft}
          serverRevision={s.recovery.server.revision}
          onChoose={s.resolveRecovery}
        />
      )}
      {s.status === "conflict" && (
        <ConflictDialog
          currentRevision={s.conflictRev}
          onChoose={c =>
            c === "download"
              ? downloadJson(`page-${pageId}-my-version.json`, {
                  layout: state.layout,
                  theme: state.theme,
                })
              : void s.resolveConflict(c)
          }
        />
      )}
      {s.status === "auth" && (
        <Modal
          title="Your session expired"
          onClose={() => {}}
          dismissable={false}
        >
          <p>Your edits are kept on this device. Sign in again to save them.</p>
          {getApiConfig().mode === "proxy" ? (
            <LoginForm onDone={() => void s.save()} />
          ) : (
            <p>Reload this page after signing in to WordPress.</p>
          )}
        </Modal>
      )}
    </div>
  );
}

const isTextTarget = (t: EventTarget | null) =>
  t instanceof HTMLElement &&
  (t.tagName === "INPUT" || t.tagName === "TEXTAREA" || t.isContentEditable);
const Centered = ({ children }: { children: React.ReactNode }) => (
  <main className="center-screen">{children}</main>
);
