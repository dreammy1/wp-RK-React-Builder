import { useCallback, useEffect, useState } from "react";
import { Download, Package, Trash2, Upload } from "lucide-react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import type {
  ThemeInstallOptions,
  ThemeInstallReport,
  ThemeSummary,
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
  const [o, setO] = useState<ThemeInstallOptions>({
    dryRun: false,
    theme: true,
    content: true,
    publish: true,
    frontPage: true,
    settings: true,
    redirects: false,
    siteInfo: false,
    switch: true,
  });
  const [phase, setPhase] = useState<
    "idle" | "checking" | "installing" | { error: string }
  >("idle");
  const [check, setCheck] = useState<ThemeInstallReport | null>(null);
  const busy = phase === "checking" || phase === "installing";

  const run = (dryRun: boolean) => {
    setPhase(dryRun ? "checking" : "installing");
    api
      .installTheme(theme.slug, { ...o, dryRun })
      .then(r => {
        setPhase("idle");
        if (dryRun) setCheck(r);
        else onDone(r);
      })
      .catch(e => setPhase({ error: describeError(e) }));
  };

  return (
    <>
      <p className="muted">
        Install <strong>{theme.name}</strong> {theme.version} on this site:{" "}
        {theme.pages} page{theme.pages === 1 ? "" : "s"}, {theme.reusables}{" "}
        reusable block{theme.reusables === 1 ? "" : "s"}, {theme.media} image
        {theme.media === 1 ? "" : "s"}
        {theme.kit && " (the pictures are inside the kit)"}
        {(theme.templates ?? 0) > 0 &&
          `, ${theme.templates} template${theme.templates === 1 ? "" : "s"}`}
        . Pages with the same address are <strong>replaced</strong> by the
        theme&apos;s version; other pages are left alone.
      </p>
      <fieldset className="field" disabled={busy}>
        <legend>What to install</legend>
        <Toggle
          label="Theme settings"
          hint="colors, fonts, logo, header and footer"
          checked={o.theme}
          onChange={v => setO({ ...o, theme: v })}
        />
        <Toggle
          label="Services and projects"
          checked={o.content}
          disabled={theme.content === 0}
          onChange={v => setO({ ...o, content: v })}
        />
        <Toggle
          label="Layout settings"
          hint="content width and side spacing"
          checked={o.settings ?? false}
          onChange={v => setO({ ...o, settings: v })}
        />
        <Toggle
          label="Site name and tagline"
          hint="replaces yours with the kit's"
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
          label="Publish the pages right away"
          hint="otherwise they arrive as drafts"
          checked={o.publish}
          onChange={v =>
            setO({ ...o, publish: v, frontPage: v && o.frontPage })
          }
        />
        <Toggle
          label="Use its Home page as the site front page"
          checked={o.frontPage && o.publish}
          disabled={!o.publish}
          onChange={v => setO({ ...o, frontPage: v })}
        />
      </fieldset>
      {typeof phase === "object" && (
        <p className="form-error" role="alert">
          {phase.error}
        </p>
      )}
      {check && (
        <div role="status" aria-live="polite">
          <h3>This is what will happen</h3>
          {hiddenNote(check, true) && <p>{hiddenNote(check, true)}</p>}
          <Summary r={check} />
        </div>
      )}
      {busy && (
        <p className="muted" role="status">
          {phase === "checking"
            ? "Checking…"
            : "Installing — copying images can take a minute…"}
        </p>
      )}
      <div className="dialog-actions">
        <button className="top-btn" disabled={busy} onClick={onBack}>
          Back
        </button>
        <button className="top-btn" disabled={busy} onClick={() => run(true)}>
          Check first
        </button>
        <button className="save-btn" disabled={busy} onClick={() => run(false)}>
          Install theme
        </button>
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
      .then(r => setItems(r.items))
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
          <div className="dialog-actions">
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
