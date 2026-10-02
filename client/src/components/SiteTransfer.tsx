import { useRef, useState } from "react";
import { Download, Upload } from "lucide-react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import type { SiteImportOptions, SiteImportReport } from "@/lib/schema/api";
import { Modal } from "./Modal";
import { downloadJson } from "./editor/Dialogs";

const MAX_FILE = 8 * 1024 * 1024;

/** Download every builder page, the theme and the media they use as one JSON file. */
export function ExportSiteButton() {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  return (
    <>
      <button
        className="top-btn"
        disabled={busy}
        onClick={() => {
          setBusy(true);
          setError(null);
          api
            .exportSite()
            .then(bundle =>
              downloadJson(
                `rk-builder-site-${new Date().toISOString().slice(0, 10)}.json`,
                bundle
              )
            )
            .catch(e => setError(describeError(e)))
            .finally(() => setBusy(false));
        }}
      >
        <Download size={14} aria-hidden="true" />{" "}
        {busy ? "Exporting…" : "Export site"}
      </button>
      {error && (
        <span className="form-error" role="alert">
          {error}
        </span>
      )}
    </>
  );
}

type Phase =
  | { kind: "pick" }
  | { kind: "checking" }
  | { kind: "checked"; report: SiteImportReport }
  | { kind: "importing" }
  | { kind: "done"; report: SiteImportReport }
  | { kind: "error"; message: string };

function Summary({ r }: { r: SiteImportReport }) {
  return (
    <div className="import-report">
      <ul>
        <li>
          Pages: <strong>{r.pages.create}</strong> new,{" "}
          <strong>{r.pages.update}</strong> updated
          {r.pages.skipped.length > 0 && (
            <>
              , <strong>{r.pages.skipped.length}</strong> skipped
            </>
          )}
        </li>
        <li>
          Images: <strong>{r.media.imported}</strong> copied,{" "}
          <strong>{r.media.reused}</strong> already here
          {r.media.failed.length > 0 && (
            <>
              , <strong>{r.media.failed.length}</strong> failed
            </>
          )}
        </li>
        {r.reusables &&
          r.reusables.create + r.reusables.update + r.reusables.skipped.length >
            0 && (
            <li>
              Reusable blocks: <strong>{r.reusables.create}</strong> new,{" "}
              <strong>{r.reusables.update}</strong> updated
              {r.reusables.skipped.length > 0 &&
                `, ${r.reusables.skipped.length} skipped`}
            </li>
          )}
        {r.theme.included && (
          <li>
            Theme:{" "}
            {r.dryRun
              ? "will replace the current theme"
              : r.theme.applied
                ? "applied"
                : "not applied"}
          </li>
        )}
        {r.content.included > 0 && (
          <li>
            Services &amp; projects: {r.content.included} in the file
            {!r.dryRun &&
              ` (${r.content.created} new, ${r.content.updated} updated)`}
          </li>
        )}
      </ul>
      {r.pages.skipped.length > 0 && (
        <details open>
          <summary>Skipped pages</summary>
          <ul>
            {r.pages.skipped.map(s => (
              <li key={s.slug}>
                <code>{s.slug}</code> — {s.issues.join("; ")}
              </li>
            ))}
          </ul>
        </details>
      )}
      {r.media.failed.length > 0 && (
        <details>
          <summary>Images that could not be copied</summary>
          <ul>
            {r.media.failed.map(f => (
              <li key={f.url}>
                <code>{f.url}</code> — {f.reason}
              </li>
            ))}
          </ul>
        </details>
      )}
      {r.warnings.map(w => (
        <p key={w} className="muted">
          {w}
        </p>
      ))}
    </div>
  );
}

