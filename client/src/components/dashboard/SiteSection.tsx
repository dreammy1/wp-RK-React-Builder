import { useEffect, useState } from "react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import type { SiteSettings } from "@/lib/schema/api";

export function SiteSection() {
  const [s, setS] = useState<SiteSettings | null>(null);
  const [pages, setPages] = useState<{ id: number; title: string }[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [note, setNote] = useState("");

  useEffect(() => {
    api
      .getSite()
      .then(r => {
        setS(r.site);
        setPages(r.pages);
      })
      .catch(e => setError(describeError(e)));
  }, []);

  if (!s)
    return error ? (
      <div className="notice error" role="alert">
        {error}
      </div>
    ) : (
      <p className="muted">Loading…</p>
    );

  const org = s.organization;
  const setOrg = (patch: Partial<SiteSettings["organization"]>) =>
    setS({ ...s, organization: { ...org, ...patch } });

  const save = () => {
    setBusy(true);
    setError("");
    setNote("");
    api
      .setSite({
        name: s.name,
        tagline: s.tagline,
        searchVisible: s.searchVisible,
        frontPageId: s.frontPageId,
        organization: org,
      })
      .then(r => {
        setS(r.site);
        setPages(r.pages);
        setNote("Saved. Public pages will refresh in a moment.");
      })
      .catch(e => setError(describeError(e)))
      .finally(() => setBusy(false));
  };

  return (
    <>
      <header className="dash-head">
        <div>
          <h1>Site &amp; SEO</h1>
          <p className="muted">
            Site-wide details, the front page, search visibility and the
            business details used in search results.
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
        <h2>General</h2>
        <div className="form-grid">
          <div className="field">
            <label htmlFor="st-name">
              <span>Site title</span>
            </label>
            <input
              id="st-name"
              type="text"
              maxLength={120}
              value={s.name}
              onChange={e => setS({ ...s, name: e.target.value })}
            />
            <small className="muted">
              Shown after every page title: “Page | {s.name || "Site title"}”.
            </small>
          </div>
          <div className="field">
            <label htmlFor="st-tag">
              <span>Tagline</span>
            </label>
            <input
              id="st-tag"
              type="text"
              maxLength={200}
              value={s.tagline}
              onChange={e => setS({ ...s, tagline: e.target.value })}
            />
          </div>
          <div className="field">
            <label htmlFor="st-front">
              <span>Front page</span>
            </label>
            <select
              id="st-front"
              value={s.frontPageId}
              onChange={e =>
                setS({ ...s, frontPageId: Number(e.target.value) })
              }
            >
              <option value={0}>Latest posts (WordPress default)</option>
              {pages.map(p => (
                <option key={p.id} value={p.id}>
                  {p.title || `(untitled ${p.id})`}
                </option>
              ))}
            </select>
            <small className="muted">Only published pages are listed.</small>
          </div>
        </div>
        <div className="field check">
          <label>
            <input
              type="checkbox"
              checked={s.searchVisible}
              onChange={e => setS({ ...s, searchVisible: e.target.checked })}
            />{" "}
            <span>
              Let search engines index this site
              <small className="muted">
                {" "}
                Turn off while you are building or testing.
              </small>
            </span>
          </label>
        </div>
      </section>

      <section className="dash-card">
        <h2>Business details</h2>
        <p className="muted">
          Added to every page as structured data so search engines know who you
          are.
        </p>
        <div className="form-grid">
          <div className="field">
            <label htmlFor="og-name">
              <span>Business name</span>
            </label>
            <input
              id="og-name"
              type="text"
              value={org.name}
              onChange={e => setOrg({ name: e.target.value })}
            />
          </div>
          <div className="field">
            <label htmlFor="og-phone">
              <span>Phone</span>
            </label>
            <input
              id="og-phone"
              type="tel"
              value={org.telephone}
              onChange={e => setOrg({ telephone: e.target.value })}
            />
          </div>
          <div className="field">
            <label htmlFor="og-email">
              <span>Email</span>
            </label>
            <input
              id="og-email"
              type="email"
              value={org.email}
              onChange={e => setOrg({ email: e.target.value })}
            />
          </div>
          <div className="field">
            <label htmlFor="og-logo">
              <span>Logo address</span>
            </label>
            <input
              id="og-logo"
              type="url"
              placeholder="https://"
              value={org.logo}
              onChange={e => setOrg({ logo: e.target.value })}
            />
          </div>
          <div className="field wide">
            <label htmlFor="og-desc">
              <span>Short description</span>
            </label>
            <input
              id="og-desc"
              type="text"
              maxLength={300}
              value={org.description}
              onChange={e => setOrg({ description: e.target.value })}
            />
          </div>
        </div>
      </section>
    </>
  );
}
