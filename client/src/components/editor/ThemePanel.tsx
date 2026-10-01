import { useId, useState } from "react";
import { FONTS, type ThemeConfig } from "@/lib/schema/theme";
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
      <div className="field">
        <label htmlFor={`${uid}-font`}>
          <span>Type system</span>
        </label>
        <select
          id={`${uid}-font`}
          value={theme.font}
          disabled={!canEdit}
          onChange={e =>
            onPatch({ font: e.target.value as ThemeConfig["font"] })
          }
        >
          {FONTS.map(f => (
            <option key={f}>{f}</option>
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
