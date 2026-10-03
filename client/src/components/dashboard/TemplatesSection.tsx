import { useEffect, useState } from "react";
import { LayoutTemplate, Pencil, Plus, Trash2 } from "lucide-react";
import { api } from "@/lib/api/builder";
import { describeIssues } from "@/lib/api/errors";
import { pageHref } from "@/lib/router";
import type { ContentType, TemplateItem } from "@/lib/schema/api";
import { SubPage } from "../SubPage";
import { BulkBar, RowCheck, runEach, useSelection } from "./Bulk";
import { fmtWhen } from "./Overview";

const KINDS = [
  {
    id: "header",
    name: "Header",
    help: "The site's top bar (logo, menu, phone button). One live header replaces the navbar on every page and template.",
  },
  {
    id: "footer",
    name: "Footer",
    help: "The site's footer (links, contact details, copyright). One live footer replaces the footer on every page and template.",
  },
  {
    id: "single",
    name: "Single page",
    help: "How one entry looks on its own page: its image, title, details, gallery and related entries.",
  },
  {
    id: "archive",
    name: "Archive / listing",
    help: "The list page of a content type (or of one category group): a grid with filters, search and page numbers.",
  },
  {
    id: "loop",
    name: "Card",
    help: "The card drawn for each entry inside a Loop grid block, on listings, pages or related-entries rows.",
  },
  {
    id: "notfound",
    name: "404 page",
    help: "What visitors see when a page does not exist. Not tied to a content type.",
  },
] as const;
type Kind = (typeof KINDS)[number]["id"];
const SITEWIDE: string[] = ["notfound", "header", "footer"];

function NewTemplate({
  types,
  onClose,
  onCreate,
  busy,
  error,
}: {
  types: ContentType[];
  onClose: () => void;
  onCreate: (b: {
    title: string;
    kind: Kind;
    postType: string;
    taxonomy: string;
  }) => void;
  busy: boolean;
  error: string;
}) {
  const [kind, setKind] = useState<Kind>("single");
  const [postType, setPostType] = useState(types[0]?.slug ?? "");
  const [taxonomy, setTaxonomy] = useState("");
  const type = types.find(t => t.slug === postType);
  const [title, setTitle] = useState("");
  const [touched, setTouched] = useState(false);
  const auto = SITEWIDE.includes(kind)
    ? KINDS.find(k => k.id === kind)!.name
    : `${type?.singular ?? ""} ${KINDS.find(k => k.id === kind)!.name.toLowerCase()}`.trim();
  return (
    <SubPage title="New template" onClose={onClose}>
      <fieldset className="field kind-pick">
        <legend>What are you designing?</legend>
        {KINDS.map(k => (
          <label
            key={k.id}
            className={`kind-card ${kind === k.id ? "on" : ""}`}
          >
            <input
              type="radio"
              name="tpl-kind"
              checked={kind === k.id}
              onChange={() => {
                setKind(k.id);
                if (k.id !== "archive") setTaxonomy("");
              }}
            />
            <strong>{k.name}</strong>
            <small>{k.help}</small>
          </label>
        ))}
      </fieldset>
      {!SITEWIDE.includes(kind) && (
        <div className="field">
          <label htmlFor="tpl-type">
            <span>For which content type?</span>
          </label>
          <select
            id="tpl-type"
            value={postType}
            onChange={e => {
              setPostType(e.target.value);
              setTaxonomy("");
            }}
          >
            {types.map(t => (
              <option key={t.slug} value={t.slug}>
                {t.plural}
              </option>
            ))}
          </select>
        </div>
      )}
      {kind === "archive" && (type?.taxonomyTerms?.length ?? 0) > 0 && (
        <div className="field">
          <label htmlFor="tpl-tax">
            <span>Used for</span>
          </label>
          <select
            id="tpl-tax"
            value={taxonomy}
            onChange={e => setTaxonomy(e.target.value)}
          >
            <option value="">The main listing page</option>
            {type?.taxonomyTerms?.map(x => (
              <option key={x.slug} value={x.slug}>
                Pages for each {x.name.toLowerCase()} (category pages)
              </option>
            ))}
          </select>
        </div>
      )}
      <div className="field">
        <label htmlFor="tpl-name">
          <span>Name</span>
        </label>
        <input
          id="tpl-name"
          value={touched ? title : auto}
          maxLength={80}
          onChange={e => {
            setTouched(true);
            setTitle(e.target.value);
          }}
        />
        <small className="muted">
          It starts with a layout built from your fields, ready to adjust.
        </small>
      </div>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <div className="dialog-actions">
        <button className="top-btn" onClick={onClose}>
          Cancel
        </button>
        <button
          className="save-btn"
          disabled={busy || (!SITEWIDE.includes(kind) && !postType)}
          onClick={() =>
            onCreate({
              title: touched ? title : auto,
              kind,
              postType: SITEWIDE.includes(kind) ? "" : postType,
              taxonomy,
            })
          }
        >
          Create and design
        </button>
      </div>
    </SubPage>
  );
}

