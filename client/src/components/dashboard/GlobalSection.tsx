import { useEffect, useState } from "react";
import { api } from "@/lib/api/builder";
import { describeIssues } from "@/lib/api/errors";
import type { GlobalSettings } from "@/lib/schema/api";

const WIDTHS = [960, 1024, 1140, 1144, 1200, 1320, 1440];
const BARS: { id: GlobalSettings["admin_bar"]; name: string; help: string }[] =
  [
    {
      id: "default",
      name: "WordPress default",
      help: "Customize, comments, New, Edit and the rest, plus the RK Builder shortcuts.",
    },
    {
      id: "builder",
      name: "RK Builder only",
      help: "Just the site name, your account and the RK Builder shortcuts: Edit with RK Builder and the dashboard.",
    },
    {
      id: "hidden",
      name: "Hidden",
      help: "No toolbar on the public site, even when you are signed in.",
    },
  ];

const CLEANUPS: { key: keyof GlobalSettings; name: string; help: string }[] = [
  {
    key: "no_emojis",
    name: "Turn off WordPress emoji scripts",
    help: "Removes an extra script and style from every page. Emoji still show normally in modern browsers.",
  },
  {
    key: "no_embeds",
    name: "Turn off WordPress embeds",
    help: "Removes the embed script and discovery links. Pasted video links stop turning into players on theme pages.",
  },
  {
    key: "no_block_css",
    name: "Skip the block-editor styles",
    help: "Drops the block library and global styles on every public page. Leave this off if your WordPress theme uses blocks.",
  },
  {
    key: "no_head_clutter",
    name: "Tidy the page head",
    help: "Removes the WordPress version, RSD, Windows Live Writer and short-link tags.",
  },
];

function Switch({
  checked,
  onChange,
  label,
  help,
}: {
  checked: boolean;
  onChange: (v: boolean) => void;
  label: string;
  help?: string;
}) {
  return (
    <div className="field check">
      <label>
        <input
          type="checkbox"
          checked={checked}
          onChange={e => onChange(e.target.checked)}
        />{" "}
        <span>
          {label}
          {help && <small className="muted"> {help}</small>}
        </span>
      </label>
    </div>
  );
}

/** Site-wide options in one place, like Elementor's global settings: layout width, the admin bar, theme styles, clean-ups. */
export function GlobalSection() {
  const [g, setG] = useState<GlobalSettings | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [note, setNote] = useState("");

  useEffect(() => {
    api
      .getGlobal()
      .then(r => setG(r.global))
      .catch(e => setError(describeIssues(e)));
  }, []);

  if (!g)
    return error ? (
      <div className="notice error" role="alert">
        {error}
      </div>
    ) : (
      <p className="muted">Loading…</p>
    );

  const set = (patch: Partial<GlobalSettings>) => setG({ ...g, ...patch });
  const num = (
    id: string,
    label: string,
    key: keyof GlobalSettings,
    min: number,
    max: number,
    help: string
  ) => (
    <div className="field">
      <label htmlFor={id}>
        <span>{label}</span>
      </label>
      <input
        id={id}
        type="number"
        min={min}
        max={max}
        value={Number(g[key])}
        onChange={e =>
          set({ [key]: Number(e.target.value) } as Partial<GlobalSettings>)
        }
      />
      <small className="muted">{help}</small>
    </div>
  );

  const save = () => {
    setBusy(true);
    setError("");
    setNote("");
    api
      .setGlobal(g)
      .then(r => {
        setG(r.global);
        setNote("Saved. Public pages will refresh in a moment.");
      })
      .catch(e => setError(describeIssues(e)))
      .finally(() => setBusy(false));
  };

  const w = Math.min(Math.max(g.layout_width, 640), 1920);
  return (
    <>
      <header className="dash-head">
        <div>
          <h1>Global settings</h1>
          <p className="muted">
            Options for the whole website: the content width, the toolbar you
            see while signed in, the WordPress theme&apos;s styles and
            clean-ups.
          </p>
        </div>
        <div className="dash-actions">
          <button className="save-btn" onClick={save} disabled={busy}>
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

      <section className="dash-card">
        <h2>Layout</h2>
        <p className="muted">
          The width of the content column on every RK Builder page, header and
          footer included. Wider screens centre the column; narrower ones keep
          the side space below.
        </p>
        <div className="form-grid">
          {num(
            "gl-width",
            "Content width (px)",
            "layout_width",
            640,
            1920,
            "Default 1144. Common choices: 1140, 1200, 1320."
          )}
          {num(
            "gl-gutter",
            "Side space on computers (px)",
            "gutter",
            0,
            120,
            "Least space at each side. Default 28."
          )}
          {num(
            "gl-gutter-m",
            "Side space on phones (px)",
            "gutter_mobile",
            0,
            60,
            "Default 16."
          )}
        </div>
        <div className="width-presets" role="group" aria-label="Width presets">
          {WIDTHS.map(p => (
            <button
              key={p}
              type="button"
              className={`top-btn ${g.layout_width === p ? "on" : ""}`}
              onClick={() => set({ layout_width: p })}
            >
              {p}
            </button>
          ))}
        </div>
        <div className="width-preview" aria-hidden="true">
          <div
            className="width-preview-col"
            style={{ width: `${(w / 1920) * 100}%` }}
          >
            {w}px
          </div>
        </div>
      </section>

      <section className="dash-card">
        <h2>Toolbar on the website</h2>
        <p className="muted">
          What the black WordPress bar shows at the top of the public site while
          you are signed in. Visitors never see it.
        </p>
        <fieldset className="field kind-pick">
          <legend>Toolbar style</legend>
          {BARS.map(b => (
            <label
              key={b.id}
              className={`kind-card ${g.admin_bar === b.id ? "on" : ""}`}
            >
              <input
                type="radio"
                name="gl-bar"
                checked={g.admin_bar === b.id}
                onChange={() => set({ admin_bar: b.id })}
              />
              <strong>{b.name}</strong>
              <small>{b.help}</small>
            </label>
          ))}
        </fieldset>
      </section>

      <section className="dash-card">
        <h2>WordPress theme</h2>
        <Switch
          checked={g.theme_styles}
          onChange={v => set({ theme_styles: v })}
          label="Load the WordPress theme's styles on RK Builder pages"
          help="Turn off to keep the active theme's CSS out of pages RK Builder draws itself (standalone mode). The theme still draws any page RK Builder does not."
        />
      </section>

      <section className="dash-card">
        <h2>Speed and clean-up</h2>
        {CLEANUPS.map(c => (
          <Switch
            key={c.key}
            checked={Boolean(g[c.key])}
            onChange={v => set({ [c.key]: v } as Partial<GlobalSettings>)}
            label={c.name}
            help={c.help}
          />
        ))}
      </section>
    </>
  );
}
