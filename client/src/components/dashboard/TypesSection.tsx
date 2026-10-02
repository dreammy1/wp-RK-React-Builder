import { useEffect, useState } from "react";
import { ArrowDown, ArrowUp, Plus, Trash2 } from "lucide-react";
import { api } from "@/lib/api/builder";
import { describeIssues } from "@/lib/api/errors";
import {
  FIELD_TYPES,
  type ContentType,
  type EntryField,
  type FieldType,
} from "@/lib/schema/api";

type Field = EntryField;
type Sub = EntryField["subfields"][number];

const TYPE_LABEL: Record<FieldType, string> = {
  text: "Short text",
  textarea: "Long text",
  number: "Number",
  email: "Email",
  url: "Link",
  date: "Date",
  color: "Colour",
  select: "Choice list",
  toggle: "Yes / no switch",
  image: "Image",
  gallery: "Gallery (many images)",
  repeater: "Repeater (rows of fields)",
};
const SUPPORTS: [string, string][] = [
  ["title", "Title"],
  ["editor", "Description"],
  ["excerpt", "Short summary"],
  ["thumbnail", "Featured image"],
  ["page-attributes", "Custom order"],
];

const slugKey = (label: string) => {
  const k = label
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "")
    .replace(/^[0-9_]+/, "")
    .slice(0, 32);
  return k;
};

const blankField = (): Field => ({
  key: "",
  label: "",
  type: "text",
  help: "",
  required: false,
  default: "",
  options: [],
  min: null,
  max: null,
  subfields: [],
});

/** "value | Label" per line <-> options. */
const optionsToText = (o: Field["options"]) =>
  o
    .map(x =>
      x.label && x.label !== x.value ? `${x.value} | ${x.label}` : x.value
    )
    .join("\n");
const textToOptions = (t: string): Field["options"] =>
  t
    .split("\n")
    .map(l => l.trim())
    .filter(Boolean)
    .map(l => {
      const [v = "", ...rest] = l.split("|");
      const label = rest.join("|").trim();
      const value = v.trim();
      return { value, label: label || value };
    });

