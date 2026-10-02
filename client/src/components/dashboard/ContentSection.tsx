import { useCallback, useEffect, useState } from "react";
import {
  ArrowLeft,
  Copy,
  ExternalLink,
  ImagePlus,
  Pencil,
  Plus,
  Search,
  Trash2,
  X,
} from "lucide-react";
import { api } from "@/lib/api/builder";
import { ApiError, describeError, describeIssues } from "@/lib/api/errors";
import type {
  ContentType,
  Entry,
  EntryMedia,
  EntryRow,
} from "@/lib/schema/api";
import { MediaPicker } from "../editor/MediaPicker";
import { fmtWhen } from "./Overview";
import { FieldInput, blankValue, toPayload } from "./EntryFields";

type Draft = {
  id: number;
  title: string;
  slug: string;
  status: string;
  excerpt: string;
  content: string;
  image: EntryMedia | null;
  terms: Record<string, string[]>;
  fields: Record<string, unknown>;
  link: string;
};

const blankDraft = (t: ContentType): Draft => ({
  id: 0,
  title: "",
  slug: "",
  status: "draft",
  excerpt: "",
  content: "",
  image: null,
  terms: {},
  fields: Object.fromEntries(t.fields.map(f => [f.key, blankValue(f)])),
  link: "",
});

const fromEntry = (t: ContentType, e: Entry): Draft => ({
  id: e.id,
  title: e.title,
  slug: e.slug,
  status: e.status,
  excerpt: e.excerpt,
  content: e.content,
  image: e.image,
  terms: e.terms,
  fields: {
    ...Object.fromEntries(t.fields.map(f => [f.key, blankValue(f)])),
    ...e.fields,
  },
  link: e.link,
});

function TermsInput({
  tax,
  value,
  onChange,
}: {
  tax: NonNullable<ContentType["taxonomyTerms"]>[number];
  value: string[];
  onChange: (v: string[]) => void;
}) {
  const [add, setAdd] = useState("");
  const names = new Set(value.map(v => v.toLowerCase()));
  const all = [
    ...tax.terms.map(t => t.name),
    ...value.filter(
      v => !tax.terms.some(t => t.name.toLowerCase() === v.toLowerCase())
    ),
  ];
  const toggle = (n: string) =>
    onChange(
      names.has(n.toLowerCase())
        ? value.filter(v => v.toLowerCase() !== n.toLowerCase())
        : [...value, n]
    );
  return (
    <fieldset className="field entry-terms">
      <legend>{tax.name}</legend>
      <div className="chip-row">
        {all.map(n => (
          <button
            key={n}
            type="button"
            className={`chip ${names.has(n.toLowerCase()) ? "on" : ""}`}
            aria-pressed={names.has(n.toLowerCase())}
            onClick={() => toggle(n)}
          >
            {n}
          </button>
        ))}
      </div>
      <div className="entry-term-add">
        <input
          aria-label={`New ${tax.name} name`}
          placeholder={`Add a new one…`}
          value={add}
          onChange={e => setAdd(e.target.value)}
          onKeyDown={e => {
            if (e.key === "Enter" && add.trim()) {
              e.preventDefault();
              if (!names.has(add.trim().toLowerCase()))
                onChange([...value, add.trim()]);
              setAdd("");
            }
          }}
        />
        <button
          type="button"
          className="top-btn"
          disabled={!add.trim()}
          onClick={() => {
            if (!names.has(add.trim().toLowerCase()))
              onChange([...value, add.trim()]);
            setAdd("");
          }}
        >
          Add
        </button>
      </div>
    </fieldset>
  );
}

