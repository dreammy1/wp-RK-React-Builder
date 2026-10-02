import { useId, useState } from "react";
import type { FieldDef } from "@/blocks/fields";
import type { MediaItem } from "@/lib/schema/api";
import { sourceOptions, useDyn } from "@/render/dyn";
import { CatalogCardsEditor } from "./CatalogCardsEditor";
import { MediaPicker } from "./MediaPicker";

type Props = {
  fields: FieldDef[];
  values: Record<string, unknown>;
  errors: Record<string, string>;
  onChange: (patch: Record<string, unknown>) => void;
};

export function FieldsForm({ fields, values, errors, onChange }: Props) {
  const uid = useId();
  const [picking, setPicking] = useState(false);
  const dyn = useDyn();
  // The content type a dynamic block works on: its own choice, else the template's.
  const own = String(values.postType ?? "current");
  const typeSlug = own === "current" || own === "" ? dyn.postType : own;
  const type = dyn.types.find(t => t.slug === typeSlug);
  return (
    <div className="field-stack">
      {fields
        .filter(f => !f.showIf || f.showIf(values))
        .map(f => {
          if (f.kind === "group")
            return (
              <h4 className="field-group" key={`group-${f.label}`}>
                {f.label}
              </h4>
            );
          const id = `${uid}-${f.key}`;
          const err = errors[f.key];
          const describedBy =
            [err ? `${id}-err` : "", "help" in f && f.help ? `${id}-help` : ""]
              .filter(Boolean)
              .join(" ") || undefined;
          const value = values[f.key];
          const help =
            "help" in f && f.help ? (
              <small id={`${id}-help`} className="help">
                {f.help}
              </small>
            ) : null;
          const error = err ? (
            <small id={`${id}-err`} className="form-error" role="alert">
              {err}
            </small>
          ) : null;
          switch (f.kind) {
            case "text":
            case "url":
              return (
                <div className="field" key={f.key}>
                  <label htmlFor={id}>
                    <span>{f.label}</span>
                  </label>
                  <input
                    id={id}
                    type={f.kind === "url" ? "text" : "text"}
                    inputMode={f.kind === "url" ? "url" : undefined}
                    maxLength={f.maxLength}
                    value={String(value ?? "")}
                    aria-invalid={!!err}
                    aria-describedby={describedBy}
                    onChange={e => onChange({ [f.key]: e.target.value })}
                  />
                  {help}
                  {error}
                </div>
              );
            case "textarea":
              return (
                <div className="field" key={f.key}>
                  <label htmlFor={id}>
                    <span>{f.label}</span>
                  </label>
                  <textarea
                    id={id}
                    rows={5}
                    maxLength={f.maxLength}
                    value={String(value ?? "")}
                    aria-invalid={!!err}
                    aria-describedby={describedBy}
                    onChange={e => onChange({ [f.key]: e.target.value })}
                  />
                  {help}
                  {error}
                </div>
              );
            case "number":
              return (
                <div className="field" key={f.key}>
                  <label htmlFor={id}>
                    <span>{f.label}</span>
                  </label>
                  <input
                    id={id}
                    type="number"
                    min={f.min}
                    max={f.max}
                    value={typeof value === "number" ? value : ""}
                    aria-invalid={!!err}
                    aria-describedby={describedBy}
                    onChange={e =>
                      onChange({
                        [f.key]:
                          e.target.value === "" ? 0 : Number(e.target.value),
                      })
                    }
                  />
                  {help}
                  {error}
                </div>
              );
            case "select":
              return (
                <div className="field" key={f.key}>
                  <label htmlFor={id}>
                    <span>{f.label}</span>
                  </label>
                  <select
                    id={id}
                    value={String(value)}
                    aria-invalid={!!err}
                    aria-describedby={describedBy}
                    onChange={e =>
                      onChange({
                        [f.key]: f.numeric
                          ? Number(e.target.value)
                          : e.target.value,
                      })
                    }
                  >
                    {f.options.map(o => (
                      <option key={o.value} value={String(o.value)}>
                        {o.label}
                      </option>
                    ))}
                  </select>
                  {error}
                </div>
              );
            case "catalogCards":
              return (
                <CatalogCardsEditor
                  key={f.key}
                  uid={id}
                  items={String(values.items ?? "")}
                  modals={String(values.modals ?? "")}
                  modalLabel={String(values.modalLabel ?? "")}
                  modalCta={String(values.modalCta ?? "")}
                  errors={errors}
                  onChange={onChange}
                />
              );
            case "dynSource": {
              const opts = sourceOptions(type, f.accept);
              const current = String(value ?? "");
              const known = opts.some(o => o.value === current);
              return (
                <div className="field" key={f.key}>
                  <label htmlFor={id}>
                    <span>{f.label}</span>
                  </label>
                  <select
                    id={id}
                    value={current}
                    aria-invalid={!!err}
                    aria-describedby={describedBy}
                    onChange={e => onChange({ [f.key]: e.target.value })}
                  >
                    {!known && current !== "" && (
                      <option value={current}>{current} (not found)</option>
                    )}
                    {opts.map(o => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </select>
                  {!type && (
                    <small className="help">
                      Open this block inside a template to pick the entry&apos;s
                      fields.
                    </small>
                  )}
                  {help}
                  {error}
                </div>
              );
            }
            case "dynSources": {
              const opts = sourceOptions(type, "scalar").filter(
                o => o.value !== "title" && o.value !== "content"
              );
              const on = new Set(
                String(value ?? "")
                  .split(",")
                  .map(x => x.trim())
                  .filter(Boolean)
              );
              const toggle = (v: string, checked: boolean) => {
                const next = new Set(on);
                if (checked) next.add(v);
                else next.delete(v);
                onChange({
                  [f.key]: opts
                    .map(o => o.value)
                    .filter(x => next.has(x))
                    .join(","),
                });
              };
              return (
                <fieldset className="field source-list" key={f.key}>
                  <legend>{f.label}</legend>
                  {opts.map(o => (
                    <label key={o.value} className="check-row">
                      <input
                        type="checkbox"
                        checked={on.has(o.value)}
                        onChange={e => toggle(o.value, e.target.checked)}
                      />{" "}
                      <span>{o.label}</span>
                    </label>
                  ))}
                  {help}
                  {error}
                </fieldset>
              );
            }
            case "postType":
              return (
                <div className="field" key={f.key}>
                  <label htmlFor={id}>
                    <span>{f.label}</span>
                  </label>
                  <select
                    id={id}
                    value={String(value ?? "current")}
                    onChange={e =>
                      onChange({
                        [f.key]: e.target.value,
                        taxonomy: "",
                        term: "",
                        templateId: 0,
                      })
                    }
                  >
                    <option value="current">
                      {dyn.postType
                        ? "The type this template shows"
                        : "Current type (inside a template)"}
                    </option>
                    {dyn.types.map(t => (
                      <option key={t.slug} value={t.slug}>
                        {t.plural}
                      </option>
                    ))}
                  </select>
                  {error}
                </div>
              );
            case "taxonomy": {
              const taxes = type?.taxonomyTerms ?? [];
              if (taxes.length === 0) return null;
              return (
                <div className="field" key={f.key}>
                  <label htmlFor={id}>
                    <span>{f.label}</span>
                  </label>
                  <select
                    id={id}
                    value={String(value ?? "")}
                    onChange={e =>
                      onChange({ [f.key]: e.target.value, term: "" })
                    }
                  >
                    <option value="">All entries</option>
                    {taxes.map(t => (
                      <option key={t.slug} value={t.slug}>
                        {t.name}
                      </option>
                    ))}
                  </select>
                  {error}
                </div>
              );
            }
            case "taxonomyTerm": {
              const tax = (type?.taxonomyTerms ?? []).find(
                t => t.slug === String(values.taxonomy ?? "")
              );
              if (!tax) return null;
              return (
                <div className="field" key={f.key}>
                  <label htmlFor={id}>
                    <span>{f.label}</span>
                  </label>
                  <select
                    id={id}
                    value={String(value ?? "")}
                    onChange={e => onChange({ [f.key]: e.target.value })}
                  >
                    <option value="">Any</option>
                    {tax.terms.map(t => (
                      <option key={t.slug} value={t.slug}>
                        {t.name}
                      </option>
                    ))}
                  </select>
                  {error}
                </div>
              );
            }
            case "loopTemplate": {
              const cards = dyn.templates.filter(
                t => t.kind === "loop" && t.postType === typeSlug
              );
              return (
                <div className="field" key={f.key}>
                  <label htmlFor={id}>
                    <span>{f.label}</span>
                  </label>
                  <select
                    id={id}
                    value={String(value ?? 0)}
                    onChange={e =>
                      onChange({ [f.key]: Number(e.target.value) })
                    }
                  >
                    <option value="0">Built-in card</option>
                    {cards.map(t => (
                      <option key={t.id} value={t.id}>
                        {t.title}
                        {t.live ? "" : " (not published yet)"}
                      </option>
                    ))}
                  </select>
                  {help}
                  {error}
                </div>
              );
            }
            case "checkbox":
              return (
                <div className="field check" key={f.key}>
                  <label htmlFor={id}>
                    <input
                      id={id}
                      type="checkbox"
                      checked={Boolean(value)}
                      onChange={e => onChange({ [f.key]: e.target.checked })}
                    />{" "}
                    <span>{f.label}</span>
                  </label>
                  {error}
                </div>
              );
            case "media":
              return (
                <div className="field" key={f.key}>
                  <span className="field-label" id={`${id}-lbl`}>
                    {f.label}
                  </span>
                  {typeof value === "string" && value && (
                    <img className="media-preview" src={value} alt="" />
                  )}
                  <button
                    type="button"
                    className="top-btn"
                    onClick={() => setPicking(true)}
                    aria-describedby={`${id}-lbl`}
                  >
                    Choose from media library
                  </button>
                  {f.optional && typeof value === "string" && value && (
                    <button
                      type="button"
                      className="top-btn"
                      onClick={() =>
                        onChange({
                          [f.key]: undefined,
                          ...(f.idKey ? { [f.idKey]: undefined } : {}),
                        })
                      }
                    >
                      Remove image
                    </button>
                  )}
                  {error}
                  {picking && (
                    <MediaPicker
                      onClose={() => setPicking(false)}
                      onSelect={(m: MediaItem) => {
                        if (f.idKey) {
                          onChange({ [f.idKey]: m.id, [f.key]: m.url });
                          setPicking(false);
                          return;
                        }
                        const patch: Record<string, unknown> = {
                          mediaId: m.id,
                          url: m.url,
                          width: m.width,
                          height: m.height,
                          srcset: m.srcset ?? "",
                        };
                        // A new image invalidates the old description; prefer the library's alt text.
                        if (m.alt) patch.alt = m.alt;
                        onChange(patch);
                        setPicking(false);
                      }}
                    />
                  )}
                </div>
              );
          }
        })}
    </div>
  );
}