function FieldRow({
  f,
  index,
  count,
  nested,
  onChange,
  onMove,
  onRemove,
}: {
  f: Field | Sub;
  index: number;
  count: number;
  nested?: boolean;
  onChange: (f: Field) => void;
  onMove: (d: number) => void;
  onRemove: () => void;
}) {
  const field = f as Field;
  const [open, setOpen] = useState(!f.key);
  const [optText, setOptText] = useState(optionsToText(f.options));
  const types = FIELD_TYPES.filter(
    t => !nested || (t !== "gallery" && t !== "repeater")
  );
  const set = (patch: Partial<Field>) =>
    onChange({ ...field, subfields: field.subfields ?? [], ...patch });
  return (
    <li className={`type-field ${nested ? "nested" : ""}`}>
      <div className="type-field-head">
        <button
          type="button"
          className="linklike type-field-name"
          aria-expanded={open}
          onClick={() => setOpen(o => !o)}
        >
          <strong>{f.label || "New field"}</strong>
          <span className="muted">
            {" "}
            {TYPE_LABEL[f.type]}
            {f.key ? ` · ${f.key}` : ""}
            {f.required ? " · required" : ""}
          </span>
        </button>
        <div className="entry-row-tools">
          <button
            type="button"
            aria-label="Move up"
            disabled={index === 0}
            onClick={() => onMove(-1)}
          >
            <ArrowUp size={12} aria-hidden="true" />
          </button>
          <button
            type="button"
            aria-label="Move down"
            disabled={index === count - 1}
            onClick={() => onMove(1)}
          >
            <ArrowDown size={12} aria-hidden="true" />
          </button>
          <button
            type="button"
            aria-label={`Delete ${f.label || "field"}`}
            onClick={onRemove}
          >
            <Trash2 size={12} aria-hidden="true" />
          </button>
        </div>
      </div>
      {open && (
        <div className="type-field-body">
          <div className="field">
            <label>
              <span>Label</span>
              <input
                value={f.label}
                maxLength={60}
                onChange={e =>
                  set({
                    label: e.target.value,
                    // The key follows the label until it has been saved once.
                    ...(field.key === "" || field.key === slugKey(f.label)
                      ? { key: slugKey(e.target.value) }
                      : {}),
                  })
                }
              />
            </label>
          </div>
          <div className="field">
            <label>
              <span>Key (used by templates)</span>
              <input
                value={f.key}
                maxLength={32}
                onChange={e =>
                  set({
                    key: e.target.value
                      .toLowerCase()
                      .replace(/[^a-z0-9_]/g, ""),
                  })
                }
              />
            </label>
          </div>
          <div className="field">
            <label>
              <span>Type</span>
              <select
                value={f.type}
                onChange={e => set({ type: e.target.value as FieldType })}
              >
                {types.map(t => (
                  <option key={t} value={t}>
                    {TYPE_LABEL[t]}
                  </option>
                ))}
              </select>
            </label>
          </div>
          <div className="field">
            <label>
              <span>Help text (optional)</span>
              <input
                value={f.help}
                maxLength={160}
                onChange={e => set({ help: e.target.value })}
              />
            </label>
          </div>
          {f.type === "select" && (
            <div className="field">
              <label>
                <span>Choices, one per line (value | label)</span>
                <textarea
                  rows={4}
                  value={optText}
                  placeholder={"sale | For sale\nsold | Sold"}
                  onChange={e => {
                    setOptText(e.target.value);
                    set({ options: textToOptions(e.target.value) });
                  }}
                />
              </label>
            </div>
          )}
          {f.type === "number" && (
            <div className="type-minmax">
              <label className="field">
                <span>Minimum</span>
                <input
                  type="number"
                  value={f.min ?? ""}
                  onChange={e =>
                    set({
                      min:
                        e.target.value === "" ? null : Number(e.target.value),
                    })
                  }
                />
              </label>
              <label className="field">
                <span>Maximum</span>
                <input
                  type="number"
                  value={f.max ?? ""}
                  onChange={e =>
                    set({
                      max:
                        e.target.value === "" ? null : Number(e.target.value),
                    })
                  }
                />
              </label>
            </div>
          )}
          {!["image", "gallery", "repeater"].includes(f.type) && (
            <div className="field">
              <label>
                <span>Starting value (optional)</span>
                <input
                  value={f.default}
                  maxLength={200}
                  onChange={e => set({ default: e.target.value })}
                />
              </label>
            </div>
          )}
          {f.type !== "toggle" && (
            <div className="field check">
              <label>
                <input
                  type="checkbox"
                  checked={f.required}
                  onChange={e => set({ required: e.target.checked })}
                />{" "}
                <span>Required</span>
              </label>
            </div>
          )}
          {f.type === "repeater" && !nested && (
            <div className="type-subfields">
              <h4>Fields in each row</h4>
              <FieldList
                nested
                fields={field.subfields as Field[]}
                onChange={sub => set({ subfields: sub as Sub[] })}
              />
            </div>
          )}
        </div>
      )}
    </li>
  );
}

function FieldList({
  fields,
  onChange,
  nested,
}: {
  fields: Field[];
  onChange: (f: Field[]) => void;
  nested?: boolean;
}) {
  const move = (i: number, d: number) => {
    const next = [...fields];
    const j = i + d;
    if (j < 0 || j >= next.length) return;
    [next[i], next[j]] = [next[j]!, next[i]!];
    onChange(next);
  };
  return (
    <>
      <ul className="type-fields">
        {fields.map((f, i) => (
          <FieldRow
            key={i}
            f={f}
            index={i}
            count={fields.length}
            nested={nested}
            onChange={nf => onChange(fields.map((x, k) => (k === i ? nf : x)))}
            onMove={d => move(i, d)}
            onRemove={() => onChange(fields.filter((_, k) => k !== i))}
          />
        ))}
      </ul>
      <button
        type="button"
        className="top-btn"
        disabled={fields.length >= (nested ? 12 : 40)}
        onClick={() => onChange([...fields, blankField()])}
      >
        <Plus size={14} aria-hidden="true" /> Add{" "}
        {nested ? "a field to the row" : "a field"}
      </button>
    </>
  );
}

