import { useEffect, useState } from "react";
import { Check, Eye, RotateCcw } from "lucide-react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import type { LocalDraft } from "@/lib/editor/drafts";
import type { RevisionSummary } from "@/lib/schema/api";
import type { LayoutDocument } from "@/lib/schema/layout";
import type { ThemeConfig } from "@/lib/schema/theme";
import { themeToCssVars, themeToDesignRules } from "@/lib/schema/theme";
import { LayoutRenderer } from "@/render/BlockRenderer";
import type { ContentStore } from "@/lib/api/contentStore";
import { ContentProvider } from "../ContentProvider";
import { Modal } from "../Modal";

const fmt = (iso: string) => new Date(iso).toLocaleString();

export function ConflictDialog({
  currentRevision,
  onChoose,
}: {
  currentRevision: number | null;
  onChoose: (c: "reload" | "reapply" | "download") => void;
}) {
  return (
    <Modal
      title="This page changed on the server"
      onClose={() => {}}
      dismissable={false}
    >
      <p>
        Someone else saved this page
        {currentRevision !== null
          ? ` (now at revision ${currentRevision})`
          : ""}{" "}
        while you were editing. Your edits are safe on this device.
      </p>
      <div className="dialog-actions stack">
        <button
          className="top-btn"
          data-autofocus
          onClick={() => onChoose("reload")}
        >
          Load the server version (discard my edits)
        </button>
        <button className="save-btn" onClick={() => onChoose("reapply")}>
          Keep my edits and rebase on the new revision
        </button>
        <button className="top-btn" onClick={() => onChoose("download")}>
          Download my version as JSON
        </button>
      </div>
    </Modal>
  );
}

export function RecoveryDialog({
  draft,
  serverRevision,
  onChoose,
}: {
  draft: LocalDraft;
  serverRevision: number;
  onChoose: (c: "restore" | "discard") => void;
}) {
  const stale =
    draft.baseRevision !== undefined && draft.baseRevision !== serverRevision;
  return (
    <Modal
      title="Unsaved draft found on this device"
      onClose={() => onChoose("discard")}
      dismissable={false}
    >
      <p>
        A local draft from <strong>{fmt(draft.savedAt)}</strong> differs from
        the version on WordPress (revision {serverRevision}).
      </p>
      {stale && (
        <p className="form-error">
          The page has changed on the server since this draft was made (it was
          based on revision {draft.baseRevision}). Restoring it will overwrite
          those changes when you save.
        </p>
      )}
      <div className="dialog-actions">
        <button className="top-btn" onClick={() => onChoose("discard")}>
          Use the server version
        </button>
        <button
          className="save-btn"
          data-autofocus
          onClick={() => onChoose("restore")}
        >
          Restore my local draft
        </button>
      </div>
    </Modal>
  );
}

export function PublishDialog({
  title,
  revision,
  blocks,
  dirty,
  publishedRevision,
  onConfirm,
  onClose,
  onUnpublish,
}: {
  title: string;
  revision: number;
  blocks: number;
  dirty: boolean;
  publishedRevision: number | null;
  onConfirm: () => Promise<boolean>;
  onClose: () => void;
  onUnpublish: () => Promise<void>;
}) {
  const [busy, setBusy] = useState(false);
  return (
    <Modal title="Publish this page" onClose={onClose}>
      <p>
        <strong>{title}</strong> will go live with {blocks} block
        {blocks === 1 ? "" : "s"}.{" "}
        {dirty
          ? "Your unsaved edits will be saved as a new draft revision first."
          : `Draft revision ${revision} will be published.`}
      </p>
      {publishedRevision !== null && (
        <p className="muted">Currently live: revision {publishedRevision}.</p>
      )}
      <div className="dialog-actions">
        {publishedRevision !== null && (
          <button
            className="top-btn"
            disabled={busy}
            onClick={async () => {
              setBusy(true);
              await onUnpublish();
              onClose();
            }}
          >
            Unpublish
          </button>
        )}
        <button className="top-btn" onClick={onClose}>
          Cancel
        </button>
        <button
          className="save-btn"
          data-autofocus
          disabled={busy}
          onClick={async () => {
            setBusy(true);
            const ok = await onConfirm();
            setBusy(false);
            if (ok) onClose();
          }}
        >
          <Check size={14} aria-hidden="true" /> Publish now
        </button>
      </div>
    </Modal>
  );
}

