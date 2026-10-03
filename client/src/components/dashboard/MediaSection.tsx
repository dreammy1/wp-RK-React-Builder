import { useCallback, useEffect, useRef, useState } from "react";
import { Copy, Eye, Trash2, Upload, Wand2 } from "lucide-react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import type { MediaItem } from "@/lib/schema/api";
import { SubPage } from "../SubPage";
import { BulkBar, RowCheck, runEach, useSelection } from "./Bulk";

/** "finish-oil_2.jpg" -> "finish oil 2": a starting point for alt text, to be improved by hand. */
function altFromTitle(m: MediaItem): string {
  const base = (m.title || m.filename || "")
    .replace(/\.[a-z0-9]{2,4}$/i, "")
    .replace(/[-_]+/g, " ")
    .replace(/\s+/g, " ")
    .trim();
  return base.slice(0, 125);
}

const fmtBytes = (n?: number) =>
  n == null
    ? ""
    : n < 1024 * 1024
      ? `${Math.max(1, Math.round(n / 1024))} KB`
      : `${(n / 1024 / 1024).toFixed(1)} MB`;

/** One counter for text that search engines cut off: green inside the advised range, amber outside. */
function Count({ n, max, min = 0 }: { n: number; max: number; min?: number }) {
  const ok = n >= min && n <= max && n > 0;
  return (
    <small className={ok ? "count ok" : "count"}>
      {n}/{max} recommended
    </small>
  );
}

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
  const [missingAlt, setMissingAlt] = useState(false);
  const [edit, setEdit] = useState({
    alt: "",
    title: "",
    caption: "",
    description: "",
  });
  const [saving, setSaving] = useState(false);
  const pick = useSelection(items.map(m => m.id));
  const [bulkBusy, setBulkBusy] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  const load = useCallback(
    (page: number, replace: boolean, signal?: AbortSignal) => {
      api
        .listMedia(search, signal, page, { missingAlt, detail: true })
        .then(r => {
          setItems(prev => (replace ? r.items : [...prev, ...r.items]));
          setPageNo(page);
          setMore(r.items.length >= 24);
        })
        .catch(e => {
          if (!signal?.aborted) setError(describeError(e));
        });
    },
    [search, missingAlt]
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

  const openItem = (m: MediaItem) => {
    setOpen(m);
    setEdit({
      alt: m.alt,
      title: m.title,
      caption: m.caption ?? "",
      description: m.description ?? "",
    });
  };

  const saveEdit = () => {
    if (!open) return;
    setSaving(true);
    setError("");
    api
      .updateMedia(open.id, edit)
      .then(item => {
        setItems(prev => prev.map(m => (m.id === item.id ? item : m)));
        setNote(`Saved “${item.title || item.filename || "image"}”.`);
        setOpen(null);
      })
      .catch(e => setError(describeError(e)))
      .finally(() => setSaving(false));
  };

  const bulkRun = (
    list: MediaItem[],
    fn: (m: MediaItem) => Promise<unknown>,
    verb: string
  ) => {
    setBulkBusy(true);
    setNote("");
    setError("");
    runEach(list, fn, verb, "image")
      .then(r => {
        setNote(r.note);
        setError(r.error);
        pick.clear();
        load(1, true);
      })
      .finally(() => setBulkBusy(false));
  };

  return (
    <>
      <header className="dash-head">
        <div>
          <h1>Media</h1>
          <p className="muted">
            Images you can use on any page. Give each one alt text, a title and
            a description: search engines and screen readers rely on them.
          </p>
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

      <div className="media-filters">
        <div className="field">
          <label htmlFor="md-search">
            <span>Search images</span>
          </label>
          <input
            id="md-search"
            type="search"
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="File name, title or description"
          />
        </div>
        <div className="chip-row" role="group" aria-label="Filter">
          <button
            className={`chip ${missingAlt ? "" : "on"}`}
            aria-pressed={!missingAlt}
            onClick={() => setMissingAlt(false)}
          >
            All images
          </button>
          <button
            className={`chip ${missingAlt ? "on" : ""}`}
            aria-pressed={missingAlt}
            onClick={() => setMissingAlt(true)}
          >
            Missing alt text
          </button>
        </div>
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

      <BulkBar
        noun="images"
        count={pick.count}
        total={items.length}
        all={pick.all}
        onToggleAll={pick.toggleAll}
        onClear={pick.clear}
        busy={bulkBusy}
        actions={[
          {
            label: "Fill empty alt text from the title",
            icon: <Wand2 size={14} aria-hidden="true" />,
            onClick: () => {
              const list = items.filter(m => pick.has(m.id) && !m.alt.trim());
              if (list.length === 0) {
                setNote("");
                setError("Every picked image already has alt text.");
                return;
              }
              bulkRun(
                list,
                m => api.updateMedia(m.id, { alt: altFromTitle(m) }),
                "Added alt text to"
              );
            },
          },
          {
            label: "Delete",
            icon: <Trash2 size={14} aria-hidden="true" />,
            danger: true,
            onClick: () => {
              const list = items.filter(m => pick.has(m.id));
              if (
                window.confirm(
                  `Delete ${list.length} image${list.length === 1 ? "" : "s"} for good? The files are removed from your site and pages that use them will show a broken image. This cannot be undone.`
                )
              )
                bulkRun(list, m => api.deleteMedia(m.id), "Deleted");
            },
          },
        ]}
      />

      <ul className="media-grid">
        {items.map(m => (
          <li
            key={m.id}
            className={`selectable${pick.has(m.id) ? " picked" : ""}`}
          >
            <RowCheck
              checked={pick.has(m.id)}
              onChange={() => pick.toggle(m.id)}
              label={`Select ${m.title || m.alt || "image"}`}
            />
            <button
              className="media-tile"
              onClick={() => openItem(m)}
              aria-label={`Open ${m.title || m.alt || "image"}`}
            >
              <img src={m.url} alt={m.alt} loading="lazy" />
              <span>{m.title || m.alt || `#${m.id}`}</span>
              {!m.alt.trim() && <em className="media-flag">No alt text</em>}
            </button>
          </li>
        ))}
      </ul>
      {items.length === 0 && !error && (
        <p className="muted">
          {missingAlt ? "Every image has alt text." : "No images found."}
        </p>
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
          title={open.title || open.filename || "Image"}
          onClose={() => setOpen(null)}
          wide
          dismissable={!saving}
        >
          <div className="media-edit">
            <div className="media-edit-preview">
              <img className="media-preview" src={open.url} alt={edit.alt} />
              <p className="muted">
                {[
                  open.width && open.height
                    ? `${open.width} × ${open.height}px`
                    : "",
                  open.mime ?? "",
                  fmtBytes(open.bytes),
                  open.filename ?? "",
                ]
                  .filter(Boolean)
                  .join(" · ")}
              </p>
            </div>
            <div className="media-edit-fields">
              <div className="field">
                <label htmlFor="md-e-alt">
                  <span>Alt text</span>
                </label>
                <textarea
                  id="md-e-alt"
                  rows={2}
                  maxLength={400}
                  data-autofocus
                  value={edit.alt}
                  onChange={e => setEdit({ ...edit, alt: e.target.value })}
                />
                <Count n={edit.alt.length} max={125} />
                <small className="muted">
                  Describe what the image shows, in plain words. Screen readers
                  read it and search engines use it for image search. Leave it
                  empty only for purely decorative images.
                </small>
              </div>
              <div className="field">
                <label htmlFor="md-e-title">
                  <span>Title</span>
                </label>
                <input
                  id="md-e-title"
                  type="text"
                  maxLength={200}
                  value={edit.title}
                  onChange={e => setEdit({ ...edit, title: e.target.value })}
                />
                <small className="muted">
                  Shown in the media library. A clear title helps you find the
                  image later.
                </small>
              </div>
              <div className="field">
                <label htmlFor="md-e-cap">
                  <span>Caption</span>
                </label>
                <textarea
                  id="md-e-cap"
                  rows={2}
                  maxLength={500}
                  value={edit.caption}
                  onChange={e => setEdit({ ...edit, caption: e.target.value })}
                />
                <small className="muted">
                  Short text that can be shown under the image.
                </small>
              </div>
              <div className="field">
                <label htmlFor="md-e-desc">
                  <span>Description</span>
                </label>
                <textarea
                  id="md-e-desc"
                  rows={3}
                  maxLength={2000}
                  value={edit.description}
                  onChange={e =>
                    setEdit({ ...edit, description: e.target.value })
                  }
                />
                <small className="muted">
                  Longer notes: where the photo was taken, the project, credits.
                </small>
              </div>
              {!edit.alt.trim() && (
                <button
                  className="top-btn"
                  onClick={() => setEdit({ ...edit, alt: altFromTitle(open) })}
                >
                  <Wand2 size={14} aria-hidden="true" /> Suggest alt text from
                  the title
                </button>
              )}
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
            </div>
          </div>
          <div className="dialog-actions">
            <button
              className="top-btn danger"
              disabled={saving}
              onClick={() => {
                if (
                  window.confirm(
                    "Delete this image for good? The file is removed from your site and pages that use it will show a broken image. This cannot be undone."
                  )
                ) {
                  setSaving(true);
                  api
                    .deleteMedia(open.id)
                    .then(() => {
                      setNote("Image deleted.");
                      setOpen(null);
                      load(1, true);
                    })
                    .catch(e => setError(describeError(e)))
                    .finally(() => setSaving(false));
                }
              }}
            >
              <Trash2 size={14} aria-hidden="true" /> Delete
            </button>
            <button
              className="top-btn"
              onClick={() => {
                void navigator.clipboard?.writeText(open.url);
                setNote("Image address copied.");
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
              <Eye size={14} aria-hidden="true" /> Open full size
            </a>
            <button className="save-btn" disabled={saving} onClick={saveEdit}>
              {saving ? "Saving…" : "Save"}
            </button>
          </div>
        </SubPage>
      )}
    </>
  );
}
