import { useEffect, useState } from "react";
import { api } from "@/lib/api/builder";
import { pageHref } from "@/lib/router";
import { describeError } from "@/lib/api/errors";
import type { PageSummary } from "@/lib/schema/api";
import { ExportSiteButton, ImportSiteDialog } from "./SiteTransfer";
import { ThemeEngineDialog } from "./ThemeEngine";
import { Package, Upload } from "lucide-react";

type Props = { navigate: (to: string) => void; onSignOut?: () => void };

export function PageSelector({ navigate, onSignOut }: Props) {
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [state, setState] = useState<{
    phase: "loading" | "ready" | "error";
    pages: PageSummary[];
    error?: string;
  }>({ phase: "loading", pages: [] });
  const [attempt, setAttempt] = useState(0);
  const [importing, setImporting] = useState(false);
  const [themes, setThemes] = useState(false);
  const transfer = api.canTransferSite();

  useEffect(() => {
    const ctrl = new AbortController();
    setState(s => ({ ...s, phase: "loading" }));
    const t = setTimeout(
      () => {
        api
          .listPages({ search, status }, ctrl.signal)
          .then(r => setState({ phase: "ready", pages: r.pages }))
          .catch(e => {
            if (!ctrl.signal.aborted)
              setState({ phase: "error", pages: [], error: describeError(e) });
          });
      },
      search ? 250 : 0
    );
    return () => {
      clearTimeout(t);
      ctrl.abort();
    };
  }, [search, status, attempt]);

  return (
    <main className="selector">
      <header className="selector-head">
        <div>
          <span className="eyebrow">RK / BUILDER</span>
          <h1>Choose a page to edit</h1>
        </div>
        <div className="selector-actions">
          {transfer && (
            <>
              <button className="top-btn" onClick={() => setThemes(true)}>
                <Package size={14} aria-hidden="true" /> Themes
              </button>
              <ExportSiteButton />
              <button className="top-btn" onClick={() => setImporting(true)}>
                <Upload size={14} aria-hidden="true" /> Import site
              </button>
            </>
          )}
          {onSignOut && (
            <button className="top-btn" onClick={onSignOut}>
              Sign out
            </button>
          )}
        </div>
      </header>
      {themes && (
        <ThemeEngineDialog
          onClose={() => setThemes(false)}
          onInstalled={() => setAttempt(a => a + 1)}
        />
      )}
      {importing && (
        <ImportSiteDialog
          onClose={() => setImporting(false)}
          onImported={() => setAttempt(a => a + 1)}
        />
      )}
      <div className="selector-filters">
        <div className="field">
          <label htmlFor="ps-search">
            <span>Search pages</span>
          </label>
          <input
            id="ps-search"
            type="search"
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Title or slug"
          />
        </div>
        <div className="field">
          <label htmlFor="ps-status">
            <span>Status</span>
          </label>
          <select
            id="ps-status"
            value={status}
            onChange={e => setStatus(e.target.value)}
          >
            <option value="">All</option>
            <option value="publish">Published</option>
            <option value="draft">Draft</option>
            <option value="private">Private</option>
          </select>
        </div>
      </div>
      <div role="status" aria-live="polite" className="sr-only">
        {state.phase === "ready" ? `${state.pages.length} pages` : ""}
      </div>
      {state.phase === "error" && (
        <div className="notice error" role="alert">
          {state.error}{" "}
          <button className="top-btn" onClick={() => setAttempt(a => a + 1)}>
            Retry
          </button>
        </div>
      )}
      {state.phase === "loading" && <p className="muted">Loading pages…</p>}
      {state.phase === "ready" && state.pages.length === 0 && (
        <p className="muted">
          No pages match. Create a page in WordPress first.
        </p>
      )}
      {state.pages.length > 0 && (
        <table className="page-table">
          <caption className="sr-only">Pages you can edit</caption>
          <thead>
            <tr>
              <th scope="col">Title</th>
              <th scope="col">Slug</th>
              <th scope="col">Status</th>
              <th scope="col">Modified</th>
              <th scope="col">Revision</th>
            </tr>
          </thead>
          <tbody>
            {state.pages.map(p => (
              <tr key={p.id}>
                <th scope="row">
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
                </th>
                <td>
                  <code>/{p.slug}</code>
                </td>
                <td>
                  <span className={`badge ${p.status}`}>{p.status}</span>
                </td>
                <td>{new Date(p.modified).toLocaleString()}</td>
                <td>
                  {p.revision}
                  {p.publishedRevision != null ? (
                    <small> · live {p.publishedRevision}</small>
                  ) : null}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </main>
  );
}