function EntryEditor({
  type,
  start,
  onBack,
  onSaved,
}: {
  type: ContentType;
  start: Draft;
  onBack: () => void;
  onSaved: (e: Entry) => void;
}) {
  const [d, setD] = useState<Draft>(start);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [note, setNote] = useState("");
  const [picking, setPicking] = useState(false);
  const supports = new Set(type.supports);
  const set = (patch: Partial<Draft>) => setD(prev => ({ ...prev, ...patch }));

  const save = (status?: string) => {
    setBusy(true);
    setError("");
    setNote("");
    setFieldErrors({});
    const body: Record<string, unknown> = {
      title: d.title,
      status: status ?? d.status,
      excerpt: d.excerpt,
      content: d.content,
      image: d.image?.id ?? 0,
      terms: d.terms,
      fields: Object.fromEntries(
        type.fields.map(f => [f.key, toPayload(f, d.fields[f.key])])
      ),
    };
    if (d.slug) body.slug = d.slug;
    const call = d.id
      ? api.updateEntry(d.id, body)
      : api.createEntry(type.slug, body);
    call
      .then(r => {
        setD(fromEntry(type, r.entry));
        setNote(r.entry.status === "publish" ? "Saved and live." : "Saved.");
        onSaved(r.entry);
      })
      .catch(e => {
        setError(describeIssues(e));
        if (e instanceof ApiError && e.extra.issues) {
          const m: Record<string, string> = {};
          for (const i of e.extra.issues)
            m[i.path.replace(/^fields\./, "")] = i.message;
          setFieldErrors(m);
        }
      })
      .finally(() => setBusy(false));
  };

  return (
    <>
      <header className="dash-head tight">
        <div>
          <button className="top-btn" onClick={onBack}>
            <ArrowLeft size={14} aria-hidden="true" /> {type.plural}
          </button>
          <h1>{d.id ? `Edit ${type.singular}` : `New ${type.singular}`}</h1>
        </div>
        <div className="dash-actions">
          {d.id > 0 && d.link && d.status === "publish" && (
            <a
              className="top-btn"
              href={d.link}
              target="_blank"
              rel="noreferrer"
            >
              <ExternalLink size={14} aria-hidden="true" /> View
            </a>
          )}
          <button
            className="top-btn"
            disabled={busy}
            onClick={() => save("draft")}
          >
            Save as draft
          </button>
          <button
            className="save-btn"
            disabled={busy || !d.title.trim()}
            onClick={() => save("publish")}
          >
            {d.status === "publish" ? "Update" : "Publish"}
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
      <div className="entry-layout">
        <div className="entry-main">
          <section className="dash-card">
            <div className="field">
              <label htmlFor="en-title">
                <span>Title</span>
              </label>
              <input
                id="en-title"
                value={d.title}
                maxLength={200}
                onChange={e => set({ title: e.target.value })}
              />
            </div>
            {supports.has("excerpt") && (
              <div className="field">
                <label htmlFor="en-excerpt">
                  <span>Short summary</span>
                </label>
                <textarea
                  id="en-excerpt"
                  rows={3}
                  value={d.excerpt}
                  onChange={e => set({ excerpt: e.target.value })}
                />
                <small className="muted">
                  Shown on cards and in search results.
                </small>
              </div>
            )}
            {supports.has("editor") && (
              <div className="field">
                <label htmlFor="en-content">
                  <span>Description</span>
                </label>
                <textarea
                  id="en-content"
                  rows={8}
                  value={d.content}
                  onChange={e => set({ content: e.target.value })}
                />
                <small className="muted">
                  Plain text or simple HTML (paragraphs, links, lists).
                </small>
              </div>
            )}
          </section>
          {type.fields.length > 0 && (
            <section className="dash-card">
              <h2>Details</h2>
              <div className="entry-fields">
                {type.fields.map(f => (
                  <FieldInput
                    key={f.key}
                    field={f}
                    uid={`f${d.id}`}
                    value={d.fields[f.key]}
                    error={fieldErrors[f.key]}
                    onChange={v => set({ fields: { ...d.fields, [f.key]: v } })}
                  />
                ))}
              </div>
            </section>
          )}
        </div>
        <aside className="entry-side">
          <section className="dash-card">
            <h2>Publishing</h2>
            <div className="field">
              <label htmlFor="en-status">
                <span>Status</span>
              </label>
              <select
                id="en-status"
                value={d.status}
                onChange={e => set({ status: e.target.value })}
              >
                <option value="publish">Published</option>
                <option value="draft">Draft</option>
                <option value="pending">Pending review</option>
                <option value="private">Private</option>
              </select>
            </div>
            <div className="field">
              <label htmlFor="en-slug">
                <span>Address</span>
              </label>
              <input
                id="en-slug"
                value={d.slug}
                placeholder="made from the title"
                onChange={e => set({ slug: e.target.value })}
              />
            </div>
          </section>
          {supports.has("thumbnail") && (
            <section className="dash-card">
              <h2>Featured image</h2>
              {d.image && (
                <img className="entry-thumb" src={d.image.url} alt="" />
              )}
              <div className="dash-actions">
                <button className="top-btn" onClick={() => setPicking(true)}>
                  <ImagePlus size={14} aria-hidden="true" />{" "}
                  {d.image ? "Change" : "Choose or upload"}
                </button>
                {d.image && (
                  <button
                    className="top-btn"
                    onClick={() => set({ image: null })}
                  >
                    <X size={14} aria-hidden="true" /> Remove
                  </button>
                )}
              </div>
              {picking && (
                <MediaPicker
                  onClose={() => setPicking(false)}
                  onSelect={m => {
                    set({ image: m as EntryMedia });
                    setPicking(false);
                  }}
                />
              )}
            </section>
          )}
          {(type.taxonomyTerms ?? []).map(tax => (
            <section className="dash-card" key={tax.slug}>
              <TermsInput
                tax={tax}
                value={d.terms[tax.slug] ?? []}
                onChange={v => set({ terms: { ...d.terms, [tax.slug]: v } })}
              />
            </section>
          ))}
        </aside>
      </div>
    </>
  );
}

