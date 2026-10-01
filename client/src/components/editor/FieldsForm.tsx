import { useId, useState } from "react";
import type { FieldDef } from "@/blocks/fields";
import type { MediaItem } from "@/lib/schema/api";
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
  return (
    <div className="field-stack">
      {fields.map(f => {
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
                {error}
                {picking && (
                  <MediaPicker
                    onClose={() => setPicking(false)}
                    onSelect={(m: MediaItem) => {
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
