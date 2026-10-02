import { useEffect, useState } from "react";
import { FilePlus2, Package, UploadCloud } from "lucide-react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import { pageHref } from "@/lib/router";
import type { Overview as OverviewData, PageRow } from "@/lib/schema/api";
import type { DashView } from "./Dashboard";
import { NewPageDialog } from "./PageDialogs";

export const fmtWhen = (iso: string) => {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  const mins = Math.round((Date.now() - d.getTime()) / 60000);
  if (mins < 1) return "just now";
  if (mins < 60) return `${mins} min ago`;
  if (mins < 60 * 24) return `${Math.round(mins / 60)} h ago`;
  return d.toLocaleDateString();
};

function Stat({
  label,
  value,
  hint,
  onClick,
}: {
  label: string;
  value: string | number;
  hint?: string;
  onClick?: () => void;
}) {
  const inner = (
    <>
      <span className="eyebrow">{label}</span>
      <strong>{value}</strong>
      {hint && <small className="muted">{hint}</small>}
    </>
  );
  return onClick ? (
    <button className="stat-card" onClick={onClick}>
      {inner}
    </button>
  ) : (
    <div className="stat-card">{inner}</div>
  );
}

function PageLink({
  p,
  navigate,
}: {
  p: PageRow;
  navigate: (to: string) => void;
}) {
  return (
    <a
      href={pageHref(p.id)}
      onClick={e => {
        if (!e.metaKey && !e.ctrlKey) {
          e.preventDefault();
          navigate(pageHref(p.id));
        }
      }}
    >
      {p.title || `(untitled ${p.id})`}
    </a>
  );
}

export function Overview({
  go,
  navigate,
}: {
  go: (v: DashView) => void;
  navigate: (to: string) => void;
}) {
  const [data, setData] = useState<OverviewData | null>(null);
  const [error, setError] = useState("");
  const [creating, setCreating] = useState(false);

  useEffect(() => {
    api
      .overview()
      .then(setData)
      .catch(e => setError(describeError(e)));
  }, []);

  if (error)
    return (
      <div className="notice error" role="alert">
        {error}
      </div>
    );
  if (!data) return <p className="muted">Loading…</p>;
  const { pages, site, visualizer: viz } = data;
  const attention = [
    ...data.attention.unpublishedChanges.map(p => ({
      p,
      why: "has changes that are not live yet",
    })),
    ...data.attention.missingDescription.map(p => ({
      p,
      why: "has no search description",
    })),
  ];

  return (
    <>
      <header className="dash-head">
        <div>
          <span className="eyebrow">Welcome back</span>
          <h1>{site.name}</h1>
          {site.tagline && <p className="muted">{site.tagline}</p>}
        </div>
        <div className="dash-actions">
          <button className="save-btn" onClick={() => setCreating(true)}>
            <FilePlus2 size={14} aria-hidden="true" /> New page
          </button>
          <a
            className="top-btn"
            href={site.url}
            target="_blank"
            rel="noreferrer"
          >
            View site
          </a>
        </div>
      </header>

      {!site.searchVisible && (
        <div className="notice warn" role="status">
          Search engines are asked <strong>not to index</strong> this site.{" "}
          <button className="top-btn" onClick={() => go("site")}>
            Change in Site &amp; SEO
          </button>
        </div>
      )}

      <section className="stat-grid" aria-label="At a glance">
        <Stat
          label="Pages"
          value={pages.total}
          hint={`${pages.publish} live · ${pages.draft} draft${pages.other ? ` · ${pages.other} other` : ""}`}
          onClick={() => go("pages")}
        />
        <Stat
          label="Images"
          value={data.media}
          hint="in the media library"
          onClick={() => go("media")}
        />
        <Stat
          label="Services & projects"
          value={data.content.services + data.content.projects}
          hint={`${data.content.services} services · ${data.content.projects} projects`}
        />
        <Stat
          label="Themes"
          value={data.themes}
          hint="saved in the library"
          onClick={() => go("themes")}
        />
        <Stat
          label="Visualizer"
          value={viz.ready ? "On" : viz.enabled ? "Needs setup" : "Off"}
          hint={`${viz.label} · ${viz.leads} lead${viz.leads === 1 ? "" : "s"}`}
          onClick={() => go("visualizer")}
        />
      </section>

      <div className="dash-cols">
        <section className="dash-card">
          <h2>Recently edited</h2>
          <ul className="dash-list">
            {data.recent.map(p => (
              <li key={p.id}>
                <PageLink p={p} navigate={navigate} />
                <span className={`badge ${p.status}`}>{p.status}</span>
                <small className="muted">{fmtWhen(p.modified)}</small>
              </li>
            ))}
            {data.recent.length === 0 && (
              <li className="muted">No pages yet.</li>
            )}
          </ul>
          <button className="top-btn" onClick={() => go("pages")}>
            All pages
          </button>
        </section>

        <section className="dash-card">
          <h2>Needs attention</h2>
          {attention.length === 0 ? (
            <p className="muted">Everything looks good.</p>
          ) : (
            <ul className="dash-list">
              {attention.map(({ p, why }) => (
                <li key={`${p.id}-${why}`}>
                  <PageLink p={p} navigate={navigate} />
                  <small className="muted">{why}</small>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>

      <section className="dash-card">
        <h2>Quick actions</h2>
        <div className="dash-actions">
          <button className="top-btn" onClick={() => setCreating(true)}>
            <FilePlus2 size={14} aria-hidden="true" /> New page
          </button>
          <button className="top-btn" onClick={() => go("media")}>
            <UploadCloud size={14} aria-hidden="true" /> Upload images
          </button>
          <button className="top-btn" onClick={() => go("themes")}>
            <Package size={14} aria-hidden="true" /> Save or install a theme
          </button>
        </div>
        <p className="muted">
          RK Builder {site.plugin} · <a href={site.adminUrl}>WordPress admin</a>
        </p>
      </section>

      {creating && (
        <NewPageDialog
          onClose={() => setCreating(false)}
          onCreated={p => {
            setCreating(false);
            navigate(pageHref(p.id));
          }}
        />
      )}
    </>
  );
}
