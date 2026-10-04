import { useEffect, useState } from "react";
import { Search, Upload } from "lucide-react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import type { MediaItem } from "@/lib/schema/api";
import { Modal } from "../Modal";

export function MediaPicker({
  onSelect,
  onClose,
  multiple = false,
  limit = 40,
  onSelectMany,
}: {
  onSelect: (m: MediaItem) => void;
  onClose: () => void;
  /** Pick several images (tick them, then confirm): they come back in the order picked through onSelectMany. */
  multiple?: boolean;
  limit?: number;
  onSelectMany?: (items: MediaItem[]) => void;
}) {
  const [search, setSearch] = useState("");
  const [picked, setPicked] = useState<MediaItem[]>([]);
  const isPicked = (m: MediaItem) => picked.some(p => p.id === m.id);
  const toggle = (m: MediaItem) =>
    setPicked(p =>
      p.some(x => x.id === m.id)
        ? p.filter(x => x.id !== m.id)
        : p.length < limit
          ? [...p, m]
          : p
    );
  const [alt, setAlt] = useState("");
  const [upload, setUpload] = useState<{ busy: boolean; error?: string }>({
    busy: false,
  });
  const canUpload = api.canUploadMedia();
  const doUpload = (files: FileList | File[] | undefined) => {
    const list = files ? Array.from(files) : [];
    if (list.length === 0) return;
    for (const file of list) {
      if (!/^image\/(jpeg|png|gif|webp|avif)$/.test(file.type)) {
        setUpload({
          busy: false,
          error: "Choose JPEG, PNG, GIF, WebP or AVIF images.",
        });
        return;
      }
      if (file.size > 10 * 1024 * 1024) {
        setUpload({
          busy: false,
          error: `${file.name} is larger than 10 MB.`,
        });
        return;
      }
    }
    setUpload({ busy: true });
    if (!multiple) {
      api
        .uploadMedia(list[0]!, alt)
        .then(item => onSelect(item))
        .catch(e => setUpload({ busy: false, error: describeError(e) }));
      return;
    }
    // several: upload one after another, tick each as it arrives
    (async () => {
      for (const file of list.slice(0, Math.max(0, limit - picked.length))) {
        const item = await api.uploadMedia(file, alt);
        setPicked(p => (p.length < limit ? [...p, item] : p));
        setState(s => ({ ...s, items: [item, ...s.items] }));
      }
      setUpload({ busy: false });
    })().catch(e => setUpload({ busy: false, error: describeError(e) }));
  };
  const [state, setState] = useState<{
    status: "loading" | "ready" | "error";
    items: MediaItem[];
    error?: string;
  }>({ status: "loading", items: [] });
  useEffect(() => {
    const ctrl = new AbortController();
    setState(s => ({ ...s, status: "loading" }));
    const t = setTimeout(
      () => {
        api
          .listMedia(search, ctrl.signal)
          .then(r => setState({ status: "ready", items: r.items }))
          .catch(e => {
            if (!ctrl.signal.aborted)
              setState({ status: "error", items: [], error: describeError(e) });
          });
      },
      search ? 250 : 0
    );
    return () => {
      clearTimeout(t);
      ctrl.abort();
    };
  }, [search]);
  return (
    <Modal
      title={multiple ? "Choose photos" : "Choose an image"}
      onClose={onClose}
      wide
    >
      <label className="field search-field">
        <span>Search media library</span>
        <div className="input-icon">
          <Search size={14} aria-hidden="true" />
          <input
            data-autofocus
            type="search"
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Filename or title"
          />
        </div>
      </label>
      {canUpload && (
        <div className="media-upload">
          <label className="field">
            <span>Alt text for a new upload</span>
            <input
              type="text"
              value={alt}
              maxLength={300}
              onChange={e => setAlt(e.target.value)}
              placeholder="Describe the image (optional for decorative images)"
            />
          </label>
          <label className="top-btn media-upload-btn">
            <Upload size={14} aria-hidden="true" />{" "}
            {upload.busy
              ? "Uploading…"
              : multiple
                ? "Upload images"
                : "Upload image"}
            <input
              type="file"
              accept="image/jpeg,image/png,image/gif,image/webp,image/avif"
              multiple={multiple}
              disabled={upload.busy}
              onChange={e => {
                doUpload(e.target.files ?? undefined);
                e.target.value = "";
              }}
            />
          </label>
          {upload.error && (
            <p className="form-error" role="alert">
              {upload.error}
            </p>
          )}
        </div>
      )}
      <div role="status" aria-live="polite" className="sr-only">
        {state.status === "ready" ? `${state.items.length} images found` : ""}
      </div>
      {state.status === "loading" && <p className="muted">Loading media…</p>}
      {state.status === "error" && (
        <p className="form-error" role="alert">
          {state.error}
        </p>
      )}
      {state.status === "ready" && state.items.length === 0 && (
        <p className="muted">
          {canUpload
            ? "No images yet. Upload one above."
            : "No images found. Upload images in the WordPress media library first."}
        </p>
      )}
      <ul className="media-grid">
        {state.items.map(m => (
          <li key={m.id}>
            <button
              className={multiple && isPicked(m) ? "picked" : undefined}
              aria-pressed={multiple ? isPicked(m) : undefined}
              disabled={multiple && !isPicked(m) && picked.length >= limit}
              onClick={() => (multiple ? toggle(m) : onSelect(m))}
              aria-label={`Select ${m.title || `image ${m.id}`}`}
            >
              {multiple && isPicked(m) && (
                <b className="media-tick" aria-hidden="true">
                  {picked.findIndex(p => p.id === m.id) + 1}
                </b>
              )}
              <img
                src={m.url}
                alt=""
                width={m.width}
                height={m.height}
                loading="lazy"
              />
              <span>{m.title || `Image ${m.id}`}</span>
            </button>
          </li>
        ))}
      </ul>
      {multiple && (
        <div className="dialog-actions">
          <span className="muted" role="status" aria-live="polite">
            {picked.length} selected (up to {limit})
          </span>
          <button className="top-btn" onClick={onClose}>
            Cancel
          </button>
          <button
            className="save-btn"
            disabled={picked.length === 0 || upload.busy}
            onClick={() => onSelectMany?.(picked)}
          >
            Add {picked.length || ""} photo{picked.length === 1 ? "" : "s"}
          </button>
        </div>
      )}
    </Modal>
  );
}