/** Choose an export file, check it (dry run), then import. Pages always arrive as drafts. */
export function ImportSiteDialog({
  onClose,
  onImported,
}: {
  onClose: () => void;
  onImported: () => void;
}) {
  const [phase, setPhase] = useState<Phase>({ kind: "pick" });
  const [bundle, setBundle] = useState<unknown>(null);
  const [fileName, setFileName] = useState("");
  const [opts, setOpts] = useState({
    theme: false,
    content: false,
    contentStatus: "draft" as SiteImportOptions["contentStatus"],
  });
  const fileRef = useRef<HTMLInputElement>(null);

  const run = (dryRun: boolean) => {
    setPhase({ kind: dryRun ? "checking" : "importing" });
    api
      .importSite(bundle, { ...opts, dryRun })
      .then(report => {
        setPhase({ kind: dryRun ? "checked" : "done", report });
        if (!dryRun) onImported();
      })
      .catch(e => setPhase({ kind: "error", message: describeError(e) }));
  };

  const onFile = (file: File | undefined) => {
    setBundle(null);
    setPhase({ kind: "pick" });
    if (!file) return;
    setFileName(file.name);
    if (file.size > MAX_FILE) {
      setPhase({ kind: "error", message: "That file is larger than 8 MB." });
      return;
    }
    file
      .text()
      .then(text => {
        const parsed: unknown = JSON.parse(text);
        setBundle(parsed);
      })
      .catch(() =>
        setPhase({ kind: "error", message: "That file is not valid JSON." })
      );
  };

  const busy = phase.kind === "checking" || phase.kind === "importing";
  return (
    <Modal title="Import site" onClose={onClose} wide dismissable={!busy}>
      <p className="muted">
        Import an export from RK Builder. Pages arrive as{" "}
        <strong>drafts</strong> (matching slugs are updated; live pages stay
        live until you publish). Images are copied into this site&apos;s media
        library.
      </p>
      <label className="field">
        <span>Export file (.json)</span>
        <input
          ref={fileRef}
          data-autofocus
          type="file"
          accept="application/json,.json"
          onChange={e => onFile(e.target.files?.[0])}
        />
      </label>
      <fieldset className="field" disabled={busy}>
        <legend>Also import</legend>
        <div className="field check">
          <label>
            <input
              type="checkbox"
              checked={opts.theme}
              onChange={e => setOpts({ ...opts, theme: e.target.checked })}
            />{" "}
            <span>
              The theme (colors, logo, header and footer) — replaces the current
              theme on every page
            </span>
          </label>
        </div>
        <div className="field check">
          <label>
            <input
              type="checkbox"
              checked={opts.content}
              onChange={e => setOpts({ ...opts, content: e.target.checked })}
            />{" "}
            <span>Services and portfolio projects (as drafts)</span>
          </label>
        </div>
      </fieldset>

      {phase.kind === "error" && (
        <p className="form-error" role="alert">
          {phase.message}
        </p>
      )}
      {(phase.kind === "checked" || phase.kind === "done") && (
        <div role="status" aria-live="polite">
          <h3>
            {phase.kind === "checked"
              ? "This is what will happen"
              : "Import finished"}
          </h3>
          <Summary r={phase.report} />
        </div>
      )}
      {busy && (
        <p className="muted" role="status">
          {phase.kind === "checking"
            ? "Checking the file…"
            : "Importing — copying images can take a minute…"}
        </p>
      )}
      <div className="dialog-actions">
        {phase.kind !== "done" && (
          <button
            className="top-btn"
            disabled={bundle == null || busy}
            onClick={() => run(true)}
          >
            Check file
          </button>
        )}
        {phase.kind === "checked" && (
          <button
            className="save-btn"
            disabled={busy}
            onClick={() => run(false)}
          >
            <Upload size={14} aria-hidden="true" /> Import now
          </button>
        )}
        <button className="top-btn" onClick={onClose} disabled={busy}>
          {phase.kind === "done" ? "Close" : "Cancel"}
        </button>
      </div>
      <span hidden>{fileName}</span>
    </Modal>
  );
}