export function RevisionsDialog({
  pageId,
  theme,
  store,
  currentRevision,
  dirty,
  onRestored,
  onClose,
}: {
  pageId: number;
  theme: ThemeConfig;
  store: ContentStore;
  currentRevision: number;
  dirty: boolean;
  onRestored: () => void;
  onClose: () => void;
}) {
  const [list, setList] = useState<RevisionSummary[] | null>(null);
  const [error, setError] = useState("");
  const [preview, setPreview] = useState<{
    rev: RevisionSummary;
    layout: LayoutDocument;
  } | null>(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    api
      .listRevisions(pageId)
      .then(r => setList(r.revisions))
      .catch(e => setError(describeError(e)));
  }, [pageId]);
  const open = async (rev: RevisionSummary) => {
    try {
      const r = await api.getRevision(pageId, rev.id);
      setPreview({ rev, layout: r.layout });
      setError("");
    } catch (e) {
      setError(describeError(e));
    }
  };
  const restore = async (rev: RevisionSummary) => {
    if (
      dirty &&
      !window.confirm(
        "You have unsaved edits. Restoring will discard them. Continue?"
      )
    )
      return;
    setBusy(true);
    try {
      await api.restoreRevision(pageId, rev.id, currentRevision);
      onRestored();
      onClose();
    } catch (e) {
      setError(describeError(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal title="Revision history" onClose={onClose} wide>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      {!list && !error && <p className="muted">Loading revisions…</p>}
      {list && list.length === 0 && (
        <p className="muted">No revisions yet. Saving creates the first one.</p>
      )}
      <div className="revisions">
        <ul className="rev-list">
          {list?.map(r => (
            <li key={r.id} className={preview?.rev.id === r.id ? "active" : ""}>
              <div>
                <strong>Revision {r.id}</strong>{" "}
                <span className="badge">{r.kind}</span>
                <br />
                <small>
                  {fmt(r.savedAt)} · {r.author} · {r.blocks} blocks
                </small>
              </div>
              <div className="rev-actions">
                <button
                  className="top-btn"
                  onClick={() => open(r)}
                  aria-label={`Preview revision ${r.id}`}
                >
                  <Eye size={13} aria-hidden="true" /> Preview
                </button>
                <button
                  className="top-btn"
                  disabled={busy}
                  onClick={() => restore(r)}
                  aria-label={`Restore revision ${r.id}`}
                >
                  <RotateCcw size={13} aria-hidden="true" /> Restore
                </button>
              </div>
            </li>
          ))}
        </ul>
        {preview && (
          <div
            className="rev-preview"
            aria-label={`Preview of revision ${preview.rev.id}`}
          >
            <div
              className="site-root"
              style={themeToCssVars(theme) as React.CSSProperties}
            >
              <style>{themeToDesignRules(theme)}</style>
              <ContentProvider store={store}>
                <LayoutRenderer layout={preview.layout} mode="editor" />
              </ContentProvider>
            </div>
          </div>
        )}
      </div>
      <p className="muted">
        Restoring creates a new revision; nothing is deleted. The last 20
        revisions are kept.
      </p>
    </Modal>
  );
}

export function ExportDialog({
  layout,
  theme,
  onClose,
}: {
  layout: LayoutDocument;
  theme: ThemeConfig;
  onClose: () => void;
}) {
  const json = JSON.stringify({ layout, theme }, null, 2);
  return (
    <Modal title="Document payload" onClose={onClose} wide>
      <textarea
        className="json-pre"
        readOnly
        rows={18}
        aria-label="Layout and theme JSON"
        value={json}
      />
      <div className="dialog-actions">
        <button
          className="save-btn"
          onClick={() => navigator.clipboard?.writeText(json)}
        >
          Copy JSON
        </button>
      </div>
    </Modal>
  );
}

export function downloadBlob(name: string, blob: Blob) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = name;
  a.click();
  URL.revokeObjectURL(url);
}

export function downloadJson(name: string, data: unknown) {
  const url = URL.createObjectURL(
    new Blob([JSON.stringify(data, null, 2)], { type: "application/json" })
  );
  const a = document.createElement("a");
  a.href = url;
  a.download = name;
  a.click();
  URL.revokeObjectURL(url);
}
