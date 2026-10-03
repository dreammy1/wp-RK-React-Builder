import { useId, useState } from "react";
import {
  BUTTON_STYLES,
  FONTS,
  HEADING_WEIGHTS,
  RADII,
  type ThemeConfig,
} from "@/lib/schema/theme";
import { THEME_PRESETS } from "@/lib/schema/themePresets";
import { MediaPicker } from "./MediaPicker";

function ColorField({
  label,
  value,
  onChange,
  disabled,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  disabled: boolean;
}) {
  const id = useId();
  return (
    <div className="color-field">
      <label htmlFor={id}>{label}</label>
      <div>
        <input
          id={id}
          type="color"
          value={value}
          disabled={disabled}
          onChange={e => onChange(e.target.value.toUpperCase())}
        />
        <code>{value}</code>
      </div>
    </div>
  );
}

/** A color that is optional: shows the fallback until set, and can be cleared. */
function OptionalColor({
  label,
  value,
  fallback,
  onChange,
  disabled,
}: {
  label: string;
  value: string | undefined;
  fallback: string;
  onChange: (v: string | undefined) => void;
  disabled: boolean;
}) {
  return (
    <div>
      <ColorField
        label={label}
        value={value ?? fallback}
        disabled={disabled}
        onChange={onChange}
      />
      {value && !disabled && (
        <button
          type="button"
          className="top-btn"
          onClick={() => onChange(undefined)}
        >
          Reset {label.toLowerCase()}
        </button>
      )}
    </div>
  );
}

const RADIUS_LABEL = { square: "Square", soft: "Soft", round: "Round" };

