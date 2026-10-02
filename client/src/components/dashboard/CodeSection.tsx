import { useEffect, useState } from "react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import type { CodeSettings } from "@/lib/schema/api";

export function CodeSection() {
  const [c, setC] = useState<CodeSettings | null>(null);
  const [canEdit, setCanEdit] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [note, setNote] = useState("");

  useEffect(() => {
    api
      .getCode()
      .then(r => {
        setC(r.code);
        setCanEdit(r.canEditCode);
      })
      .catch(e => setError(describeError(e)));
  }, []);

  if (!c)
    return error ? (
      <div className="notice error" role="alert">
        {error}
      </div>
    ) : (
      <p className="muted">Loading…</p>
    );

  const set = <K extends keyof CodeSettings>(k: K, v: CodeSettings[K]) =>
    setC({ ...c, [k]: v });
  const save = () => {
    setBusy(true);
    setError("");
    setNote("");
    api
      .setCode(c)
      .then(r => {
        setC(r.code);
        setNote("Saved. The code is live on your pages now.");
      })
      .catch(e => setError(describeError(e)))
      .finally(() => setBusy(false));
  };

  const area = (
    id: string,
    label: string,
    key: "head" | "bodyStart" | "footer",
    help: string
  ) => (
    <div className="field">
      <label htmlFor={id}>
        <span>{label}</span>
      </label>
      <textarea
        id={id}
        className="code-area"
        spellCheck={false}
        value={c[key]}
        disabled={!canEdit}
        onChange={e => set(key, e.target.value)}
      />
      <small className="muted">{help}</small>
    </div>
  );

  return (
    <>
      <header className="dash-head">
        <div>
          <h1>Code &amp; tracking</h1>
          <p className="muted">
            Verify the site with Google Search Console, add analytics and paste
            custom code. Works on every page of the site.
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
        <h2>Search Console &amp; Bing</h2>
        <p className="muted">
          In Google Search Console choose <strong>HTML tag</strong> and paste
          the whole tag or just the code from <code>content=&quot;…&quot;</code>
          .
        </p>
        <div className="form-grid">
          <div className="field">
            <label htmlFor="cd-gsc">
              <span>Google Search Console</span>
            </label>
            <input
              id="cd-gsc"
              type="text"
              autoComplete="off"
              placeholder='<meta name="google-site-verification" content="…" />'
              value={c.gsc}
              onChange={e => set("gsc", e.target.value)}
            />
          </div>
          <div className="field">
            <label htmlFor="cd-bing">
              <span>Bing Webmaster Tools</span>
            </label>
            <input
              id="cd-bing"
              type="text"
              autoComplete="off"
              placeholder='<meta name="msvalidate.01" content="…" />'
              value={c.bing}
              onChange={e => set("bing", e.target.value)}
            />
          </div>
        </div>
      </section>

      <section className="dash-card">
        <h2>Analytics</h2>
        <div className="form-grid">
          <div className="field">
            <label htmlFor="cd-ga4">
              <span>Google Analytics 4 measurement ID</span>
            </label>
            <input
              id="cd-ga4"
              type="text"
              placeholder="G-ABC123XYZ4"
              value={c.ga4}
              onChange={e => set("ga4", e.target.value)}
            />
          </div>
          <div className="field">
            <label htmlFor="cd-gtm">
              <span>Google Tag Manager container ID</span>
            </label>
            <input
              id="cd-gtm"
              type="text"
              placeholder="GTM-ABC1234"
              value={c.gtm}
              onChange={e => set("gtm", e.target.value)}
            />
          </div>
        </div>
        <div className="field check">
          <label>
            <input
              type="checkbox"
              checked={c.skipLoggedIn}
              onChange={e => set("skipLoggedIn", e.target.checked)}
            />{" "}
            <span>
              Do not track signed-in editors
              <small className="muted">
                {" "}
                keeps your own visits out of the reports
              </small>
            </span>
          </label>
        </div>
        <p className="muted">
          Use Tag Manager <em>or</em> Analytics, not both, unless you know your
          Tag Manager container does not also load Analytics.
        </p>
      </section>

      <section className="dash-card">
        <h2>Custom code</h2>
        {!canEdit && (
          <p className="notice warn inline" role="status">
            Your account is not allowed to edit custom code. Ask an
            administrator.
          </p>
        )}
        <p className="muted">
          Pasted exactly as written, so only add code from sources you trust.
        </p>
        {area(
          "cd-head",
          "In the <head>",
          "head",
          "Scripts and meta tags: pixels, chat widgets, extra verification tags."
        )}
        {area(
          "cd-body",
          "After the opening <body>",
          "bodyStart",
          "For snippets that must come first, such as noscript pixels."
        )}
        {area(
          "cd-foot",
          "Before the closing </body>",
          "footer",
          "Scripts that can load last: they do not slow the page down."
        )}
      </section>
    </>
  );
}
