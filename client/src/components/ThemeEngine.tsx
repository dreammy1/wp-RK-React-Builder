import { useCallback, useEffect, useState } from "react";
import { Download, Package, Trash2, Upload } from "lucide-react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import type {
  ThemeInstallOptions,
  ThemeInstallReport,
  ThemeSummary,
  UndoSummary,
} from "@/lib/schema/api";
import { SubPage } from "./SubPage";
import { Summary } from "./SiteTransfer";
import { downloadBlob, downloadJson } from "./editor/Dialogs";

const MAX_FILE = 8 * 1024 * 1024;

type View =
  | { kind: "library" }
  | { kind: "install"; theme: ThemeSummary }
  | { kind: "done"; theme: ThemeSummary; report: ThemeInstallReport };

const fmtDate = (iso: string) => {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? "" : d.toLocaleDateString();
};

function Toggle({
  label,
  hint,
  checked,
  disabled,
  onChange,
}: {
  label: string;
  hint?: string;
  checked: boolean;
  disabled?: boolean;
  onChange: (v: boolean) => void;
}) {
  return (
    <div className="field check">
      <label>
        <input
          type="checkbox"
          checked={checked}
          disabled={disabled}
          onChange={e => onChange(e.target.checked)}
        />{" "}
        <span>
          {label}
          {hint && <small className="muted"> {hint}</small>}
        </span>
      </label>
    </div>
  );
}

function hiddenNote(r: ThemeInstallReport, future: boolean) {
  const h = r.hidden;
  if (!h || h.pages + h.templates + h.posts === 0) return null;
  const parts = [
    h.pages > 0 && `${h.pages} page${h.pages === 1 ? "" : "s"}`,
    h.templates > 0 && `${h.templates} template${h.templates === 1 ? "" : "s"}`,
    h.posts > 0 && `${h.posts} content item${h.posts === 1 ? "" : "s"}`,
  ].filter(Boolean);
  return `${future ? "Will hide" : "Hid"} ${parts.join(", ")} of “${h.from}” (kept as drafts, not deleted).`;
}

type Row = { label: string; find: string; with: string };

function Mode({
  checked,
  title,
  text,
  onPick,
}: {
  checked: boolean;
  title: string;
  text: string;
  onPick: () => void;
}) {
  return (
    <label className={`kind-card${checked ? " on" : ""}`}>
      <input
        type="radio"
        name="install-mode"
        checked={checked}
        onChange={onPick}
      />
      <strong>{title}</strong>
      <span className="muted">{text}</span>
    </label>
  );
}

