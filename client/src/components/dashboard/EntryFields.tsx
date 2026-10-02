import { useState } from "react";
import { ArrowDown, ArrowUp, ImagePlus, Plus, Trash2, X } from "lucide-react";
import type { EntryField, EntryMedia } from "@/lib/schema/api";
import { MediaPicker } from "../editor/MediaPicker";

type SubField = EntryField["subfields"][number];
type AnyField = EntryField | SubField;

export type Row = Record<string, unknown>;

const asMedia = (v: unknown): EntryMedia | null =>
  v && typeof v === "object" && "id" in v && "url" in v
    ? (v as EntryMedia)
    : null;

/** Empty value for a field of this type (what a new entry or a new repeater row starts with). */
export function blankValue(f: AnyField): unknown {
  switch (f.type) {
    case "toggle":
      return f.default === "1";
    case "gallery":
    case "repeater":
      return [];
    case "image":
      return null;
    case "number":
      return f.default !== "" && !Number.isNaN(Number(f.default))
        ? Number(f.default)
        : null;
    default:
      return f.default;
  }
}

/** Entry values -> what the API accepts (media become their IDs). */
export function toPayload(f: AnyField, v: unknown): unknown {
  switch (f.type) {
    case "image":
      return asMedia(v)?.id ?? null;
    case "gallery":
      return (Array.isArray(v) ? v : [])
        .map(asMedia)
        .filter(Boolean)
        .map(m => m!.id);
    case "repeater": {
      const subs = "subfields" in f ? f.subfields : [];
      return (Array.isArray(v) ? v : []).map((row: Row) =>
        Object.fromEntries(subs.map(s => [s.key, toPayload(s, row[s.key])]))
      );
    }
    default:
      return v;
  }
}

function ImageInput({
  id,
  value,
  onChange,
}: {
  id: string;
  value: EntryMedia | null;
  onChange: (m: EntryMedia | null) => void;
}) {
  const [picking, setPicking] = useState(false);
  return (
    <div className="entry-image">
      {value ? (
        <img src={value.url} alt="" />
      ) : (
        <span className="entry-image-empty" aria-hidden="true" />
      )}
      <div className="dash-actions">
        <button
          id={id}
          type="button"
          className="top-btn"
          onClick={() => setPicking(true)}
        >
          <ImagePlus size={14} aria-hidden="true" />{" "}
          {value ? "Change" : "Choose or upload"}
        </button>
        {value && (
          <button
            type="button"
            className="top-btn"
            onClick={() => onChange(null)}
          >
            <X size={14} aria-hidden="true" /> Remove
          </button>
        )}
      </div>
      {picking && (
        <MediaPicker
          onClose={() => setPicking(false)}
          onSelect={m => {
            onChange(m as EntryMedia);
            setPicking(false);
          }}
        />
      )}
    </div>
  );
}

function GalleryInput({
  value,
  onChange,
}: {
  value: EntryMedia[];
  onChange: (v: EntryMedia[]) => void;
}) {
  const [picking, setPicking] = useState(false);
  const move = (i: number, d: number) => {
    const next = [...value];
    const j = i + d;
    if (j < 0 || j >= next.length) return;
    [next[i], next[j]] = [next[j]!, next[i]!];
    onChange(next);
  };
  return (
    <div className="entry-gallery">
      <ul>
        {value.map((m, i) => (
          <li key={`${m.id}-${i}`}>
            <img src={m.url} alt="" />
            <div className="entry-gallery-tools">
              <button
                type="button"
                aria-label="Move earlier"
                disabled={i === 0}
                onClick={() => move(i, -1)}
              >
                <ArrowUp size={12} aria-hidden="true" />
              </button>
              <button
                type="button"
                aria-label="Move later"
                disabled={i === value.length - 1}
                onClick={() => move(i, 1)}
              >
                <ArrowDown size={12} aria-hidden="true" />
              </button>
              <button
                type="button"
                aria-label="Remove photo"
                onClick={() => onChange(value.filter((_, k) => k !== i))}
              >
                <X size={12} aria-hidden="true" />
              </button>
            </div>
          </li>
        ))}
      </ul>
      <button
        type="button"
        className="top-btn"
        disabled={value.length >= 60}
        onClick={() => setPicking(true)}
      >
        <Plus size={14} aria-hidden="true" /> Add photo
      </button>
      {picking && (
        <MediaPicker
          onClose={() => setPicking(false)}
          onSelect={m => {
            onChange([...value, m as EntryMedia]);
            setPicking(false);
          }}
        />
      )}
    </div>
  );
}

