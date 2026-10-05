import { useEffect, useRef, useState } from "react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import type { PlacesImport, PlacesLink, PlacesSearch } from "@/lib/schema/api";

type Result = PlacesSearch["results"][number];

/** Fetching and state only; the markup below just renders it. */
function usePlaceSearch(
  onImported: (r: PlacesImport) => void,
  onLinked: (r: PlacesLink) => void
) {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<Result[]>([]);
  const [searching, setSearching] = useState(false);
  const [importing, setImporting] = useState(false);
  const [error, setError] = useState("");
  const [done, setDone] = useState("");
  const [keySet, setKeySet] = useState<boolean | null>(null);
  const [keyDraft, setKeyDraft] = useState("");
  const [link, setLink] = useState("");
  const [linking, setLinking] = useState(false);
  const seq = useRef(0);

  useEffect(() => {
    api
      .getReviews()
      .then(r => setKeySet(r.config.keySet))
      .catch(() => setKeySet(false));
  }, []);

  useEffect(() => {
    const q = query.trim();
    if (keySet !== true || q.length < 3) {
      setResults([]);
      return;
    }
    const mine = ++seq.current;
    const t = window.setTimeout(() => {
      setSearching(true);
      setError("");
      api
        .searchPlaces(q)
        .then(r => mine === seq.current && setResults(r.results))
        .catch(e => mine === seq.current && setError(describeError(e)))
        .finally(() => mine === seq.current && setSearching(false));
    }, 400);
    return () => window.clearTimeout(t);
  }, [query, keySet]);

  const saveKey = () => {
    setError("");
    api
      .setReviewsConfig({ apiKey: keyDraft.trim() })
      .then(r => {
        setKeySet(r.config.keySet);
        setKeyDraft("");
      })
      .catch(e => setError(describeError(e)));
  };

  const readLink = () => {
    setLinking(true);
    setError("");
    setDone("");
    api
      .readMapsLink(link.trim())
      .then(r => {
        setLink("");
        setDone(
          r.placeId
            ? "Link saved, with your Place ID. Press Save below."
            : "Link saved. Press Save below. (This link has no Place ID; the Leave-a-review link needs one.)"
        );
        onLinked(r);
      })
      .catch(e => setError(describeError(e)))
      .finally(() => setLinking(false));
  };

  const pick = (r: Result) => {
    setImporting(true);
    setError("");
    setDone("");
    seq.current++;
    api
      .importPlace(r.placeId)
      .then(res => {
        setResults([]);
        setQuery("");
        setDone(
          `Business profile details imported successfully! Review them below, then press Save.`
        );
        onImported(res);
      })
      .catch(e => setError(describeError(e)))
      .finally(() => setImporting(false));
  };

  return {
    query,
    setQuery,
    results,
    searching,
    importing,
    error,
    done,
    keySet,
    keyDraft,
    setKeyDraft,
    saveKey,
    pick,
    link,
    setLink,
    linking,
    readLink,
  };
}

export function PlaceSearch({
  onImported,
  onLinked,
}: {
  onImported: (r: PlacesImport) => void;
  onLinked: (r: PlacesLink) => void;
}) {
  const p = usePlaceSearch(onImported, onLinked);
  return (
    <div className="place-search">
      <div className="field wide">
        <label htmlFor="place-q">
          <span>Search &amp; Import Google Business Profile</span>
        </label>
        {p.keySet === false && (
          <div className="place-key">
            <input
              id="place-key"
              type="password"
              autoComplete="off"
              aria-label="Google Places API key"
              placeholder="Google Places API key"
              value={p.keyDraft}
              onChange={e => p.setKeyDraft(e.target.value)}
            />
            <button
              type="button"
              className="btn"
              disabled={p.keyDraft.trim().length < 20}
              onClick={p.saveKey}
            >
              Save key
            </button>
          </div>
        )}
        <input
          id="place-q"
          type="search"
          autoComplete="off"
          role="combobox"
          aria-expanded={p.results.length > 0}
          aria-controls="place-results"
          disabled={p.keySet !== true || p.importing}
          placeholder="Peoria Hardwood Floors"
          value={p.query}
          onChange={e => p.setQuery(e.target.value)}
        />
        <small className="muted">
          Type or paste your exact Google Business Name (e.g., Peoria Hardwood
          Floors) to automatically import details, address, ratings, and
          reviews.
          {p.keySet === false &&
            " Add a Google Places API key above to turn this on."}
        </small>
        {p.searching && <small className="muted">Searching…</small>}
        {p.results.length > 0 && (
          <ul id="place-results" className="place-results" role="listbox">
            {p.results.map(r => (
              <li key={r.placeId} role="option" aria-selected={false}>
                <button type="button" onClick={() => p.pick(r)}>
                  <strong>{r.name}</strong>
                  <span>{r.address}</span>
                  {r.count > 0 && (
                    <span>
                      ★ {r.rating.toFixed(1)} · {r.count} reviews
                    </span>
                  )}
                </button>
              </li>
            ))}
          </ul>
        )}
        <div className="place-key">
          <input
            id="place-link"
            type="text"
            autoComplete="off"
            aria-label="Google Maps link"
            placeholder="Or paste your Google Maps link (no key needed)"
            value={p.link}
            onChange={e => p.setLink(e.target.value)}
          />
          <button
            type="button"
            className="btn"
            disabled={p.linking || p.link.trim().length < 10}
            onClick={p.readLink}
          >
            {p.linking ? "Reading…" : "Use link"}
          </button>
        </div>
        <small className="muted">
          In Google Maps open your business, press Share, copy the link. It
          fills your profile link (and the name if empty), with no API key.
          Address, hours and ratings still need the search above or typing them.
        </small>
        {p.error && (
          <p className="notice error inline" role="alert">
            {p.error}
          </p>
        )}
        {p.done && (
          <p className="notice info inline" role="status">
            {p.done}
          </p>
        )}
      </div>
    </div>
  );
}