function InstallPanel({
  theme,
  activeName,
  onBack,
  onDone,
}: {
  theme: ThemeSummary;
  activeName: string;
  onBack: () => void;
  onDone: (r: ThemeInstallReport) => void;
}) {
  const [step, setStep] = useState<1 | 2 | 3>(1);
  const [design, setDesign] = useState(false);
  const [o, setO] = useState<ThemeInstallOptions>({
    dryRun: false,
    theme: true,
    content: true,
    publish: true,
    frontPage: true,
    settings: true,
    redirects: false,
    siteInfo: true,
    switch: true,
  });
  const [rows, setRows] = useState<Row[]>([]);
  const [phase, setPhase] = useState<
    "idle" | "checking" | "installing" | { error: string }
  >("idle");
  const [check, setCheck] = useState<ThemeInstallReport | null>(null);
  const busy = phase === "checking" || phase === "installing";

  const options = (dryRun: boolean): ThemeInstallOptions => ({
    ...o,
    dryRun,
    pages: !design,
    content: o.content && !design,
    replace: rows
      .filter(r => r.find.trim() !== "" && r.with.trim() !== "")
      .map(r => ({ find: r.find.trim(), with: r.with.trim() })),
  });

  const run = (dryRun: boolean, then?: (r: ThemeInstallReport) => void) => {
    setPhase(dryRun ? "checking" : "installing");
    api
      .installTheme(theme.slug, options(dryRun))
      .then(r => {
        setPhase("idle");
        if (then) then(r);
        else if (dryRun) setCheck(r);
        else onDone(r);
      })
      .catch(e => setPhase({ error: describeError(e) }));
  };

  const toBusiness = () => {
    setStep(2);
    if (rows.length === 0)
      run(true, r =>
        setRows(
          (r.suggest ?? []).map(s => ({
            label: s.label,
            find: s.find,
            with: "",
          }))
        )
      );
  };
  const toReview = () => {
    setStep(3);
    setCheck(null);
    run(true);
  };
  const setRow = (i: number, patch: Partial<Row>) =>
    setRows(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)));

  return (
    <>
      <p className="muted">
        Step {step} of 3 · <strong>{theme.name}</strong> {theme.version}
        {theme.kit && " (kit: the pictures are inside)"}
      </p>
      {step === 1 && (
        <>
          <fieldset className="field kind-pick" disabled={busy}>
            <legend>What do you want?</legend>
            <Mode
              checked={!design}
              title="Everything"
              text={`${theme.pages} page${theme.pages === 1 ? "" : "s"}, demo content, design and templates. Pages at the same address are replaced.`}
              onPick={() => setDesign(false)}
            />
            <Mode
              checked={design}
              title="Design only"
              text="Colors, fonts, header, footer, templates and blocks. No pages and no demo content."
              onPick={() => setDesign(true)}
            />
          </fieldset>
          <fieldset className="field" disabled={busy}>
            <legend>Include</legend>
            <Toggle
              label="Theme settings"
              hint="colors, fonts, logo, header and footer"
              checked={o.theme}
              onChange={v => setO({ ...o, theme: v })}
            />
            <Toggle
              label="Layout settings"
              hint="content width and side spacing"
              checked={o.settings ?? false}
              onChange={v => setO({ ...o, settings: v })}
            />
            <Toggle
              label="Site name and tagline"
              hint="the ones in the kit, or your own from the next step"
              checked={o.siteInfo ?? false}
              onChange={v => setO({ ...o, siteInfo: v })}
            />
            <Toggle
              label="Redirects"
              hint="adds the kit's rules to yours"
              checked={o.redirects ?? false}
              onChange={v => setO({ ...o, redirects: v })}
            />
            {activeName && (
              <Toggle
                label={`Switch from “${activeName}”`}
                hint="hides its pages, templates and content (nothing is deleted)"
                checked={o.switch ?? false}
                onChange={v => setO({ ...o, switch: v })}
              />
            )}
            <Toggle
              label="Publish right away"
              hint="otherwise pages arrive as drafts"
              checked={o.publish}
              disabled={design}
              onChange={v =>
                setO({ ...o, publish: v, frontPage: v && o.frontPage })
              }
            />
            <Toggle
              label="Use its Home page as the site front page"
              checked={o.frontPage && o.publish && !design}
              disabled={!o.publish || design}
              onChange={v => setO({ ...o, frontPage: v })}
            />
          </fieldset>
        </>
      )}
      {step === 2 && (
        <>
          <p>
            <strong>Your business.</strong> The kit is written for a demo
            business. Type your own details next to each one and they replace
            the demo&apos;s everywhere (text, links, SEO). Leave a box empty to
            keep the kit&apos;s wording.
          </p>
          {phase === "checking" && <p className="muted">Reading the kit…</p>}
          <div className="theme-form">
            {rows.map((r, i) => (
              <div key={i} className="field theme-wide">
                <label htmlFor={`rep-${i}`}>
                  <span>
                    {r.label || "Replace"}{" "}
                    <small className="muted">
                      {r.label ? `(kit: ${r.find})` : ""}
                    </small>
                  </span>
                </label>
                {!r.label && (
                  <input
                    type="text"
                    aria-label="Text to find"
                    placeholder="Text in the kit"
                    maxLength={200}
                    value={r.find}
                    onChange={e => setRow(i, { find: e.target.value })}
                  />
                )}
                <input
                  id={`rep-${i}`}
                  type="text"
                  maxLength={300}
                  value={r.with}
                  placeholder="Your value"
                  onChange={e => setRow(i, { with: e.target.value })}
                />
              </div>
            ))}
          </div>
          {rows.length < 10 && (
            <button
              className="top-btn"
              disabled={busy}
              onClick={() =>
                setRows([...rows, { label: "", find: "", with: "" }])
              }
            >
              Add another replacement
            </button>
          )}
        </>
      )}
      {step === 3 && (
        <div role="status" aria-live="polite">
          <h3>This is what will happen</h3>
          {check && hiddenNote(check, true) && <p>{hiddenNote(check, true)}</p>}
          {check && <Summary r={check} />}
          {phase === "checking" && <p className="muted">Checking…</p>}
          <p className="muted">
            Before anything changes, the site is saved so you can{" "}
            <strong>undo this install</strong> with one click. Pictures that are
            copied stay in your Media library.
          </p>
        </div>
      )}
      {typeof phase === "object" && (
        <p className="form-error" role="alert">
          {phase.error}
        </p>
      )}
      {phase === "installing" && (
        <p className="muted" role="status">
          Installing — copying images can take a minute…
        </p>
      )}
      <div className="dialog-actions">
        <button
          className="top-btn"
          disabled={busy}
          onClick={() => (step === 1 ? onBack() : setStep(step === 2 ? 1 : 2))}
        >
          Back
        </button>
        {step === 1 && (
          <button className="save-btn" disabled={busy} onClick={toBusiness}>
            Next
          </button>
        )}
        {step === 2 && (
          <button className="save-btn" disabled={busy} onClick={toReview}>
            Next
          </button>
        )}
        {step === 3 && (
          <button
            className="save-btn"
            disabled={busy || check === null}
            onClick={() => run(false)}
          >
            Install theme
          </button>
        )}
      </div>
    </>
  );
}

