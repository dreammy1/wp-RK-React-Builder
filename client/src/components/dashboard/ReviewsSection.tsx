import { useEffect, useState } from "react";
import { Eye, EyeOff, RefreshCw, Star, Trash2 } from "lucide-react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import { resetReviewsCache } from "@/lib/reviews";
import type { ReviewItem, ReviewsAdmin } from "@/lib/schema/api";
import { fmtWhen } from "./Overview";
import { BulkBar, RowCheck, useSelection } from "./Bulk";

function StarsInput({
  value,
  onChange,
}: {
  value: number;
  onChange: (n: number) => void;
}) {
  return (
    <span className="stars-input" role="radiogroup" aria-label="Rating">
      {[1, 2, 3, 4, 5].map(n => (
        <button
          key={n}
          type="button"
          role="radio"
          aria-checked={value === n}
          aria-label={`${n} star${n === 1 ? "" : "s"}`}
          className={n <= value ? "on" : ""}
          onClick={() => onChange(n)}
        >
          <Star
            size={20}
            fill={n <= value ? "currentColor" : "none"}
            aria-hidden="true"
          />
        </button>
      ))}
    </span>
  );
}

export function ReviewsSection() {
  const [data, setData] = useState<ReviewsAdmin | null>(null);
  const [cfg, setCfg] = useState({
    profileUrl: "",
    placeId: "",
    apiKey: "",
    auto: false,
  });
  const [manual, setManual] = useState({ rating: "", count: "" });
  const [draft, setDraft] = useState({
    author: "",
    rating: 5,
    text: "",
    date: "",
  });
  const [clearKey, setClearKey] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [note, setNote] = useState("");
  const pick = useSelection((data?.items ?? []).map(r => r.id));

  const apply = (r: ReviewsAdmin) => {
    setData(r);
    setCfg({
      profileUrl: r.config.profileUrl,
      placeId: r.config.placeId,
      apiKey: "",
      auto: r.config.auto,
    });
    setManual({
      rating: r.summary.rating ? String(r.summary.rating) : "",
      count: r.summary.count ? String(r.summary.count) : "",
    });
    setClearKey(false);
    resetReviewsCache();
  };

  useEffect(() => {
    api
      .getReviews()
      .then(apply)
      .catch(e => setError(describeError(e)));
  }, []);

  const run = (fn: () => Promise<ReviewsAdmin>, msg: string) => {
    setBusy(true);
    setError("");
    setNote("");
    fn()
      .then(r => {
        apply(r);
        setNote(msg);
      })
      .catch(e => setError(describeError(e)))
      .finally(() => setBusy(false));
  };

  if (!data)
    return error ? (
      <div className="notice error" role="alert">
        {error}
      </div>
    ) : (
      <p className="muted">Loading…</p>
    );

  const c = data.config;
  const saveConfig = () =>
    run(
      () =>
        api.setReviewsConfig({
          profileUrl: cfg.profileUrl,
          placeId: cfg.placeId,
          auto: cfg.auto,
          ...(cfg.apiKey ? { apiKey: cfg.apiKey } : {}),
          ...(clearKey ? { clearKey: true } : {}),
          ...(!c.syncedAt && manual.rating
            ? {
                summary: {
                  rating: Number(manual.rating),
                  count: Number(manual.count || 0),
                },
              }
            : {}),
        }),
      "Saved."
    );
  const setItems = (items: ReviewItem[], msg: string) =>
    run(() => api.setReviewItems(items), msg);

  const addManual = () =>
    setItems(
      [
        ...data.items,
        {
          id: "",
          author: draft.author,
          rating: draft.rating,
          text: draft.text,
          date: draft.date,
          source: "manual",
          url: "",
          hidden: false,
        },
      ],
      "Review added."
    );

  return (
    <>
      <header className="dash-head">
        <div>
          <h1>Reviews</h1>
          <p className="muted">
            Show your Google rating and best reviews with the{" "}
            <strong>Google reviews</strong> block, and link customers to your
            Google Business Profile.
          </p>
        </div>
        <div className="dash-actions">
          <button
            className="save-btn"
            disabled={busy}
            onClick={() => saveConfig()}
          >
            {busy ? "Saving…" : "Save"}
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

      <section className="stat-grid" aria-label="Google rating">
        <div className="stat-card">
          <span className="eyebrow">Rating</span>
          <strong>
            {data.summary.rating ? data.summary.rating.toFixed(1) : "–"}
          </strong>
          <small className="muted">out of 5</small>
        </div>
        <div className="stat-card">
          <span className="eyebrow">Google reviews</span>
          <strong>{data.summary.count || "–"}</strong>
          <small className="muted">
            {c.syncedAt ? `synced ${fmtWhen(c.syncedAt)}` : "not synced yet"}
          </small>
        </div>
        <div className="stat-card">
          <span className="eyebrow">Showing on site</span>
          <strong>{data.items.filter(r => !r.hidden).length}</strong>
          <small className="muted">of {data.items.length} saved</small>
        </div>
      </section>

      <section className="dash-card">
        <h2>Google Business Profile</h2>
        <div className="form-grid">
          <div className="field wide">
            <label htmlFor="rv-url">
              <span>Business Profile link</span>
            </label>
            <input
              id="rv-url"
              type="url"
              placeholder="https://g.page/r/… or your Google Maps link"
              value={cfg.profileUrl}
              onChange={e => setCfg({ ...cfg, profileUrl: e.target.value })}
            />
            <small className="muted">
              Used for the “See all reviews on Google” button.
            </small>
          </div>
          <div className="field">
            <label htmlFor="rv-place">
              <span>Place ID</span>
            </label>
            <input
              id="rv-place"
              type="text"
              placeholder="ChIJ…"
              value={cfg.placeId}
              onChange={e => setCfg({ ...cfg, placeId: e.target.value })}
            />
            <small className="muted">
              Gives you the “Leave a review” button and lets the site pull
              reviews.{" "}
              <a
                href="https://developers.google.com/maps/documentation/places/web-service/place-id"
                target="_blank"
                rel="noreferrer"
              >
                Find your Place ID
              </a>
            </small>
          </div>
          <div className="field">
            <label htmlFor="rv-key">
              <span>Google API key (Places API)</span>
            </label>
            <input
              id="rv-key"
              type="password"
              autoComplete="off"
              disabled={c.keyFromServer}
              placeholder={
                c.keyFromServer
                  ? "Set on the server (wp-config)"
                  : c.keySet && !clearKey
                    ? "•••••••• saved — type to replace"
                    : "Paste your key"
              }
              value={cfg.apiKey}
              onChange={e => setCfg({ ...cfg, apiKey: e.target.value })}
            />
            {c.keySet && !c.keyFromServer && (
              <label className="inline-check">
                <input
                  type="checkbox"
                  checked={clearKey}
                  onChange={e => setClearKey(e.target.checked)}
                />{" "}
                Remove the saved key on save
              </label>
            )}
            <small className="muted">
              Create it in Google Cloud, enable{" "}
              <strong>Places API (New)</strong> and restrict it to this
              site&apos;s server. Stored here, never shown again.
            </small>
          </div>
        </div>
        {!c.syncedAt && (
          <div className="form-grid">
            <div className="field">
              <label htmlFor="rv-rating">
                <span>Your rating (if you are not syncing)</span>
              </label>
              <input
                id="rv-rating"
                type="number"
                min={0}
                max={5}
                step={0.1}
                value={manual.rating}
                onChange={e => setManual({ ...manual, rating: e.target.value })}
              />
            </div>
            <div className="field">
              <label htmlFor="rv-count">
                <span>Number of Google reviews</span>
              </label>
              <input
                id="rv-count"
                type="number"
                min={0}
                value={manual.count}
                onChange={e => setManual({ ...manual, count: e.target.value })}
              />
            </div>
          </div>
        )}
        <div className="field check">
          <label>
            <input
              type="checkbox"
              checked={cfg.auto}
              onChange={e => setCfg({ ...cfg, auto: e.target.checked })}
            />{" "}
            <span>
              Refresh reviews from Google every day
              <small className="muted"> needs the Place ID and API key</small>
            </span>
          </label>
        </div>
        {c.lastError && (
          <p className="form-error" role="alert">
            {c.lastError}
          </p>
        )}
        <div className="dash-actions">
          <button
            className="top-btn"
            disabled={busy || !c.placeId || !(c.keySet || c.keyFromServer)}
            onClick={() => run(() => api.syncReviews(), "Synced with Google.")}
          >
            <RefreshCw size={14} aria-hidden="true" /> Sync from Google now
          </button>
          {c.writeUrl && (
            <a
              className="top-btn"
              href={c.writeUrl}
              target="_blank"
              rel="noreferrer"
            >
              Test “Leave a review” link
            </a>
          )}
        </div>
        <p className="muted">
          Google returns up to five reviews at a time (its most relevant), plus
          the overall rating and count. You can also add your own below. Google
          asks that reviews keep the reviewer&apos;s name and a link back, which
          the block does. Ratings shown on the page are not added to search
          results markup, as Google does not allow that for a business&apos;s
          own reviews.
        </p>
      </section>

      <section className="dash-card">
        <h2>Reviews on your site</h2>
        {data.items.length === 0 ? (
          <p className="muted">
            No reviews yet. Sync from Google or add one below.
          </p>
        ) : (
          <div>
            <BulkBar
              noun="reviews"
              count={pick.count}
              total={data.items.length}
              all={pick.all}
              onToggleAll={pick.toggleAll}
              onClear={pick.clear}
              busy={busy}
              actions={[
                {
                  label: "Hide",
                  icon: <EyeOff size={14} aria-hidden="true" />,
                  onClick: () => {
                    setItems(
                      data.items.map(x =>
                        pick.has(x.id) ? { ...x, hidden: true } : x
                      ),
                      `Hid ${pick.count} review${pick.count === 1 ? "" : "s"}.`
                    );
                    pick.clear();
                  },
                },
                {
                  label: "Show",
                  icon: <Eye size={14} aria-hidden="true" />,
                  onClick: () => {
                    setItems(
                      data.items.map(x =>
                        pick.has(x.id) ? { ...x, hidden: false } : x
                      ),
                      `Showing ${pick.count} review${pick.count === 1 ? "" : "s"}.`
                    );
                    pick.clear();
                  },
                },
                {
                  label: "Delete",
                  icon: <Trash2 size={14} aria-hidden="true" />,
                  danger: true,
                  onClick: () => {
                    if (
                      window.confirm(
                        `Delete ${pick.count} review${pick.count === 1 ? "" : "s"}?`
                      )
                    ) {
                      setItems(
                        data.items.filter(x => !pick.has(x.id)),
                        `Deleted ${pick.count} review${pick.count === 1 ? "" : "s"}.`
                      );
                      pick.clear();
                    }
                  },
                },
              ]}
            />
            {data.items.map(r => (
              <div
                key={r.id}
                className={`review-row selectable${r.hidden ? " hidden" : ""}${pick.has(r.id) ? " picked" : ""}`}
              >
                <RowCheck
                  checked={pick.has(r.id)}
                  onChange={() => pick.toggle(r.id)}
                  label={`Select review by ${r.author}`}
                />
                <div>
                  <strong>{r.author}</strong>{" "}
                  <span className="muted">
                    {"★".repeat(r.rating)} ·{" "}
                    {r.source === "google" ? "Google" : "Added by you"}
                    {r.date && ` · ${r.date}`}
                  </span>
                  {r.text && <p>{r.text}</p>}
                </div>
                <div className="review-actions">
                  <button
                    className="top-btn"
                    disabled={busy}
                    onClick={() =>
                      setItems(
                        data.items.map(x =>
                          x.id === r.id ? { ...x, hidden: !x.hidden } : x
                        ),
                        r.hidden ? "Review shown." : "Review hidden."
                      )
                    }
                  >
                    {r.hidden ? (
                      <>
                        <Eye size={14} aria-hidden="true" /> Show
                      </>
                    ) : (
                      <>
                        <EyeOff size={14} aria-hidden="true" /> Hide
                      </>
                    )}
                  </button>
                  <button
                    className="icon-btn danger"
                    disabled={busy}
                    aria-label={`Delete review by ${r.author}`}
                    onClick={() =>
                      window.confirm(`Delete the review by ${r.author}?`) &&
                      setItems(
                        data.items.filter(x => x.id !== r.id),
                        "Review deleted."
                      )
                    }
                  >
                    <Trash2 size={14} aria-hidden="true" />
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </section>

      <section className="dash-card">
        <h2>Add a review yourself</h2>
        <p className="muted">
          For testimonials from customers who did not post on Google. Only add
          real reviews.
        </p>
        <div className="form-grid">
          <div className="field">
            <label htmlFor="mr-author">
              <span>Customer name</span>
            </label>
            <input
              id="mr-author"
              type="text"
              maxLength={80}
              value={draft.author}
              onChange={e => setDraft({ ...draft, author: e.target.value })}
            />
          </div>
          <div className="field">
            <span className="field-label">Rating</span>
            <StarsInput
              value={draft.rating}
              onChange={rating => setDraft({ ...draft, rating })}
            />
          </div>
          <div className="field">
            <label htmlFor="mr-date">
              <span>When (optional)</span>
            </label>
            <input
              id="mr-date"
              type="text"
              maxLength={40}
              placeholder="March 2026"
              value={draft.date}
              onChange={e => setDraft({ ...draft, date: e.target.value })}
            />
          </div>
          <div className="field wide">
            <label htmlFor="mr-text">
              <span>Review</span>
            </label>
            <textarea
              id="mr-text"
              rows={3}
              maxLength={1500}
              value={draft.text}
              onChange={e => setDraft({ ...draft, text: e.target.value })}
            />
          </div>
        </div>
        <button
          className="save-btn"
          disabled={busy || !draft.author.trim()}
          onClick={addManual}
        >
          Add review
        </button>
      </section>
    </>
  );
}
