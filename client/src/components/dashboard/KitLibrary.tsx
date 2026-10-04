import { useCallback, useEffect, useState } from "react";
import { Package, RefreshCw } from "lucide-react";
import { api } from "@/lib/api/builder";
import { describeError, describeIssues } from "@/lib/api/errors";
import type { LibraryKit, LibraryView } from "@/lib/schema/api";

const BUTTON: Record<LibraryKit["state"], string> = {
  new: "Add to my themes",
  update: "Update",
  added: "In my themes",
  "needs-plugin": "Needs a newer plugin",
};

/** Browse a catalogue of kits (hosted anywhere) and add one to this site's themes in a click. */
export function KitLibrary({ onAdded }: { onAdded: () => void }) {
  const [view, setView] = useState<LibraryView | null>(null);
  const [error, setError] = useState("");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState<string>("");
  const [editing, setEditing] = useState(false);
  const [url, setUrl] = useState("");
  const [key, setKey] = useState("");

  const load = useCallback((refresh = false) => {
    setError("");
    setBusy("load");
    api
      .getLibrary(refresh)
      .then(v => {
        setView(v);
        setUrl(v.url);
        if (v.error) setError(v.error);
      })
      .catch(e => setError(describeError(e)))
      .finally(() => setBusy(""));
  }, []);
  useEffect(() => load(), [load]);

  const connect = (nextUrl: string) => {
    setError("");
    setNote("");
    setBusy("connect");
    const body: { url: string; licenseKey?: string } = { url: nextUrl };
    if (key.trim() !== "" || nextUrl === "") body.licenseKey = key.trim();
    api
      .setLibrary(body)
      .then(v => {
        setView(v);
        setUrl(v.url);
        setKey("");
        setEditing(false);
        if (v.error) setError(v.error);
      })
      .catch(e => setError(describeIssues(e)))
      .finally(() => setBusy(""));
  };

  const add = (k: LibraryKit) => {
    setError("");
    setNote("");
    setBusy(k.id);
    api
      .addLibraryKit(k.id)
      .then(r => {
        setNote(
          `Added “${r.theme.name}” ${r.theme.version} to your themes below. Press Install to use it.`
        );
        onAdded();
        load();
      })
      .catch(e => setError(describeError(e)))
      .finally(() => setBusy(""));
  };

  const connected = view?.configured === true && view.url !== "";
  const showForm = !connected || editing;

  return (
    <section className="dash-card" aria-labelledby="kit-library-h">
      <h2 id="kit-library-h">Kit Library</h2>
      <p className="muted">
        Browse ready-made site kits and add one to your themes in a click. A kit
        brings its own pages, blocks, design and pictures.
      </p>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      {note && (
        <p className="notice info inline" role="status">
          {note}
        </p>
      )}
      {view === null && <p className="muted">Loading…</p>}
      {showForm && view !== null && (
        <div className="theme-form">
          <div className="field theme-wide">
            <label htmlFor="kl-url">
              <span>Library address</span>
            </label>
            <input
              id="kl-url"
              type="url"
              maxLength={400}
              value={url}
              placeholder="https://kits.example.com/index.json"
              onChange={e => setUrl(e.target.value)}
            />
          </div>
          <div className="field theme-wide">
            <label htmlFor="kl-key">
              <span>
                Licence key <small className="muted">(optional)</small>
              </span>
            </label>
            <input
              id="kl-key"
              type="password"
              autoComplete="off"
              maxLength={200}
              value={key}
              placeholder={
                view.hasKey ? "A key is saved. Type to replace it." : ""
              }
              onChange={e => setKey(e.target.value)}
            />
          </div>
          <div className="dialog-actions theme-wide">
            {connected && (
              <button
                className="top-btn"
                disabled={busy !== ""}
                onClick={() => {
                  setEditing(false);
                  setUrl(view.url);
                }}
              >
                Cancel
              </button>
            )}
            <button
              className="save-btn"
              disabled={busy !== "" || url.trim() === ""}
              onClick={() => connect(url.trim())}
            >
              {connected ? "Save" : "Connect"}
            </button>
          </div>
        </div>
      )}
      {connected && !editing && view && (
        <>
          <div className="dash-actions">
            <span className="muted">
              {view.name || "Kit library"} · {view.items.length} kit
              {view.items.length === 1 ? "" : "s"}
            </span>
            <button
              className="top-btn"
              disabled={busy !== ""}
              onClick={() => load(true)}
            >
              <RefreshCw size={14} aria-hidden="true" /> Refresh
            </button>
            <button className="top-btn" onClick={() => setEditing(true)}>
              Settings
            </button>
            <button
              className="top-btn"
              disabled={busy !== ""}
              onClick={() => connect("")}
            >
              Disconnect
            </button>
          </div>
          {busy === "load" && <p className="muted">Loading the library…</p>}
          <ul className="theme-grid">
            {view.items.map(k => (
              <li key={k.id} className="theme-card">
                <div
                  className="theme-thumb"
                  style={
                    k.preview
                      ? { backgroundImage: `url("${k.preview}")` }
                      : undefined
                  }
                  aria-hidden="true"
                >
                  {!k.preview && <Package size={28} />}
                </div>
                <div className="theme-body">
                  <strong>{k.name}</strong>
                  <span className="muted">
                    v{k.version}
                    {k.industry && ` · ${k.industry}`}
                    {k.author && ` · ${k.author}`}
                    {k.price && ` · ${k.price}`}
                  </span>
                  {k.description && <p>{k.description}</p>}
                  {k.tags.length > 0 && (
                    <span className="muted">{k.tags.join(" · ")}</span>
                  )}
                  {k.requiresKey && !view.hasKey && (
                    <span className="muted">Needs a licence key</span>
                  )}
                </div>
                <div className="theme-actions">
                  <button
                    className="save-btn"
                    disabled={
                      busy !== "" ||
                      k.state === "added" ||
                      k.state === "needs-plugin"
                    }
                    onClick={() => add(k)}
                    aria-label={`${BUTTON[k.state]}: ${k.name}`}
                  >
                    {busy === k.id
                      ? "Adding…"
                      : k.state === "update"
                        ? `Update to v${k.version}`
                        : BUTTON[k.state]}
                  </button>
                  {k.demo && (
                    <a
                      className="top-btn"
                      href={k.demo}
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      Demo
                    </a>
                  )}
                </div>
              </li>
            ))}
          </ul>
          {view.items.length === 0 && !error && busy !== "load" && (
            <p className="muted">This library has no kits yet.</p>
          )}
        </>
      )}
    </section>
  );
}