/** The theme builder: templates for single entries, listing pages and cards. */
export function TemplatesSection({
  navigate,
}: {
  navigate: (to: string) => void;
}) {
  const [items, setItems] = useState<TemplateItem[] | null>(null);
  const [types, setTypes] = useState<ContentType[]>([]);
  const [creating, setCreating] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [dialogError, setDialogError] = useState("");
  const [note, setNote] = useState("");
  const pick = useSelection((items ?? []).map(t => t.id));

  const load = () =>
    Promise.all([api.listTemplates(), api.getTypes()])
      .then(([t, ty]) => {
        setItems(t.items);
        setTypes(ty.types);
      })
      .catch(e => setError(describeIssues(e)));
  useEffect(() => {
    void load();
  }, []);

  const run = (p: Promise<unknown>, msg: string) => {
    setBusy(true);
    setError("");
    setNote("");
    p.then(() => {
      setNote(msg);
      return load();
    })
      .catch(e => setError(describeIssues(e)))
      .finally(() => setBusy(false));
  };

  if (!items)
    return error ? (
      <div className="notice error" role="alert">
        {error}
      </div>
    ) : (
      <p className="muted">Loading…</p>
    );

  const chosen = items.filter(t => pick.has(t.id));
  const bulk = (
    list: TemplateItem[],
    fn: (t: TemplateItem) => Promise<unknown>,
    verb: string
  ) => {
    setBusy(true);
    setError("");
    setNote("");
    runEach(list, fn, verb, "template")
      .then(r => {
        setNote(r.note);
        setError(r.error);
        pick.clear();
        return load();
      })
      .finally(() => setBusy(false));
  };

  const typeName = (slug: string) =>
    types.find(t => t.slug === slug)?.plural ?? slug;

  return (
    <>
      <header className="dash-head">
        <div>
          <h1>Templates</h1>
          <p className="muted">
            Design how your content looks on the website. A single-page template
            replaces an entry&apos;s page; an archive template replaces its
            listing; a card template draws each entry inside a Loop grid.
          </p>
        </div>
        <div className="dash-actions">
          <button className="save-btn" onClick={() => setCreating(true)}>
            <Plus size={14} aria-hidden="true" /> New template
          </button>
        </div>
      </header>
      {note && (
        <p className="notice info inline" role="status">
          {note}
        </p>
      )}
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <BulkBar
        noun="templates"
        count={pick.count}
        total={items.length}
        all={pick.all}
        onToggleAll={pick.toggleAll}
        onClear={pick.clear}
        busy={busy}
        actions={[
          {
            label: "Switch off",
            onClick: () =>
              bulk(
                chosen.filter(t => t.kind !== "loop" && t.active),
                t => api.updateTemplate(t.id, { active: false }),
                "Switched off"
              ),
          },
          {
            label: "Delete",
            icon: <Trash2 size={14} aria-hidden="true" />,
            danger: true,
            onClick: () => {
              if (
                window.confirm(
                  `Delete ${chosen.length} template${chosen.length === 1 ? "" : "s"}? This cannot be undone.`
                )
              )
                bulk(chosen, t => api.deleteTemplate(t.id), "Deleted");
            },
          },
        ]}
      />
      {KINDS.map(k => {
        const list = items.filter(i => i.kind === k.id);
        return (
          <section className="dash-card" key={k.id}>
            <h2>{k.name} templates</h2>
            <p className="muted">{k.help}</p>
            {list.length === 0 ? (
              <p className="muted">None yet.</p>
            ) : (
              <ul className="dash-pages">
                {list.map(t => (
                  <li
                    key={t.id}
                    className={`selectable${busy ? " busy" : ""}${pick.has(t.id) ? " picked" : ""}`}
                  >
                    <RowCheck
                      checked={pick.has(t.id)}
                      onChange={() => pick.toggle(t.id)}
                      label={`Select ${t.title}`}
                    />
                    <LayoutTemplate
                      size={18}
                      aria-hidden="true"
                      className="tpl-icon"
                    />
                    <div className="dash-page-main">
                      <button
                        className="dash-page-title linklike"
                        onClick={() => navigate(pageHref(t.id))}
                      >
                        {t.title}
                      </button>
                      <div className="dash-page-meta">
                        <span>
                          {SITEWIDE.includes(k.id)
                            ? "Whole site"
                            : typeName(t.postType)}
                          {t.taxonomy ? ` · by ${t.taxonomy}` : ""}
                        </span>
                        <span
                          className={`badge ${t.live ? "publish" : "draft"}`}
                        >
                          {t.live ? "published" : "draft"}
                        </span>
                        {k.id !== "loop" && t.active && t.live && (
                          <span className="badge on">in use</span>
                        )}
                        <span>{fmtWhen(t.modified)}</span>
                      </div>
                    </div>
                    <div className="dash-page-actions">
                      {k.id !== "loop" && (
                        <label className="switch-row">
                          <input
                            type="checkbox"
                            checked={t.active}
                            disabled={busy}
                            onChange={e =>
                              run(
                                api.updateTemplate(t.id, {
                                  active: e.target.checked,
                                }),
                                e.target.checked
                                  ? t.live
                                    ? "This template is now used on the site."
                                    : "Marked as in use. It goes live once you publish it in the editor."
                                  : "Switched off. WordPress draws these pages again."
                              )
                            }
                          />{" "}
                          <span>Use on site</span>
                        </label>
                      )}
                      <button
                        className="top-btn"
                        onClick={() => navigate(pageHref(t.id))}
                      >
                        <Pencil size={13} aria-hidden="true" /> Design
                      </button>
                      <button
                        className="icon-btn"
                        aria-label={`Delete ${t.title}`}
                        onClick={() => {
                          if (
                            window.confirm(
                              `Delete the template “${t.title}”? This cannot be undone.`
                            )
                          )
                            run(api.deleteTemplate(t.id), "Template deleted.");
                        }}
                      >
                        <Trash2 size={14} aria-hidden="true" />
                      </button>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </section>
        );
      })}
      {creating && (
        <NewTemplate
          types={types}
          busy={busy}
          error={dialogError}
          onClose={() => {
            setCreating(false);
            setDialogError("");
          }}
          onCreate={b => {
            setBusy(true);
            setDialogError("");
            api
              .createTemplate(b)
              .then(r => navigate(pageHref(r.item.id)))
              .catch(e => setDialogError(describeIssues(e)))
              .finally(() => setBusy(false));
          }}
        />
      )}
    </>
  );
}