export function ThemePanel({
  theme,
  canEdit,
  onPatch,
}: {
  theme: ThemeConfig;
  canEdit: boolean;
  onPatch: (p: Partial<ThemeConfig>) => void;
}) {
  const uid = useId();
  const [picking, setPicking] = useState(false);
  const social = theme.social ?? {};
  return (
    <div className="theme-panel">
      <div className="eyebrow">global / tokens</div>
      <h2>Theme system</h2>
      <p>One config for every page. Changes here apply site-wide when saved.</p>
      {!canEdit && (
        <p className="readonly-note" role="note">
          Only administrators can change the global theme.
        </p>
      )}
      <fieldset className="field theme-presets" disabled={!canEdit}>
        <legend>Style presets</legend>
        <div className="preset-row">
          {THEME_PRESETS.map(p => (
            <button
              key={p.name}
              type="button"
              className="top-btn preset"
              title={p.note}
              aria-label={`Apply the ${p.name} style: ${p.note}`}
              onClick={() => onPatch({ ...p.style })}
            >
              <span
                className="preset-dots"
                aria-hidden="true"
                style={{
                  ["--a" as string]: p.style.primary,
                  ["--b" as string]: p.style.dark,
                }}
              />
              {p.name}
            </button>
          ))}
        </div>
      </fieldset>
      <ColorField
        label="Primary signal"
        value={theme.primary}
        disabled={!canEdit}
        onChange={v => onPatch({ primary: v })}
      />
      <ColorField
        label="Canvas background"
        value={theme.bg}
        disabled={!canEdit}
        onChange={v => onPatch({ bg: v })}
      />
      <ColorField
        label="Ink color"
        value={theme.ink}
        disabled={!canEdit}
        onChange={v => onPatch({ ink: v })}
      />
      <OptionalColor
        label="Accent"
        value={theme.accent}
        fallback="#A97C50"
        disabled={!canEdit}
        onChange={v => onPatch({ accent: v })}
      />
      <OptionalColor
        label="Dark (header, footer, buttons)"
        value={theme.dark}
        fallback="#131313"
        disabled={!canEdit}
        onChange={v => onPatch({ dark: v })}
      />
      <OptionalColor
        label="Soft background (cards)"
        value={theme.surface}
        fallback="#F1EADF"
        disabled={!canEdit}
        onChange={v => onPatch({ surface: v })}
      />
      <div className="field">
        <label htmlFor={`${uid}-font`}>
          <span>Body font</span>
        </label>
        <select
          id={`${uid}-font`}
          value={theme.bodyFont ?? theme.font}
          disabled={!canEdit}
          onChange={e =>
            onPatch({
              font: e.target.value as ThemeConfig["font"],
              bodyFont: e.target.value as ThemeConfig["font"],
            })
          }
        >
          {FONTS.map(f => (
            <option key={f}>{f}</option>
          ))}
        </select>
      </div>
      <div className="field">
        <label htmlFor={`${uid}-hfont`}>
          <span>Heading font</span>
        </label>
        <select
          id={`${uid}-hfont`}
          value={theme.headingFont ?? ""}
          disabled={!canEdit}
          onChange={e =>
            onPatch({
              headingFont: (e.target.value || undefined) as
                ThemeConfig["headingFont"] | undefined,
            })
          }
        >
          <option value="">As designed</option>
          {FONTS.map(f => (
            <option key={f}>{f}</option>
          ))}
        </select>
      </div>
      <div className="field">
        <label htmlFor={`${uid}-hw`}>
          <span>Heading weight</span>
        </label>
        <select
          id={`${uid}-hw`}
          value={theme.headingWeight ?? ""}
          disabled={!canEdit}
          onChange={e =>
            onPatch({
              headingWeight: e.target.value
                ? Number(e.target.value)
                : undefined,
            })
          }
        >
          <option value="">As designed</option>
          {HEADING_WEIGHTS.map(w => (
            <option key={w} value={w}>
              {w}
            </option>
          ))}
        </select>
      </div>
      <div className="field">
        <label htmlFor={`${uid}-rad`}>
          <span>Corners</span>
        </label>
        <select
          id={`${uid}-rad`}
          value={theme.radius ?? ""}
          disabled={!canEdit}
          onChange={e =>
            onPatch({
              radius: (e.target.value || undefined) as
                ThemeConfig["radius"] | undefined,
            })
          }
        >
          <option value="">As designed</option>
          {RADII.map(r => (
            <option key={r} value={r}>
              {RADIUS_LABEL[r]}
            </option>
          ))}
        </select>
      </div>
      <div className="field">
        <label htmlFor={`${uid}-bs`}>
          <span>Buttons</span>
        </label>
        <select
          id={`${uid}-bs`}
          value={theme.buttonStyle ?? "solid"}
          disabled={!canEdit}
          onChange={e =>
            onPatch({
              buttonStyle: (e.target.value === "solid"
                ? undefined
                : e.target.value) as ThemeConfig["buttonStyle"] | undefined,
            })
          }
        >
          {BUTTON_STYLES.map(b => (
            <option key={b} value={b}>
              {b === "solid" ? "Solid" : "Outline"}
            </option>
          ))}
        </select>
      </div>
      <div className="field">
        <span className="field-label">Logo</span>
        {theme.logoUrl && (
          <img
            className="media-preview"
            src={theme.logoUrl}
            alt="Current logo"
          />
        )}
        <button
          type="button"
          className="top-btn"
          disabled={!canEdit}
          onClick={() => setPicking(true)}
        >
          Choose logo
        </button>
        {picking && (
          <MediaPicker
            onClose={() => setPicking(false)}
            onSelect={m => {
              onPatch({ logoMediaId: m.id, logoUrl: m.url });
              setPicking(false);
            }}
          />
        )}
      </div>
      {(["instagram", "linkedin"] as const).map(k => (
        <div className="field" key={k}>
          <label htmlFor={`${uid}-${k}`}>
            <span>{k[0]!.toUpperCase() + k.slice(1)} URL</span>
          </label>
          <input
            id={`${uid}-${k}`}
            type="url"
            disabled={!canEdit}
            maxLength={500}
            value={social[k] ?? ""}
            placeholder="https://"
            onChange={e =>
              onPatch({
                social: Object.fromEntries(
                  Object.entries({ ...social, [k]: e.target.value }).filter(
                    ([, v]) => v
                  )
                ),
              })
            }
          />
        </div>
      ))}
      <div className="field check">
        <label>
          <input
            type="checkbox"
            disabled={!canEdit}
            checked={Boolean(theme.header?.sticky)}
            onChange={e =>
              onPatch({ header: { ...theme.header, sticky: e.target.checked } })
            }
          />{" "}
          <span>Sticky header</span>
        </label>
      </div>
      <div className="field">
        <label htmlFor={`${uid}-cols`}>
          <span>Footer columns</span>
        </label>
        <select
          id={`${uid}-cols`}
          disabled={!canEdit}
          value={theme.footer?.columns ?? 3}
          onChange={e =>
            onPatch({
              footer: { ...theme.footer, columns: Number(e.target.value) },
            })
          }
        >
          {[1, 2, 3, 4].map(n => (
            <option key={n} value={n}>
              {n}
            </option>
          ))}
        </select>
      </div>
    </div>
  );
}