/** The theme library as a dialog. */
export function ThemeEngineDialog({
  onClose,
  onInstalled,
}: {
  onClose: () => void;
  onInstalled: () => void;
}) {
  return (
    <SubPage title="Theme engine" onClose={onClose} wide>
      <ThemeEngine onInstalled={onInstalled} onClose={onClose} />
    </SubPage>
  );
}

/** The theme library: save this site as a theme, install one with a click, export and import theme files. */
export function ThemeEngine({
  onInstalled,
  onClose,
}: {
  onInstalled: () => void;
  onClose?: () => void;
}) {
  const [items, setItems] = useState<ThemeSummary[] | null>(null);
  const [undo, setUndo] = useState<UndoSummary | null>(null);
  const [view, setView] = useState<View>({ kind: "library" });
  const [error, setError] = useState("");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [form, setForm] = useState({
    name: "",
    description: "",
    version: "1.0.0",
    author: "",
    industry: "",
    license: "",
    demo: "",
  });

  const load = useCallback(() => {
    api
      .listThemes()
      .then(r => {
        setItems(r.items);
        setUndo(r.undo ?? null);
      })
      .catch(e => {
        setItems([]);
        setError(describeError(e));
      });
  }, []);
  useEffect(load, [load]);

  const guard = (fn: () => Promise<void>) => {
    setError("");
    setNote("");
    setBusy(true);
    fn()
      .catch(e => setError(describeError(e)))
      .finally(() => setBusy(false));
  };

  const save = () =>
    guard(async () => {
      const r = await api.captureTheme(form);
      setNote(
        `Saved “${r.theme.name}” ${r.theme.version} (${r.theme.pages} pages) to the library.`
      );
      setForm({ ...form, name: "" });
      load();
    });

  const downloadKit = () =>
    guard(async () => {
      const { blob, filename } = await api.exportKit(form);
      downloadBlob(filename, blob);
      setNote(
        `Downloaded ${filename} (${(blob.size / 1048576).toFixed(1)} MB). It holds every page, block, template, type, setting and image.`
      );
    });

  const onFile = (file: File | undefined) => {
    if (!file) return;
    if (/\.zip$/i.test(file.name)) {
      guard(async () => {
        const r = await api.uploadKit(file);
        setNote(
          `Added the kit “${r.theme.name}” (${r.theme.images ?? 0} images) to the library. Install it when you are ready.`
        );
        load();
      });
      return;
    }
    if (file.size > MAX_FILE) {
      setError("That file is larger than 8 MB.");
      return;
    }
    guard(async () => {
      let parsed: unknown;
      try {
        parsed = JSON.parse(await file.text());
      } catch {
        throw new Error("That file is not valid JSON.");
      }
      const r = await api.importTheme(parsed);
      setNote(
        `Added “${r.theme.name}” to the library. Install it when you are ready.`
      );
      load();
    });
  };

  const undoInstall = () => {
    if (
      !window.confirm(
        `Undo the install of “${undo?.name ?? "this theme"}”? Pages it added go to the Trash, pages and settings it changed are put back as they were, and anything it hid is shown again. Edits you made to those pages since then are lost.`
      )
    )
      return;
    guard(async () => {
      const r = await api.undoInstall();
      setView({ kind: "library" });
      setNote(
        `Undone: ${r.undone.restored} restored, ${r.undone.trashed} moved to the Trash, ${r.undone.shown} shown again.`
      );
      load();
      onInstalled();
    });
  };

  const remove = (t: ThemeSummary) => {
    if (
      !window.confirm(
        `Delete “${t.name}” from the library? Pages already installed are not affected.`
      )
    )
      return;
    guard(async () => {
      await api.deleteTheme(t.slug);
      load();
    });
  };

  const exportTheme = (t: ThemeSummary) =>
    guard(async () => {
      if (t.kit) {
        const { blob, filename } = await api.exportKitFile(t.slug);
        downloadBlob(filename, blob);
        return;
      }
      downloadJson(
        `rk-theme-${t.slug}-${t.version}.json`,
        await api.exportTheme(t.slug)
      );
    });

  return (
    <>
      {view.kind === "install" && (
        <InstallPanel
          theme={view.theme}
          activeName={
            items?.find(i => i.active && i.slug !== view.theme.slug)?.name ?? ""
          }
          onBack={() => setView({ kind: "library" })}
          onDone={report => {
            setView({ kind: "done", theme: view.theme, report });
            onInstalled();
            load();
          }}
        />
      )}
      {view.kind === "done" && (
        <div role="status" aria-live="polite">
          <p>
            <strong>{view.theme.name}</strong> is installed
            {view.report.published > 0 &&
              `: ${view.report.published} page${view.report.published === 1 ? "" : "s"} published`}
            {(view.report.publishedTemplates ?? 0) > 0 &&
              `, ${view.report.publishedTemplates} template${view.report.publishedTemplates === 1 ? "" : "s"} live`}
            {view.report.frontPage && ", Home is now the front page"}.
          </p>
          {hiddenNote(view.report, false) && (
            <p>{hiddenNote(view.report, false)}</p>
          )}
          <Summary r={view.report} />
          <p className="muted">
            The pages still use the demo&apos;s wording. Open{" "}
            <strong>AI &amp; MCP</strong> in the dashboard to have an AI
            assistant rewrite it for your business (saved as drafts for you to
            review).
          </p>
          {view.report.undo && (
            <p className="muted">
              Not what you wanted? <strong>Undo this install</strong> puts the
              site back as it was ({view.report.undo.counts.created} added,{" "}
              {view.report.undo.counts.changed} changed,{" "}
              {view.report.undo.counts.hidden} hidden).
            </p>
          )}
          <div className="dialog-actions">
            {view.report.undo && (
              <button className="top-btn" disabled={busy} onClick={undoInstall}>
                Undo this install
              </button>
            )}
            <button
              className="save-btn"
              onClick={() =>
                onClose ? onClose() : setView({ kind: "library" })
              }
            >
              Done
            </button>
          </div>
        </div>
      )}
      {view.kind === "library" && (
        <>
          <p className="muted">
            Package this whole site (pages, blocks, templates, content types,
            blog posts, images, colors, logo, header, footer and SEO) as a
            theme. Download it as a <strong>kit zip</strong> that carries its
            own pictures, install it on any site with one click, or share it.
            Tracking codes and API keys are never included.
          </p>
          {error && (
            <p className="form-error" role="alert">
              {error}
            </p>
          )}
          {undo && (
            <p className="notice info inline" role="status">
              Last install: <strong>{undo.name}</strong> ({undo.counts.created}{" "}
              added, {undo.counts.changed} changed, {undo.counts.hidden}{" "}
              hidden).{" "}
              <button className="top-btn" disabled={busy} onClick={undoInstall}>
                Undo
              </button>
            </p>
          )}
          {note && (
            <p className="notice info inline" role="status">
              {note}
            </p>
          )}

          <h3>Library</h3>
          {items === null && <p className="muted">Loading…</p>}
          {items?.length === 0 && (
            <p className="muted">
              No themes yet. Save this site below, or import a kit zip or theme
              file.
            </p>
          )}
          <ul className="theme-grid">
            {items?.map(t => (
              <li key={t.slug} className="theme-card">
                <div
                  className="theme-thumb"
                  style={
                    t.preview
                      ? { backgroundImage: `url("${t.preview}")` }
                      : undefined
                  }
                  aria-hidden="true"
                >
                  {!t.preview && <Package size={28} />}
                </div>
                <div className="theme-body">
                  <strong>{t.name}</strong>
                  <span className="muted">
                    {t.active && (
                      <strong className="theme-active">Active · </strong>
                    )}
                    {t.kit && <strong>Kit · </strong>}v{t.version}
                    {t.industry && ` · ${t.industry}`}
                    {t.author && ` · ${t.author}`}
                    {fmtDate(t.createdAt) && ` · ${fmtDate(t.createdAt)}`}
                  </span>
                  {t.description && <p>{t.description}</p>}
                  <span className="muted">
                    {t.pages} pages · {t.reusables} blocks ·{" "}
                    {t.kit ? (t.images ?? 0) : t.media} images
                    {(t.templates ?? 0) > 0 && ` · ${t.templates} templates`}
                    {t.content > 0 &&
                      ` · ${t.content} services/projects/entries`}
                  </span>
                </div>
                <div className="theme-actions">
                  <button
                    className="save-btn"
                    disabled={busy}
                    onClick={() => setView({ kind: "install", theme: t })}
                  >
                    {t.active ? "Reinstall" : "Install"}
                  </button>
                  <button
                    className="top-btn"
                    disabled={busy}
                    onClick={() => exportTheme(t)}
                    aria-label={`Export ${t.name}`}
                  >
                    <Download size={14} aria-hidden="true" /> Export
                  </button>
                  <button
                    className="icon-btn danger"
                    disabled={busy}
                    onClick={() => remove(t)}
                    aria-label={`Delete ${t.name}`}
                  >
                    <Trash2 size={14} aria-hidden="true" />
                  </button>
                </div>
              </li>
            ))}
          </ul>

          <h3>Save this site as a theme</h3>
          <div className="theme-form">
            <div className="field">
              <label htmlFor="te-name">
                <span>Theme name</span>
              </label>
              <input
                id="te-name"
                type="text"
                maxLength={80}
                value={form.name}
                onChange={e => setForm({ ...form, name: e.target.value })}
                placeholder="e.g. Acme Studio"
              />
            </div>
            <div className="field">
              <label htmlFor="te-version">
                <span>Version</span>
              </label>
              <input
                id="te-version"
                type="text"
                maxLength={20}
                value={form.version}
                onChange={e => setForm({ ...form, version: e.target.value })}
              />
            </div>
            <div className="field">
              <label htmlFor="te-author">
                <span>Author</span>
              </label>
              <input
                id="te-author"
                type="text"
                maxLength={80}
                value={form.author}
                onChange={e => setForm({ ...form, author: e.target.value })}
              />
            </div>
            <div className="field">
              <label htmlFor="te-industry">
                <span>Industry</span>
              </label>
              <input
                id="te-industry"
                type="text"
                maxLength={60}
                value={form.industry}
                onChange={e => setForm({ ...form, industry: e.target.value })}
                placeholder="e.g. Interior design"
              />
            </div>
            <div className="field">
              <label htmlFor="te-license">
                <span>License</span>
              </label>
              <input
                id="te-license"
                type="text"
                maxLength={80}
                value={form.license}
                onChange={e => setForm({ ...form, license: e.target.value })}
                placeholder="e.g. Regular license"
              />
            </div>
            <div className="field">
              <label htmlFor="te-demo">
                <span>Demo link</span>
              </label>
              <input
                id="te-demo"
                type="url"
                maxLength={300}
                value={form.demo}
                onChange={e => setForm({ ...form, demo: e.target.value })}
                placeholder="https://"
              />
            </div>
            <div className="field theme-wide">
              <label htmlFor="te-desc">
                <span>Description</span>
              </label>
              <input
                id="te-desc"
                type="text"
                maxLength={300}
                value={form.description}
                onChange={e =>
                  setForm({ ...form, description: e.target.value })
                }
              />
            </div>
          </div>
          <div className="dialog-actions">
            <label className="top-btn media-upload-btn">
              <Upload size={14} aria-hidden="true" /> Import kit or theme file
              <input
                type="file"
                accept="application/json,.json,application/zip,.zip"
                disabled={busy}
                onChange={e => {
                  onFile(e.target.files?.[0]);
                  e.target.value = "";
                }}
              />
            </label>
            <button
              className="top-btn"
              disabled={busy || form.name.trim() === ""}
              onClick={downloadKit}
            >
              <Download size={14} aria-hidden="true" /> Download kit (.zip)
            </button>
            <button
              className="save-btn"
              disabled={busy || form.name.trim() === ""}
              onClick={save}
            >
              Save as theme
            </button>
          </div>
        </>
      )}
    </>
  );
}
