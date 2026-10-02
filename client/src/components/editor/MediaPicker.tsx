import { useEffect, useState } from "react";
import { Search, Upload } from "lucide-react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import type { MediaItem } from "@/lib/schema/api";
import { Modal } from "../Modal";

export function MediaPicker({
  onSelect,
  onClose,
}: {
  onSelect: (m: MediaItem) => void;
  onClose: () => void;
}) {
  const [search, setSearch] = useState("");
  const [alt, setAlt] = useState("");
  const [upload, setUpload] = useState<{ busy: boolean; error?: string }>({
    busy: false,
  });
  const canUpload = api.canUploadMedia();
  const doUpload = (file: File | undefined) => {
    if (!file) return;
    if (!/^image\/(jpeg|png|gif|webp|avif)$/.test(file.type)) {
      setUpload({
        busy: false,
        error: "Choose a JPEG, PNG, GIF, WebP or AVIF image.",
      });
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      setUpload({ busy: false, error: "That image is larger than 10 MB." });
      return;
    }
    setUpload({ busy: true });
    api
      .uploadMedia(file, alt)
      .then(item => onSelect(item))
      .catch(e => setUpload({ busy: false, error: describeError(e) }));
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
    <Modal title="Choose an image" onClose={onClose} wide>
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
            {upload.busy ? "Uploading…" : "Upload image"}
            <input
              type="file"
              accept="image/jpeg,image/png,image/gif,image/webp,image/avif"
              disabled={upload.busy}
              onChange={e => doUpload(e.target.files?.[0])}
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
              onClick={() => onSelect(m)}
              aria-label={`Select ${m.title || `image ${m.id}`}`}
            >
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
    </Modal>
  );
}
