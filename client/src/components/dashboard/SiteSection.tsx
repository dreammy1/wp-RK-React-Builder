import { useEffect, useState } from "react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import type { SiteSettings } from "@/lib/schema/api";
import { ImageField } from "./ImageField";
import { PlaceSearch } from "./PlaceSearch";

const PROFILES: [string, string, string][] = [
  ["googleBusiness", "Google Business Profile", "https://g.page/r/…"],
  ["facebook", "Facebook", "https://facebook.com/…"],
  ["instagram", "Instagram", "https://instagram.com/…"],
  ["linkedin", "LinkedIn", "https://linkedin.com/company/…"],
  ["youtube", "YouTube", "https://youtube.com/@…"],
  ["x", "X (Twitter)", "https://x.com/…"],
  ["tiktok", "TikTok", "https://tiktok.com/@…"],
  ["yelp", "Yelp", "https://yelp.com/biz/…"],
  ["pinterest", "Pinterest", "https://pinterest.com/…"],
];
const TYPES = [
  ["LocalBusiness", "Local business (general)"],
  ["HomeAndConstructionBusiness", "Home & construction"],
  ["GeneralContractor", "General contractor"],
  ["Electrician", "Electrician"],
  ["Plumber", "Plumber"],
  ["HVACBusiness", "HVAC"],
  ["RoofingContractor", "Roofing contractor"],
  ["HousePainter", "House painter"],
  ["ProfessionalService", "Professional service"],
  ["Store", "Store"],
  ["Restaurant", "Restaurant"],
  ["AutomotiveBusiness", "Automotive"],
  ["HealthAndBeautyBusiness", "Health & beauty"],
  ["LegalService", "Legal service"],
  ["RealEstateAgent", "Real estate"],
];