function RepeaterInput({
  field,
  value,
  onChange,
  uid,
}: {
  field: EntryField;
  value: Row[];
  onChange: (v: Row[]) => void;
  uid: string;
}) {
  const blank = (): Row =>
    Object.fromEntries(field.subfields.map(s => [s.key, blankValue(s)]));
  const set = (i: number, key: string, v: unknown) =>
    onChange(value.map((r, k) => (k === i ? { ...r, [key]: v } : r)));
  const move = (i: number, d: number) => {
    const next = [...value];
    const j = i + d;
    if (j < 0 || j >= next.length) return;
    [next[i], next[j]] = [next[j]!, next[i]!];
    onChange(next);
  };
  return (
    <div className="entry-repeater">
      {value.map((row, i) => (
        <fieldset key={i} className="entry-row">
          <legend>
            {field.label} {i + 1}
          </legend>
          <div className="entry-row-tools">
            <button
              type="button"
              aria-label={`Move row ${i + 1} up`}
              disabled={i === 0}
              onClick={() => move(i, -1)}
            >
              <ArrowUp size={12} aria-hidden="true" />
            </button>
            <button
              type="button"
              aria-label={`Move row ${i + 1} down`}
              disabled={i === value.length - 1}
              onClick={() => move(i, 1)}
            >
              <ArrowDown size={12} aria-hidden="true" />
            </button>
            <button
              type="button"
              aria-label={`Delete row ${i + 1}`}
              onClick={() => onChange(value.filter((_, k) => k !== i))}
            >
              <Trash2 size={12} aria-hidden="true" />
            </button>
          </div>
          <div className="entry-row-fields">
            {field.subfields.map(sf => (
              <FieldInput
                key={sf.key}
                field={sf}
                uid={`${uid}-${i}`}
                value={row[sf.key]}
                onChange={v => set(i, sf.key, v)}
              />
            ))}
          </div>
        </fieldset>
      ))}
      <button
        type="button"
        className="top-btn"
        disabled={value.length >= 50}
        onClick={() => onChange([...value, blank()])}
      >
        <Plus size={14} aria-hidden="true" /> Add row
      </button>
    </div>
  );
}

/** One input for one field of a content type. */
export function FieldInput({
  field,
  value,
  onChange,
  uid,
  error,
}: {
  field: AnyField;
  value: unknown;
  onChange: (v: unknown) => void;
  uid: string;
  error?: string;
}) {
  const id = `${uid}-${field.key}`;
  const label = (
    <label htmlFor={id}>
      <span>
        {field.label}
        {field.required ? " *" : ""}
      </span>
    </label>
  );
  const help = field.help ? (
    <small className="muted">{field.help}</small>
  ) : null;
  const err = error ? (
    <small className="form-error" role="alert">
      {error}
    </small>
  ) : null;
  const str = typeof value === "string" ? value : "";
  let input: React.ReactNode;
  switch (field.type) {
    case "textarea":
      input = (
        <textarea
          id={id}
          rows={4}
          value={str}
          onChange={e => onChange(e.target.value)}
        />
      );
      break;
    case "number":
      input = (
        <input
          id={id}
          type="number"
          min={field.min ?? undefined}
          max={field.max ?? undefined}
          step="any"
          value={typeof value === "number" ? value : ""}
          onChange={e =>
            onChange(e.target.value === "" ? null : Number(e.target.value))
          }
        />
      );
      break;
    case "email":
    case "url":
    case "text":
      input = (
        <input
          id={id}
          type={field.type === "email" ? "email" : "text"}
          inputMode={field.type === "url" ? "url" : undefined}
          placeholder={field.type === "url" ? "https://" : undefined}
          value={str}
          onChange={e => onChange(e.target.value)}
        />
      );
      break;
    case "date":
      input = (
        <input
          id={id}
          type="date"
          value={str}
          onChange={e => onChange(e.target.value)}
        />
      );
      break;
    case "color":
      input = (
        <div className="entry-color">
          <input
            id={id}
            type="color"
            value={/^#[0-9a-f]{6}$/i.test(str) ? str : "#8b5a2b"}
            onChange={e => onChange(e.target.value)}
          />
          <span>{str || "Not set"}</span>
          {str && (
            <button
              type="button"
              className="top-btn"
              onClick={() => onChange("")}
            >
              Clear
            </button>
          )}
        </div>
      );
      break;
    case "select":
      input = (
        <select id={id} value={str} onChange={e => onChange(e.target.value)}>
          <option value="">Choose…</option>
          {field.options.map(o => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      );
      break;
    case "toggle":
      return (
        <div className="field check">
          <label htmlFor={id}>
            <input
              id={id}
              type="checkbox"
              checked={value === true}
              onChange={e => onChange(e.target.checked)}
            />{" "}
            <span>{field.label}</span>
          </label>
          {help}
          {err}
        </div>
      );
    case "image":
      input = (
        <ImageInput
          id={id}
          value={asMedia(value)}
          onChange={m => onChange(m)}
        />
      );
      break;
    case "gallery":
      input = (
        <GalleryInput
          value={(Array.isArray(value) ? value : [])
            .map(asMedia)
            .filter((m): m is EntryMedia => m !== null)}
          onChange={onChange}
        />
      );
      break;
    case "repeater":
      input = (
        <RepeaterInput
          field={field as EntryField}
          uid={id}
          value={Array.isArray(value) ? (value as Row[]) : []}
          onChange={onChange}
        />
      );
      break;
  }
  return (
    <div className="field">
      {label}
      {input}
      {help}
      {err}
    </div>
  );
}