const newType = (): ContentType => ({
  slug: "",
  singular: "",
  plural: "",
  icon: "",
  supports: ["title", "editor", "excerpt", "thumbnail", "page-attributes"],
  public: true,
  hasArchive: true,
  rewrite: "",
  builtin: false,
  schema: "WebPage",
  archiveTitle: "",
  archiveDescription: "",
  taxonomies: [],
  fields: [],
});

/** Define content types (custom post types), their categories and their fields. */
export function TypesSection({
  openContent,
}: {
  openContent: (slug: string) => void;
}) {
  const [types, setTypes] = useState<ContentType[] | null>(null);
  const [saved, setSaved] = useState("");
  const [sel, setSel] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [note, setNote] = useState("");

  const apply = (list: ContentType[], keep?: string) => {
    setTypes(list);
    setSaved(JSON.stringify(list.map(strip)));
    if (keep)
      setSel(
        Math.max(
          0,
          list.findIndex(t => t.slug === keep)
        )
      );
  };
  useEffect(() => {
    api
      .getTypes()
      .then(r => apply(r.types))
      .catch(e => setError(describeIssues(e)));
  }, []);

  if (!types)
    return error ? (
      <div className="notice error" role="alert">
        {error}
      </div>
    ) : (
      <p className="muted">Loading…</p>
    );

  const t = types[sel];
  const dirty = JSON.stringify(types.map(strip)) !== saved;
  const edit = (patch: Partial<ContentType>) =>
    setTypes(types.map((x, i) => (i === sel ? { ...x, ...patch } : x)));

  const save = () => {
    setBusy(true);
    setError("");
    setNote("");
    // Built-in types are saved only when they carry fields; the server fills in the rest.
    const body = types
      .filter(x => !x.builtin || x.fields.length > 0)
      .map(strip);
    api
      .saveTypes(body)
      .then(r => {
        apply(r.types, t?.slug);
        setNote("Saved. New types and addresses are live straight away.");
      })
      .catch(e => setError(describeIssues(e)))
      .finally(() => setBusy(false));
  };

  const addType = () => {
    setTypes([...types, newType()]);
    setSel(types.length);
  };
  const removeType = () => {
    if (!t) return;
    if (
      !window.confirm(
        `Remove the “${t.plural || "new"}” type? Its entries stay in the database but are no longer shown until you add the type back.`
      )
    )
      return;
    setTypes(types.filter((_, i) => i !== sel));
    setSel(0);
  };

  return (
    <>
      <header className="dash-head">
        <div>
          <h1>Types &amp; fields</h1>
          <p className="muted">
            Create your own content types (listings, team, events…) with
            categories and custom fields: text, numbers, images, galleries and
            repeaters. Then design how they look under Templates.
          </p>
        </div>
        <div className="dash-actions">
          <button className="save-btn" disabled={busy || !dirty} onClick={save}>
            {busy ? "Saving…" : "Save changes"}
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
      <div className="types-layout">
        <nav className="types-list" aria-label="Content types">
          {types.map((x, i) => (
            <button
              key={i}
              className={i === sel ? "active" : ""}
              aria-current={i === sel ? "true" : undefined}
              onClick={() => setSel(i)}
            >
              <strong>{x.plural || "New type"}</strong>
              <small>
                {x.builtin ? "built in" : x.slug || "not saved"} ·{" "}
                {x.fields.length} field
                {x.fields.length === 1 ? "" : "s"}
              </small>
            </button>
          ))}
          <button
            className="types-add"
            onClick={addType}
            disabled={types.length >= 23}
          >
            <Plus size={14} aria-hidden="true" /> New content type
          </button>
        </nav>
        {t && (
          <div className="types-edit">
            {!t.builtin && (
              <section className="dash-card">
                <h2>About this type</h2>
                <div className="types-grid">
                  <label className="field">
                    <span>Singular name</span>
                    <input
                      value={t.singular}
                      maxLength={40}
                      placeholder="Listing"
                      onChange={e =>
                        edit({
                          singular: e.target.value,
                          ...(t.slug === "" ||
                          t.slug ===
                            slugKey(t.singular).replace(/_/g, "").slice(0, 20)
                            ? {
                                slug: slugKey(e.target.value)
                                  .replace(/_/g, "")
                                  .slice(0, 20),
                              }
                            : {}),
                        })
                      }
                    />
                  </label>
                  <label className="field">
                    <span>Plural name</span>
                    <input
                      value={t.plural}
                      maxLength={40}
                      placeholder="Listings"
                      onChange={e => edit({ plural: e.target.value })}
                    />
                  </label>
                  <label className="field">
                    <span>Type key</span>
                    <input
                      value={t.slug}
                      maxLength={20}
                      disabled={savedSlugs(saved).has(t.slug) && t.slug !== ""}
                      onChange={e =>
                        edit({
                          slug: e.target.value
                            .toLowerCase()
                            .replace(/[^a-z0-9_]/g, ""),
                        })
                      }
                    />
                    <small className="muted">
                      Letters, numbers and underscores. Cannot change once
                      saved.
                    </small>
                  </label>
                  <label className="field">
                    <span>Web address</span>
                    <input
                      value={t.rewrite}
                      maxLength={40}
                      placeholder={t.slug || "listings"}
                      onChange={e =>
                        edit({
                          rewrite: e.target.value
                            .toLowerCase()
                            .replace(/[^a-z0-9-]/g, ""),
                        })
                      }
                    />
                    <small className="muted">
                      Entries appear at /{t.rewrite || t.slug || "listings"}
                      /entry-name/
                    </small>
                  </label>
                </div>
                <div className="field check">
                  <label>
                    <input
                      type="checkbox"
                      checked={t.public}
                      onChange={e => edit({ public: e.target.checked })}
                    />{" "}
                    <span>Visible on the website</span>
                  </label>
                </div>
                <div className="field check">
                  <label>
                    <input
                      type="checkbox"
                      checked={t.hasArchive}
                      disabled={!t.public}
                      onChange={e => edit({ hasArchive: e.target.checked })}
                    />{" "}
                    <span>Has a listing page (archive)</span>
                  </label>
                </div>
                <fieldset className="field source-list">
                  <legend>What an entry has</legend>
                  {SUPPORTS.map(([k, label]) => (
                    <label key={k} className="check-row">
                      <input
                        type="checkbox"
                        checked={t.supports.includes(k)}
                        onChange={e =>
                          edit({
                            supports: e.target.checked
                              ? [...t.supports, k]
                              : t.supports.filter(x => x !== k),
                          })
                        }
                      />{" "}
                      <span>{label}</span>
                    </label>
                  ))}
                </fieldset>
              </section>
            )}
            {!t.builtin && (
              <section className="dash-card">
                <h2>Categories</h2>
                <p className="muted">
                  Groups visitors can filter by, such as “Property type” or
                  “Department”.
                </p>
                <ul className="type-fields">
                  {t.taxonomies.map((x, i) => (
                    <li key={i} className="type-field">
                      <div className="types-grid tax">
                        <label className="field">
                          <span>Singular</span>
                          <input
                            value={x.singular}
                            maxLength={40}
                            onChange={e =>
                              edit({
                                taxonomies: t.taxonomies.map((y, k) =>
                                  k === i
                                    ? { ...y, singular: e.target.value }
                                    : y
                                ),
                              })
                            }
                          />
                        </label>
                        <label className="field">
                          <span>Plural</span>
                          <input
                            value={x.plural}
                            maxLength={40}
                            onChange={e =>
                              edit({
                                taxonomies: t.taxonomies.map((y, k) =>
                                  k === i
                                    ? {
                                        ...y,
                                        plural: e.target.value,
                                        ...(y.slug === "" ||
                                        y.slug === slugKey(y.plural)
                                          ? {
                                              slug: slugKey(
                                                e.target.value
                                              ).slice(0, 32),
                                            }
                                          : {}),
                                      }
                                    : y
                                ),
                              })
                            }
                          />
                        </label>
                        <label className="field">
                          <span>Key</span>
                          <input
                            value={x.slug}
                            maxLength={32}
                            onChange={e =>
                              edit({
                                taxonomies: t.taxonomies.map((y, k) =>
                                  k === i
                                    ? {
                                        ...y,
                                        slug: e.target.value
                                          .toLowerCase()
                                          .replace(/[^a-z0-9_]/g, ""),
                                      }
                                    : y
                                ),
                              })
                            }
                          />
                        </label>
                        <button
                          type="button"
                          className="icon-btn"
                          aria-label={`Delete ${x.plural || "category group"}`}
                          onClick={() =>
                            edit({
                              taxonomies: t.taxonomies.filter(
                                (_, k) => k !== i
                              ),
                            })
                          }
                        >
                          <Trash2 size={14} aria-hidden="true" />
                        </button>
                      </div>
                    </li>
                  ))}
                </ul>
                <button
                  type="button"
                  className="top-btn"
                  disabled={t.taxonomies.length >= 6}
                  onClick={() =>
                    edit({
                      taxonomies: [
                        ...t.taxonomies,
                        {
                          slug: "",
                          singular: "",
                          plural: "",
                          hierarchical: true,
                        },
                      ],
                    })
                  }
                >
                  <Plus size={14} aria-hidden="true" /> Add a category group
                </button>
              </section>
            )}
            <section className="dash-card">
              <h2>Search &amp; schema</h2>
              <p className="muted">
                How these pages appear in Google. Each entry also has its own
                search title, description and image under Content.
              </p>
              <div className="field">
                <label>
                  <span>Structured data (schema.org) for each entry</span>
                  <select
                    value={t.schema}
                    onChange={e =>
                      edit({ schema: e.target.value as ContentType["schema"] })
                    }
                  >
                    <option value="WebPage">Web page (default)</option>
                    <option value="Article">
                      Article (news, posts, case studies)
                    </option>
                    <option value="Service">Service (things you offer)</option>
                  </select>
                </label>
                <small className="muted">
                  Breadcrumbs and your business details are always included.
                </small>
              </div>
              {t.public && t.hasArchive && (
                <>
                  <div className="field">
                    <label>
                      <span>Listing page title</span>
                      <input
                        value={t.archiveTitle}
                        maxLength={70}
                        placeholder={t.plural}
                        onChange={e => edit({ archiveTitle: e.target.value })}
                      />
                    </label>
                    <small className="muted">
                      {t.archiveTitle.length}/60 recommended
                    </small>
                  </div>
                  <div className="field">
                    <label>
                      <span>Listing page description</span>
                      <textarea
                        rows={3}
                        maxLength={300}
                        value={t.archiveDescription}
                        onChange={e =>
                          edit({ archiveDescription: e.target.value })
                        }
                      />
                    </label>
                    <small className="muted">
                      {t.archiveDescription.length}/160 recommended
                    </small>
                  </div>
                </>
              )}
            </section>
            <section className="dash-card">
              <h2>Fields</h2>
              <p className="muted">
                {t.builtin
                  ? `Extra details for ${t.plural.toLowerCase()}, on top of the title, text and image.`
                  : "The details each entry holds. Templates can show any of them."}
              </p>
              <FieldList
                fields={t.fields}
                onChange={f => edit({ fields: f })}
              />
            </section>
            <div className="dash-actions">
              {t.slug && savedSlugs(saved).has(t.slug) && (
                <button className="top-btn" onClick={() => openContent(t.slug)}>
                  Manage {t.plural.toLowerCase()}
                </button>
              )}
              {!t.builtin && (
                <button className="top-btn danger" onClick={removeType}>
                  <Trash2 size={14} aria-hidden="true" /> Remove this type
                </button>
              )}
            </div>
          </div>
        )}
      </div>
    </>
  );
}

/** What gets sent: only the definition, never counts or term lists. */
function strip(t: ContentType) {
  const { count: _c, taxonomyTerms: _t, ...rest } = t;
  void _c;
  void _t;
  return rest;
}

function savedSlugs(saved: string): Set<string> {
  try {
    return new Set((JSON.parse(saved) as { slug: string }[]).map(x => x.slug));
  } catch {
    return new Set();
  }
}
