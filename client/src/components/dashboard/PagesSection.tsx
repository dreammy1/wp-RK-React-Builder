import { useCallback, useEffect, useState } from "react";
import {
  Copy,
  Eye,
  FilePlus2,
  Globe,
  Home,
  MoreVertical,
  Pencil,
  Search,
  Trash2,
  Undo2,
} from "lucide-react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import { pageHref } from "@/lib/router";
import type { PageRow } from "@/lib/schema/api";
import { NewPageDialog, RenameDialog, SeoDialog } from "./PageDialogs";
import { fmtWhen } from "./Overview";

type Dialog =
  | null
  | { kind: "new" }
  | { kind: "rename"; page: PageRow }
  | { kind: "seo"; page: PageRow };

export function PagesSection({ navigate }: { navigate: (to: string) => void }) {
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [rows, setRows] = useState<PageRow[]>([]);
  const [total, setTotal] = useState(0);
  const [phase, setPhase] = useState<"loading" | "ready" | "error">("loading");
  const [error, setError] = useState("");
  const [note, setNote] = useState("");
  const [attempt, setAttempt] = useState(0);
  const [pageNo, setPageNo] = useState(1);
  const [menu, setMenu] = useState<number | null>(null);
  const [dialog, setDialog] = useState<Dialog>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [siteName, setSiteName] = useState("");

  useEffect(() => {
    api
      .overview()
      .then(o => setSiteName(o.site.name))
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    const ctrl = new AbortController();
    setPhase("loading");
    const t = setTimeout(
      () => {
        api
          .listPages({ search, status, page: 1 }, ctrl.signal)
          .then(r => {
            setRows(r.pages);
            setTotal(r.total);
            setPageNo(1);
            setPhase("ready");
          })
          .catch(e => {
            if (ctrl.signal.aborted) return;
            setError(describeError(e));
            setPhase("error");
          });
      },
      search ? 250 : 0
    );
    return () => {
      clearTimeout(t);
      ctrl.abort();
    };
  }, [search, status, attempt]);

  const reload = useCallback(() => setAttempt(a => a + 1), []);

  const more = () => {
    const next = pageNo + 1;
    api
      .listPages({ search, status, page: next })
      .then(r => {
        setRows(prev => [...prev, ...r.pages]);
        setPageNo(next);
      })
      .catch(e => setError(describeError(e)));
  };

  const run = (p: PageRow, fn: () => Promise<unknown>, done: string) => {
    setMenu(null);
    setBusyId(p.id);
    setNote("");
    setError("");
    fn()
      .then(() => {
        setNote(done);
        reload();
      })
      .catch(e => setError(describeError(e)))
      .finally(() => setBusyId(null));
  };

  const open = (p: PageRow) => navigate(pageHref(p.id));

  return (
    <>
      <header className="dash-head">
        <div>
          <h1>Pages</h1>
          <p className="muted">{total} pages</p>
        </div>
        <div className="dash-actions">
          <button
            className="save-btn"
            onClick={() => setDialog({ kind: "new" })}
          >
            <FilePlus2 size={14} aria-hidden="true" /> New page
          </button>
        </div>
      </header>

      <div className="selector-filters">
        <div className="field">
          <label htmlFor="pg-search">
            <span>Search pages</span>
          </label>
          <div className="search-box">
            <Search size={14} aria-hidden="true" />
            <input
              id="pg-search"
              type="search"
              value={search}
              onChange={e => setSearch(e.target.value)}
              placeholder="Title or address"
            />
          </div>
        </div>
        <div className="field">
          <label htmlFor="pg-status">
            <span>Status</span>
          </label>
          <select
            id="pg-status"
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

      {note && (
        <p className="notice info inline" role="status">
          {note}
        </p>
      )}
      {error && (
        <div className="notice error" role="alert">
          {error}{" "}
          <button className="top-btn" onClick={reload}>
            Retry
          </button>
        </div>
      )}
      {phase === "loading" && rows.length === 0 && (
        <p className="muted">Loading pages…</p>
      )}
      {phase === "ready" && rows.length === 0 && (
        <p className="muted">No pages match.</p>
      )}

      <ul className="dash-pages">
        {rows.map(p => {
          const unpublished =
            p.status === "publish" &&
            p.publishedRevision != null &&
            p.revision > p.publishedRevision;
          return (
            <li key={p.id} className={busyId === p.id ? "busy" : ""}>
              <div className="dash-page-main">
                <a
                  href={pageHref(p.id)}
                  className="dash-page-title"
                  onClick={e => {
                    if (!e.metaKey && !e.ctrlKey) {
                      e.preventDefault();
                      open(p);
                    }
                  }}
                >
                  {p.title || `(untitled ${p.id})`}
                </a>
                <code>/{p.slug}</code>
              </div>
              <div className="dash-page-meta">
                <span className={`badge ${p.status}`}>{p.status}</span>
                {p.isFront && <span className="badge publish">front page</span>}
                {p.noindex && <span className="badge warn">noindex</span>}
                {unpublished && (
                  <span className="badge warn">unpublished changes</span>
                )}
                <small className="muted">{fmtWhen(p.modified)}</small>
              </div>
              <div className="dash-page-actions">
                <button className="top-btn" onClick={() => open(p)}>
                  Edit
                </button>
                <button
                  className="icon-btn"
                  aria-label={`More actions for ${p.title}`}
                  aria-expanded={menu === p.id}
                  onClick={() => setMenu(menu === p.id ? null : p.id)}
                >
                  <MoreVertical size={15} aria-hidden="true" />
                </button>
                {menu === p.id && (
                  <div className="row-menu" role="menu">
                    {p.link && p.status === "publish" && (
                      <a
                        role="menuitem"
                        href={p.link}
                        target="_blank"
                        rel="noreferrer"
                      >
                        <Eye size={14} aria-hidden="true" /> View page
                      </a>
                    )}
                    <button
                      role="menuitem"
                      onClick={() => {
                        setMenu(null);
                        setDialog({ kind: "rename", page: p });
                      }}
                    >
                      <Pencil size={14} aria-hidden="true" /> Rename / address
                    </button>
                    <button
                      role="menuitem"
                      onClick={() => {
                        setMenu(null);
                        setDialog({ kind: "seo", page: p });
                      }}
                    >
                      <Globe size={14} aria-hidden="true" /> Search &amp;
                      sharing
                    </button>
                    <button
                      role="menuitem"
                      onClick={() =>
                        run(
                          p,
                          () => api.duplicatePage(p.id),
                          `Duplicated “${p.title}”.`
                        )
                      }
                    >
                      <Copy size={14} aria-hidden="true" /> Duplicate
                    </button>
                    {p.status === "publish" ? (
                      <button
                        role="menuitem"
                        onClick={() =>
                          run(
                            p,
                            () => api.unpublish(p.id),
                            `“${p.title}” is now a draft.`
                          )
                        }
                      >
                        <Undo2 size={14} aria-hidden="true" /> Unpublish
                      </button>
                    ) : (
                      <button
                        role="menuitem"
                        onClick={() =>
                          run(
                            p,
                            () => api.publish(p.id, p.revision),
                            `“${p.title}” is live.`
                          )
                        }
                      >
                        <Globe size={14} aria-hidden="true" /> Publish
                      </button>
                    )}
                    {p.status === "publish" && !p.isFront && (
                      <button
                        role="menuitem"
                        onClick={() =>
                          run(
                            p,
                            () => api.makeFrontPage(p.id),
                            `“${p.title}” is now the front page.`
                          )
                        }
                      >
                        <Home size={14} aria-hidden="true" /> Make front page
                      </button>
                    )}
                    {!p.isFront && (
                      <button
                        role="menuitem"
                        className="danger"
                        onClick={() => {
                          if (
                            window.confirm(
                              `Move “${p.title}” to the trash? You can restore it from WordPress.`
                            )
                          )
                            run(
                              p,
                              () => api.trashPage(p.id),
                              `“${p.title}” moved to the trash.`
                            );
                          else setMenu(null);
                        }}
                      >
                        <Trash2 size={14} aria-hidden="true" /> Move to trash
                      </button>
                    )}
                  </div>
                )}
              </div>
            </li>
          );
        })}
      </ul>
      {rows.length < total && (
        <div className="dialog-actions">
          <button className="top-btn" onClick={more}>
            Load more
          </button>
        </div>
      )}

      {dialog?.kind === "new" && (
        <NewPageDialog
          onClose={() => setDialog(null)}
          onCreated={p => {
            setDialog(null);
            open(p);
          }}
        />
      )}
      {dialog?.kind === "rename" && (
        <RenameDialog
          page={dialog.page}
          onClose={() => setDialog(null)}
          onSaved={p => {
            setDialog(null);
            setNote(`Saved “${p.title}”.`);
            reload();
          }}
        />
      )}
      {dialog?.kind === "seo" && (
        <SeoDialog
          page={dialog.page}
          siteName={siteName}
          onClose={() => setDialog(null)}
          onSaved={() => {
            setDialog(null);
            setNote("Search and sharing details saved.");
            reload();
          }}
        />
      )}
    </>
  );
}
