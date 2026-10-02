import { useEffect, useState } from "react";
import { Trash2 } from "lucide-react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import type { VizAdmin } from "@/lib/schema/api";

type Form = {
  enabled: boolean;
  provider: string;
  hf_token: string;
  gemini_key: string;
  gemini_model: string;
  custom_url: string;
  custom_key: string;
  custom_header: string;
  free_count: number;
  bonus_count: number;
  cooldown_hours: number;
  ip_per_hour: number;
  timeout: number;
  notify_email: string;
};

const fromAdmin = (a: VizAdmin): Form => ({
  enabled: a.settings.enabled,
  provider: a.settings.provider,
  hf_token: "",
  gemini_key: "",
  gemini_model: a.settings.gemini_model,
  custom_url: a.settings.custom_url,
  custom_key: "",
  custom_header: a.settings.custom_header,
  free_count: a.settings.free_count,
  bonus_count: a.settings.bonus_count,
  cooldown_hours: a.settings.cooldown_hours,
  ip_per_hour: a.settings.ip_per_hour,
  timeout: a.settings.timeout,
  notify_email: a.settings.notify_email,
});

export function VisualizerSection() {
  const [data, setData] = useState<VizAdmin | null>(null);
  const [f, setF] = useState<Form | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [note, setNote] = useState("");
  const [clear, setClear] = useState<Record<string, boolean>>({});

  useEffect(() => {
    api
      .getViz()
      .then(a => {
        setData(a);
        setF(fromAdmin(a));
      })
      .catch(e => setError(describeError(e)));
  }, []);

  if (!data || !f)
    return error ? (
      <div className="notice error" role="alert">
        {error}
      </div>
    ) : (
      <p className="muted">Loading…</p>
    );

  const set = <K extends keyof Form>(k: K, v: Form[K]) =>
    setF({ ...f, [k]: v });
  const num = (k: keyof Form) => (e: React.ChangeEvent<HTMLInputElement>) =>
    set(k, Number(e.target.value) as never);

  const save = () => {
    setBusy(true);
    setError("");
    setNote("");
    api
      .setViz({
        ...f,
        ...Object.fromEntries(
          Object.entries(clear)
            .filter(([, v]) => v)
            .map(([k]) => [`clear_${k}`, true])
        ),
      })
      .then(a => {
        setData(a);
        setF(fromAdmin(a));
        setClear({});
        setNote("Visualizer settings saved.");
      })
      .catch(e => setError(describeError(e)))
      .finally(() => setBusy(false));
  };

  const removeLead = (
    target: { email: string } | { all: true },
    msg: string
  ) => {
    if (!window.confirm(msg)) return;
    api
      .deleteVizLeads(target)
      .then(setData)
      .catch(e => setError(describeError(e)));
  };

  const secret = (
    key: "hf_token" | "gemini_key" | "custom_key",
    label: string,
    isSet: boolean,
    env = false
  ) => (
    <div className="field">
      <label htmlFor={`vz-${key}`}>
        <span>{label}</span>
      </label>
      <input
        id={`vz-${key}`}
        type="password"
        autoComplete="off"
        value={f[key]}
        placeholder={
          env
            ? "Set on the server (environment or wp-config)"
            : isSet && !clear[key]
              ? "•••••••• saved — type to replace"
              : "Paste it here"
        }
        onChange={e => set(key, e.target.value)}
      />
      {isSet && (
        <label className="inline-check">
          <input
            type="checkbox"
            checked={Boolean(clear[key])}
            onChange={e => setClear({ ...clear, [key]: e.target.checked })}
          />{" "}
          Remove the saved value on save
        </label>
      )}
      <small className="muted">
        Stored on this WordPress site and never shown again.
      </small>
    </div>
  );

  const s = data.settings;
  return (
    <>
      <header className="dash-head">
        <div>
          <h1>Visualizer</h1>
          <p className="muted">
            The AI flooring visualizer: backend, free limits and the people who
            asked for more.
          </p>
        </div>
        <div className="dash-actions">
          <button className="save-btn" onClick={save} disabled={busy}>
            {busy ? "Saving…" : "Save settings"}
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
      <div className={`notice ${data.ready ? "info" : "warn"}`} role="status">
        {data.ready
          ? `Live with ${data.providers[s.provider]}.`
          : s.enabled
            ? "Turned on but not ready: the chosen backend still needs its key or address."
            : "Turned off: the visualizer page shows a notice instead of the form."}
      </div>
      {f.provider === "mock" && f.enabled && (
        <div className="notice warn" role="status">
          Test mode only returns the visitor&apos;s own photo. Pick a real
          backend before going live.
        </div>
      )}

      <section className="dash-card">
        <h2>Backend</h2>
        <div className="field check">
          <label>
            <input
              type="checkbox"
              checked={f.enabled}
              onChange={e => set("enabled", e.target.checked)}
            />{" "}
            <span>Visualizer is on</span>
          </label>
        </div>
        <div className="form-grid">
          <div className="field">
            <label htmlFor="vz-provider">
              <span>Provider</span>
            </label>
            <select
              id="vz-provider"
              value={f.provider}
              onChange={e => set("provider", e.target.value)}
            >
              {Object.entries(data.providers).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </select>
          </div>
          {f.provider === "huggingface" &&
            secret(
              "hf_token",
              "Hugging Face token",
              s.hf_token_set,
              s.hf_token_env && !s.hf_token_set
            )}
          {f.provider === "gemini" && (
            <>
              {secret(
                "gemini_key",
                "Gemini API key",
                s.gemini_key_set,
                s.gemini_key_env && !s.gemini_key_set
              )}
              <div className="field">
                <label htmlFor="vz-model">
                  <span>Gemini model</span>
                </label>
                <input
                  id="vz-model"
                  type="text"
                  value={f.gemini_model}
                  onChange={e => set("gemini_model", e.target.value)}
                />
              </div>
            </>
          )}
          {f.provider === "custom" && (
            <>
              <div className="field">
                <label htmlFor="vz-url">
                  <span>Backend address</span>
                </label>
                <input
                  id="vz-url"
                  type="url"
                  placeholder="https://"
                  value={f.custom_url}
                  onChange={e => set("custom_url", e.target.value)}
                />
              </div>
              {secret("custom_key", "Backend key", s.custom_key_set)}
              <div className="field">
                <label htmlFor="vz-hdr">
                  <span>Key header name</span>
                </label>
                <input
                  id="vz-hdr"
                  type="text"
                  value={f.custom_header}
                  onChange={e => set("custom_header", e.target.value)}
                />
              </div>
            </>
          )}
        </div>
      </section>

      <section className="dash-card">
        <h2>Limits</h2>
        <div className="form-grid">
          <div className="field">
            <label htmlFor="vz-free">
              <span>Free generations per visitor</span>
            </label>
            <input
              id="vz-free"
              type="number"
              min={0}
              max={20}
              value={f.free_count}
              onChange={num("free_count")}
            />
          </div>
          <div className="field">
            <label htmlFor="vz-bonus">
              <span>Bonus after contact details</span>
            </label>
            <input
              id="vz-bonus"
              type="number"
              min={0}
              max={20}
              value={f.bonus_count}
              onChange={num("bonus_count")}
            />
          </div>
          <div className="field">
            <label htmlFor="vz-cool">
              <span>Wait before more (hours)</span>
            </label>
            <input
              id="vz-cool"
              type="number"
              min={1}
              max={720}
              value={f.cooldown_hours}
              onChange={num("cooldown_hours")}
            />
          </div>
          <div className="field">
            <label htmlFor="vz-ip">
              <span>Per-IP limit per hour</span>
            </label>
            <input
              id="vz-ip"
              type="number"
              min={1}
              max={1000}
              value={f.ip_per_hour}
              onChange={num("ip_per_hour")}
            />
          </div>
          <div className="field">
            <label htmlFor="vz-to">
              <span>Timeout (seconds)</span>
            </label>
            <input
              id="vz-to"
              type="number"
              min={20}
              max={600}
              value={f.timeout}
              onChange={num("timeout")}
            />
          </div>
          <div className="field">
            <label htmlFor="vz-mail">
              <span>Email me new leads</span>
            </label>
            <input
              id="vz-mail"
              type="email"
              value={f.notify_email}
              onChange={e => set("notify_email", e.target.value)}
            />
          </div>
        </div>
      </section>

      <section className="dash-card">
        <div className="dash-head tight">
          <h2>Leads ({data.leads.length})</h2>
          {data.leads.length > 0 && (
            <button
              className="top-btn"
              onClick={() =>
                removeLead(
                  { all: true },
                  "Delete every lead? This cannot be undone."
                )
              }
            >
              Delete all
            </button>
          )}
        </div>
        {data.leads.length === 0 ? (
          <p className="muted">No one has left contact details yet.</p>
        ) : (
          <ul className="dash-list">
            {data.leads.map(l => (
              <li key={l.email}>
                <strong>{l.name}</strong>
                <a href={`mailto:${l.email}`}>{l.email}</a>
                {l.phone && <a href={`tel:${l.phone}`}>{l.phone}</a>}
                <small className="muted">
                  {l.at ? new Date(l.at).toLocaleString() : ""}
                </small>
                <button
                  className="icon-btn danger"
                  aria-label={`Delete lead ${l.email}`}
                  onClick={() =>
                    removeLead(
                      { email: l.email },
                      `Delete ${l.name || l.email}?`
                    )
                  }
                >
                  <Trash2 size={14} aria-hidden="true" />
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>
    </>
  );
}