/** Entries of every content type: list, create, edit, duplicate, trash. No wp-admin needed. */
export function ContentSection({ initialType }: { initialType?: string }) {
  const [types, setTypes] = useState<ContentType[] | null>(null);
  const [slug, setSlug] = useState(initialType ?? "");
  const [rows, setRows] = useState<EntryRow[]>([]);
  const [total, setTotal] = useState(0);
  const [pageNo, setPageNo] = useState(1);
  const [search, setSearch] = useState("");
  const [editing, setEditing] = useState<Draft | null>(null);
  const [busy, setBusy] = useState(0);
  const [error, setError] = useState("");
  const [note, setNote] = useState("");

  useEffect(() => {
    api
      .getTypes()
      .then(r => {
        setTypes(r.types);
        setSlug(s =>
          s && r.types.some(t => t.slug === s) ? s : (r.types[0]?.slug ?? "")
        );
      })
      .catch(e => setError(describeError(e)));
  }, []);

  const type = types?.find(t => t.slug === slug);

  const load = useCallback(() => {
    if (!slug) return;
    api
      .listEntries(slug, { search, page: pageNo })
      .then(r => {
        setRows(r.items);
        setTotal(r.total);
      })
      .catch(e => setError(describeError(e)));
  }, [slug, search, pageNo]);

  useEffect(() => {
    const t = setTimeout(load, search ? 250 : 0);
    return () => clearTimeout(t);
  }, [load, search]);

  const open = (id: number) => {
    if (!type) return;
    setBusy(b => b + 1);
    api
      .getEntry(id)
      .then(r => setEditing(fromEntry(type, r.entry)))
      .catch(e => setError(describeError(e)))
      .finally(() => setBusy(b => b - 1));
  };
  const act = (p: Promise<unknown>, msg: string) => {
    setBusy(b => b + 1);
    setError("");
    p.then(() => {
      setNote(msg);
      load();
    })
      .catch(e => setError(describeError(e)))
      .finally(() => setBusy(b => b - 1));
  };

  if (!types)
    return error ? (
      <div className="notice error" role="alert">
        {error}
      </div>
    ) : (
      <p className="muted">Loading…</p>
    );

  if (editing && type)
    return (
      <EntryEditor
        key={editing.id}
        type={type}
        start={editing}
        onBack={() => {
          setEditing(null);
          load();
        }}
        onSaved={() => load()}
      />
    );

  return (
    <>
      <header className="dash-head">
        <div>
          <h1>Content</h1>
          <p className="muted">
            Add and edit the entries of your content types: listings, projects,
            team members, whatever you set up under Types &amp; fields.
          </p>
        </div>
        {type && (
          <div className="dash-actions">
            <button
              className="save-btn"
              onClick={() => setEditing(blankDraft(type))}
            >
              <Plus size={14} aria-hidden="true" /> New {type.singular}
            </button>
          </div>
        )}
      </header>
      <div
        className="chip-row type-tabs"
        role="tablist"
        aria-label="Content type"
      >
        {types.map(t => (
          <button
            key={t.slug}
            role="tab"
            aria-selected={t.slug === slug}
            className={`chip ${t.slug === slug ? "on" : ""}`}
            onClick={() => {
              setSlug(t.slug);
              setPageNo(1);
              setSearch("");
              setNote("");
            }}
          >
            {t.plural}
            {typeof t.count === "number" ? ` (${t.count})` : ""}
          </button>
        ))}
      </div>
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
      <div className="field search-field">
        <div className="input-icon">
          <Search size={14} aria-hidden="true" />
          <input
            type="search"
            aria-label={`Search ${type?.plural ?? ""}`}
            placeholder={`Search ${type?.plural.toLowerCase() ?? ""}`}
            value={search}
            onChange={e => {
              setSearch(e.target.value);
              setPageNo(1);
            }}
          />
        </div>
      </div>
      {rows.length === 0 ? (
        <section className="dash-card">
          <p className="muted">
            {search
              ? "Nothing matches that search."
              : `No ${type?.plural.toLowerCase() ?? "entries"} yet.`}
          </p>
        </section>
      ) : (
        <ul className="dash-pages">
          {rows.map(r => (
            <li key={r.id} className={busy > 0 ? "busy" : ""}>
              {r.image ? (
                <img className="entry-row-thumb" src={r.image} alt="" />
              ) : (
                <span className="entry-row-thumb empty" aria-hidden="true" />
              )}
              <div className="dash-page-main">
                <button
                  className="dash-page-title linklike"
                  onClick={() => open(r.id)}
                >
                  {r.title || "(no title)"}
                </button>
                <div className="dash-page-meta">
                  <span className={`badge ${r.status}`}>
                    {r.status === "publish" ? "published" : r.status}
                  </span>
                  <span>{fmtWhen(r.modified)}</span>
                </div>
              </div>
              <div className="dash-page-actions">
                <button className="top-btn" onClick={() => open(r.id)}>
                  <Pencil size={13} aria-hidden="true" /> Edit
                </button>
                <button
                  className="icon-btn"
                  aria-label={`Duplicate ${r.title}`}
                  onClick={() =>
                    act(api.duplicateEntry(r.id), "Duplicated as a draft.")
                  }
                >
                  <Copy size={14} aria-hidden="true" />
                </button>
                <button
                  className="icon-btn"
                  aria-label={`Move ${r.title} to the trash`}
                  onClick={() => {
                    if (window.confirm(`Move “${r.title}” to the trash?`))
                      act(api.trashEntry(r.id), "Moved to the trash.");
                  }}
                >
                  <Trash2 size={14} aria-hidden="true" />
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}
      {total > 30 && (
        <div className="dash-actions">
          <button
            className="top-btn"
            disabled={pageNo <= 1}
            onClick={() => setPageNo(p => p - 1)}
          >
            Previous
          </button>
          <span className="muted">
            Page {pageNo} of {Math.ceil(total / 30)}
          </span>
          <button
            className="top-btn"
            disabled={pageNo >= Math.ceil(total / 30)}
            onClick={() => setPageNo(p => p + 1)}
          >
            Next
          </button>
        </div>
      )}
    </>
  );
}
