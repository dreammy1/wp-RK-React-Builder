import { useEffect, useState } from "react";
import { Search } from "lucide-react";
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
          No images found. Upload images in the WordPress media library first.
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