export function SiteSection() {
  const [s, setS] = useState<SiteSettings | null>(null);
  const [pages, setPages] = useState<{ id: number; title: string }[]>([]);
  const [loginPages, setLoginPages] = useState<{ id: number; title: string }[]>(
    []
  );
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [note, setNote] = useState("");

  useEffect(() => {
    api
      .getSite()
      .then(r => {
        setS(r.site);
        setPages(r.pages);
        setLoginPages(r.loginPages);
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
  const setProfile = (k: string, v: string) =>
    setOrg({ profiles: { ...org.profiles, [k]: v } });
  const text = (
    id: string,
    label: string,
    key: keyof SiteSettings["organization"],
    extra: { type?: string; placeholder?: string; wide?: boolean } = {}
  ) => (
    <div className={extra.wide ? "field wide" : "field"}>
      <label htmlFor={id}>
        <span>{label}</span>
      </label>
      <input
        id={id}
        type={extra.type ?? "text"}
        placeholder={extra.placeholder}
        value={String(org[key] ?? "")}
        onChange={e => setOrg({ [key]: e.target.value })}
      />
    </div>
  );

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
        loginPageId: s.loginPageId,
        loginEnabled: s.loginEnabled,
        loginImage: s.loginImage,
        organization: org,
      })
      .then(r => {
        setS(r.site);
        setPages(r.pages);
        setLoginPages(r.loginPages);
        setNote("Saved. Public pages will refresh in a moment.");
      })
      .catch(e => setError(describeError(e)))
      .finally(() => setBusy(false));
  };

  const createLogin = () => {
    setBusy(true);
    setError("");
    setNote("");
    api
      .createLoginPage()
      .then(r => {
        setS(r.site);
        setPages(r.pages);
        setLoginPages(r.loginPages);
        setNote(
          "Sign-in page created and switched on. Edit it like any page, then try it in a private window."
        );
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
        <h2>Sign-in page</h2>
        <p className="muted">
          Use one of your own pages instead of the standard WordPress login.
          WordPress still checks the password, so security and two-step plugins
          keep working. Add the “Sign-in form” block to a page to use it here.
        </p>
        <div className="field check">
          <label>
            <input
              type="checkbox"
              checked={s.loginEnabled}
              disabled={s.loginPageId === 0}
              onChange={e => setS({ ...s, loginEnabled: e.target.checked })}
            />{" "}
            <span>Use the custom sign-in page</span>
          </label>
          <small className="muted">
            {s.loginPageId === 0
              ? "Choose or create a page below first."
              : s.loginEnabled
                ? "On: visitors are sent to your page instead of the WordPress login."
                : "Off: the standard WordPress login is used. Your page is kept for later."}
          </small>
        </div>
        <div className="form-grid">
          <div className="field">
            <label htmlFor="st-login">
              <span>Login page</span>
            </label>
            <select
              id="st-login"
              value={s.loginPageId}
              onChange={e => {
                const id = Number(e.target.value);
                setS({
                  ...s,
                  loginPageId: id,
                  loginEnabled: id === 0 ? false : s.loginEnabled,
                });
              }}
            >
              <option value={0}>WordPress default</option>
              {loginPages.map(p => (
                <option key={p.id} value={p.id}>
                  {p.title || `(untitled ${p.id})`}
                </option>
              ))}
            </select>
            <small className="muted">
              Only published pages with the Sign-in form block are listed. Press
              Save changes after switching it on or off.
            </small>
          </div>
        </div>
        <ImageField
          id="st-login-img"
          label="Sign-in picture"
          value={s.loginImage}
          onChange={loginImage => setS({ ...s, loginImage })}
          help="Shown beside the form in the full-screen layout. A picture set on the block itself is used instead. Use a tall or square photo, at least 1200 px high."
        />
        <div className="dash-actions">
          <button className="top-btn" onClick={createLogin} disabled={busy}>
            Create a sign-in page
          </button>
          {s.loginLink && (
            <a href={s.loginLink} target="_blank" rel="noreferrer">
              View the sign-in page
            </a>
          )}
        </div>
        <small className="muted">
          Locked out? Open{" "}
          <code style={{ textTransform: "none" }}>
            /wp-login.php?rk_login=0
          </code>{" "}
          on your site to get the standard WordPress login back.
        </small>
      </section>

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
        <ImageField
          id="st-favicon"
          label="Favicon"
          value={org.favicon}
          onChange={favicon => setOrg({ favicon })}
          help="The small icon in browser tabs and bookmarks. Use a square PNG, at least 512 × 512 px."
        />
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
        <h2>Social sharing</h2>
        <ImageField
          id="og-default"
          label="Default social image"
          value={org.defaultImage}
          onChange={defaultImage => setOrg({ defaultImage })}
          help="Used on every page that has no image of its own (1200 × 630 px). Set a page's own image in Pages > Search & sharing."
        />
      </section>

      <section className="dash-card">
        <h2>Business details</h2>
        <p className="muted">
          Added to every page as structured data so search engines know who you
          are.
        </p>
        <div className="form-grid">
          {text("og-name", "Business name", "name")}
          {text("og-phone", "Phone", "telephone", { type: "tel" })}
          {text("og-email", "Email", "email", { type: "email" })}
          {text("og-logo", "Logo address", "logo", {
            type: "url",
            placeholder: "https://",
          })}
          {text("og-desc", "Short description", "description", { wide: true })}
        </div>
      </section>

      <section className="dash-card">
        <h2>Local business &amp; Google Business Profile</h2>
        <p className="muted">
          Fill in your address to publish a LocalBusiness listing in the
          structured data. Use the same details as your Google Business Profile.
        </p>
        <PlaceSearch
          onLinked={r =>
            setS(
              cur =>
                cur && {
                  ...cur,
                  organization: {
                    ...cur.organization,
                    ...(r.name && !cur.organization.name && { name: r.name }),
                    profiles: {
                      ...cur.organization.profiles,
                      ...(r.profileUrl && { googleBusiness: r.profileUrl }),
                    },
                  },
                }
            )
          }
          onImported={r => {
            const f = r.fields;
            setS(
              cur =>
                cur && {
                  ...cur,
                  organization: {
                    ...cur.organization,
                    ...(f.name && { name: f.name }),
                    ...(f.telephone && { telephone: f.telephone }),
                    street: f.street,
                    city: f.city,
                    region: f.region,
                    postal: f.postal,
                    ...(f.country && { country: f.country }),
                    ...(f.hours && { hours: f.hours }),
                    profiles: {
                      ...cur.organization.profiles,
                      ...(f.googleBusiness && {
                        googleBusiness: f.googleBusiness,
                      }),
                    },
                  },
                }
            );
          }}
        />
        <div className="form-grid">
          <div className="field">
            <label htmlFor="lb-type">
              <span>Business type</span>
            </label>
            <select
              id="lb-type"
              value={org.businessType || "LocalBusiness"}
              onChange={e => setOrg({ businessType: e.target.value })}
            >
              {TYPES.map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </select>
          </div>
          {text("lb-street", "Street address", "street")}
          {text("lb-city", "City", "city")}
          {text("lb-region", "State / region", "region")}
          {text("lb-postal", "Postal code", "postal")}
          {text("lb-country", "Country (2 letters)", "country", {
            placeholder: "US",
          })}
          {text("lb-price", "Price range", "priceRange", { placeholder: "$$" })}
          {text("lb-areas", "Areas you serve (comma separated)", "areaServed", {
            wide: true,
          })}
          <div className="field wide">
            <label htmlFor="lb-hours">
              <span>Opening hours</span>
            </label>
            <textarea
              id="lb-hours"
              rows={3}
              value={org.hours}
              placeholder={"Mon-Fri 08:00-17:00\nSat 09:00-13:00"}
              onChange={e => setOrg({ hours: e.target.value })}
            />
            <small className="muted">
              One line per group: days (Mon-Fri or Mon,Wed) then 24-hour times.
            </small>
          </div>
        </div>
      </section>

      <section className="dash-card">
        <h2>Profiles &amp; links</h2>
        <p className="muted">
          Your Google Business Profile and social pages. They are added to the
          structured data so Google connects them to this site.
        </p>
        <div className="form-grid">
          {PROFILES.map(([k, label, ph]) => (
            <div className="field" key={k}>
              <label htmlFor={`pf-${k}`}>
                <span>{label}</span>
              </label>
              <input
                id={`pf-${k}`}
                type="url"
                placeholder={ph}
                value={org.profiles[k] ?? ""}
                onChange={e => setProfile(k, e.target.value)}
              />
            </div>
          ))}
        </div>
      </section>
    </>
  );
}
