import { useCallback, useEffect, useRef, useState } from "react";
import { Copy, Upload } from "lucide-react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import type { MediaItem } from "@/lib/schema/api";
import { SubPage } from "../SubPage";

export function MediaSection() {
  const [search, setSearch] = useState("");
  const [items, setItems] = useState<MediaItem[]>([]);
  const [pageNo, setPageNo] = useState(1);
  const [more, setMore] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [note, setNote] = useState("");
  const [open, setOpen] = useState<MediaItem | null>(null);
  const [alt, setAlt] = useState("");
  const fileRef = useRef<HTMLInputElement>(null);

  const load = useCallback(
    (page: number, replace: boolean, signal?: AbortSignal) => {
      api
        .listMedia(search, signal, page)
        .then(r => {
          setItems(prev => (replace ? r.items : [...prev, ...r.items]));
          setPageNo(page);
          setMore(r.items.length >= 24);
        })
        .catch(e => {
          if (!signal?.aborted) setError(describeError(e));
        });
    },
    [search]
  );

  useEffect(() => {
    const ctrl = new AbortController();
    const t = setTimeout(() => load(1, true, ctrl.signal), search ? 250 : 0);
    return () => {
      clearTimeout(t);
      ctrl.abort();
    };
  }, [load, search]);

  const upload = (files: FileList | null) => {
    if (!files || files.length === 0) return;
    setBusy(true);
    setError("");
    setNote("");
    Promise.all([...files].map(f => api.uploadMedia(f, alt.trim())))
      .then(done => {
        setNote(
          `Uploaded ${done.length} image${done.length === 1 ? "" : "s"}.`
        );
        setAlt("");
        load(1, true);
      })
      .catch(e => setError(describeError(e)))
      .finally(() => {
        setBusy(false);
        if (fileRef.current) fileRef.current.value = "";
      });
  };

  return (
    <>
      <header className="dash-head">
        <div>
          <h1>Media</h1>
          <p className="muted">Images you can use on any page.</p>
        </div>
      </header>

      <div className="media-upload">
        <div className="field">
          <label htmlFor="md-alt">
            <span>Description for the next upload (alt text)</span>
          </label>
          <input
            id="md-alt"
            type="text"
            value={alt}
            maxLength={200}
            onChange={e => setAlt(e.target.value)}
          />
        </div>
        <label className="top-btn media-upload-btn">
          <Upload size={14} aria-hidden="true" />{" "}
          {busy ? "Uploading…" : "Upload images"}
          <input
            ref={fileRef}
            type="file"
            accept="image/*"
            multiple
            disabled={busy}
            onChange={e => upload(e.target.files)}
          />
        </label>
      </div>

      <div className="field">
        <label htmlFor="md-search">
          <span>Search images</span>
        </label>
        <input
          id="md-search"
          type="search"
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder="File name or description"
        />
      </div>

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

      <ul className="media-grid">
        {items.map(m => (
          <li key={m.id}>
            <button
              className="media-tile"
              onClick={() => setOpen(m)}
              aria-label={`Open ${m.title || m.alt || "image"}`}
            >
              <img src={m.url} alt={m.alt} loading="lazy" />
              <span>{m.title || m.alt || `#${m.id}`}</span>
            </button>
          </li>
        ))}
      </ul>
      {items.length === 0 && !error && (
        <p className="muted">No images found.</p>
      )}
      {more && (
        <div className="dialog-actions">
          <button className="top-btn" onClick={() => load(pageNo + 1, false)}>
            Load more
          </button>
        </div>
      )}

      {open && (
        <SubPage
          title={open.title || "Image"}
          onClose={() => setOpen(null)}
          wide
        >
          <img className="media-preview" src={open.url} alt={open.alt} />
          <p className="muted">
            {open.width && open.height
              ? `${open.width} × ${open.height}px · `
              : ""}
            {open.alt ? `Alt: ${open.alt}` : "No alt text"}
          </p>
          <div className="field">
            <label htmlFor="md-url">
              <span>Address</span>
            </label>
            <input
              id="md-url"
              readOnly
              value={open.url}
              onFocus={e => e.target.select()}
            />
          </div>
          <div className="dialog-actions">
            <button
              className="top-btn"
              onClick={() => {
                void navigator.clipboard?.writeText(open.url);
                setNote("Image address copied.");
                setOpen(null);
              }}
            >
              <Copy size={14} aria-hidden="true" /> Copy address
            </button>
            <a
              className="top-btn"
              href={open.url}
              target="_blank"
              rel="noreferrer"
            >
              Open full size
            </a>
          </div>
        </SubPage>
      )}
    </>
  );
}
