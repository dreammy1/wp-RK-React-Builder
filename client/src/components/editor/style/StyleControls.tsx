import { useId, type ReactNode } from "react";
import { ChevronDown } from "lucide-react";

/**
 * Small, accessible building blocks shared by the style panels.
 *
 * They exist so every control in the inspector behaves the same way (label association, help text,
 * a consistent "unset" affordance) instead of each panel re-implementing a <select>. None of them
 * knows anything about a particular block or style field.
 */

/** A labelled row that keeps the label, control and help text aligned like a real design tool. */
export function Control({
  label,
  hint,
  htmlFor,
  children,
  inline,
}: {
  label: string;
  hint?: string;
  htmlFor?: string;
  children: ReactNode;
  inline?: boolean;
}) {
  return (
    <div className={`sx-control${inline ? " sx-inline" : ""}`}>
      <label className="sx-label" htmlFor={htmlFor}>
        {label}
      </label>
      <div className="sx-input">{children}</div>
      {hint && <small className="sx-hint">{hint}</small>}
    </div>
  );
}

/** A collapsible group. Native <details> keeps it keyboard- and screen-reader-friendly for free. */
export function Section({
  title,
  children,
  defaultOpen = false,
  badge,
}: {
  title: string;
  children: ReactNode;
  defaultOpen?: boolean;
  badge?: ReactNode;
}) {
  return (
    <details className="sx-section" open={defaultOpen}>
      <summary>
        <span>{title}</span>
        {badge}
        <ChevronDown size={14} aria-hidden="true" className="sx-chevron" />
      </summary>
      <div className="sx-section-body">{children}</div>
    </details>
  );
}

/**
 * A dropdown whose empty option means "inherit / not set". Central to the whole style model: an
 * unset value emits no CSS, which is how a block keeps its designed default until you change it.
 */
export function SelectField({
  label,
  value,
  options,
  onChange,
  unsetLabel = "Default",
  hint,
}: {
  label: string;
  value: string | number | undefined;
  options:
    | readonly (string | number)[]
    | readonly { readonly value: string | number; readonly label: string }[];
  onChange: (v: string | undefined) => void;
  unsetLabel?: string;
  hint?: string;
}) {
  const id = useId();
  const opts = options.map(o =>
    typeof o === "object" ? o : { value: o, label: String(o) }
  );
  return (
    <Control label={label} htmlFor={id} hint={hint}>
      <select
        id={id}
        className="sx-select"
        value={value === undefined ? "" : String(value)}
        onChange={e => onChange(e.target.value === "" ? undefined : e.target.value)}
      >
        <option value="">{unsetLabel}</option>
        {opts.map(o => (
          <option key={String(o.value)} value={String(o.value)}>
            {o.label}
          </option>
        ))}
      </select>
    </Control>
  );
}

/** A numeric field that is "unset" when empty, so clearing it removes the override. */
export function NumberField({
  label,
  value,
  onChange,
  min,
  max,
  step = 1,
  suffix,
  hint,
}: {
  label: string;
  value: number | undefined;
  onChange: (v: number | undefined) => void;
  min?: number;
  max?: number;
  step?: number;
  suffix?: string;
  hint?: string;
}) {
  const id = useId();
  return (
    <Control label={label} htmlFor={id} hint={hint}>
      <div className="sx-number">
        <input
          id={id}
          className="sx-input-el"
          type="number"
          value={value ?? ""}
          min={min}
          max={max}
          step={step}
          onChange={e =>
            onChange(e.target.value === "" ? undefined : Number(e.target.value))
          }
        />
        {suffix && <span className="sx-suffix">{suffix}</span>}
      </div>
    </Control>
  );
}

/** A colour swatch + hex entry, with a clear button when a value is set. */
export function ColorField({
  label,
  value,
  onChange,
  hint,
  allowTransparent,
}: {
  label: string;
  value: string | undefined;
  onChange: (v: string | undefined) => void;
  hint?: string;
  allowTransparent?: boolean;
}) {
  const id = useId();
  const isTransparent = value === "transparent";
  return (
    <Control label={label} htmlFor={id} hint={hint}>
      <div className="sx-color">
        <input
          id={id}
          type="color"
          value={isTransparent ? "#ffffff" : value || "#ffffff"}
          aria-label={label}
          onChange={e => onChange(e.target.value.toUpperCase())}
        />
        <input
          className="sx-input-el sx-hex"
          type="text"
          spellCheck={false}
          maxLength={7}
          placeholder="#RRGGBB"
          value={value ?? ""}
          aria-label={`${label} hex value`}
          onChange={e => {
            const v = e.target.value.trim();
            onChange(v === "" ? undefined : v.toUpperCase());
          }}
        />
        {allowTransparent && (
          <button
            type="button"
            className={`sx-mini${isTransparent ? " on" : ""}`}
            aria-pressed={isTransparent}
            title="Transparent"
            onClick={() => onChange(isTransparent ? undefined : "transparent")}
          >
            ⊘
          </button>
        )}
        {value && (
          <button
            type="button"
            className="sx-mini"
            title="Clear"
            aria-label={`Clear ${label}`}
            onClick={() => onChange(undefined)}
          >
            ×
          </button>
        )}
      </div>
    </Control>
  );
}

/** Four values at once (top/right/bottom/left) with an optional link to keep them equal. */
export function BoxSides({
  label,
  values,
  onChange,
  presets,
}: {
  label: string;
  values: Partial<Record<"top" | "right" | "bottom" | "left", string>>;
  onChange: (patch: Partial<Record<"top" | "right" | "bottom" | "left", string | undefined>>) => void;
  presets: readonly string[];
}) {
  const sides = ["top", "right", "bottom", "left"] as const;
  return (
    <div className="sx-box">
      <div className="sx-box-head">
        <span className="sx-label">{label}</span>
        <select
          className="sx-select sx-preset"
          value=""
          aria-label={`${label} preset`}
          onChange={e => {
            const v = e.target.value;
            if (!v) return;
            onChange({ top: v, right: v, bottom: v, left: v });
          }}
        >
          <option value="">Preset…</option>
          {presets.map(p => (
            <option key={p} value={p}>
              {p}
            </option>
          ))}
        </select>
      </div>
      <div className="sx-sides">
        {sides.map(side => (
          <label key={side} className="sx-side">
            <span>{side[0]!.toUpperCase()}</span>
            <input
              type="text"
              inputMode="numeric"
              className="sx-input-el"
              placeholder="—"
              aria-label={`${label} ${side}`}
              value={values[side] ?? ""}
              onChange={e =>
                onChange({ [side]: e.target.value.trim() || undefined })
              }
            />
          </label>
        ))}
      </div>
    </div>
  );
}

/** A simple on/off switch rendered as a checkbox with a consistent look. */
export function ToggleField({
  label,
  checked,
  onChange,
  hint,
}: {
  label: string;
  checked: boolean;
  onChange: (v: boolean) => void;
  hint?: string;
}) {
  const id = useId();
  return (
    <div className="sx-control sx-toggle">
      <label htmlFor={id}>
        <input
          id={id}
          type="checkbox"
          checked={checked}
          onChange={e => onChange(e.target.checked)}
        />
        <span>{label}</span>
      </label>
      {hint && <small className="sx-hint">{hint}</small>}
    </div>
  );
}
