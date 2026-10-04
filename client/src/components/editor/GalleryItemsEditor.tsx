import { useState } from "react";
import { ArrowDown, ArrowUp, ImagePlus, Trash2 } from "lucide-react";
import { parseRows } from "@/blocks/links";
import type { MediaItem } from "@/lib/schema/api";
import { MediaPicker } from "./MediaPicker";

export const MAX_GALLERY_PHOTOS = 40;

export type GalleryPhoto = { src: string; category: string; caption: string };

/** The separators the text format uses cannot appear inside a value. */
const clean = (s: string) => s.replace(/\r?\n/g, " ").replace(/\|/g, "/");

export function parsePhotos(items: string): GalleryPhoto[] {
  return parseRows(items, 3, MAX_GALLERY_PHOTOS).map(
    ([src, category, caption]) => ({
      src: src ?? "",
      category: category ?? "",
      caption: caption ?? "",
    })
  );
}

export function serializePhotos(photos: GalleryPhoto[]): string {
  return photos
    .filter(p => p.src.trim() !== "")
    .slice(0, MAX_GALLERY_PHOTOS)
    .map(p =>
      [clean(p.src.trim()), clean(p.category.trim()), clean(p.caption.trim())]
        .join("|")
        .replace(/\|+$/, "")
    )
    .join("\n");
}

/** The caption a picked image starts with: its alt text, else its title. */
export const photoFromMedia = (m: MediaItem): GalleryPhoto => ({
  src: m.url,
  category: "",
  caption: m.alt || m.title || "",
});

/** Photo-by-photo editor: pick from the media library (several at once), reorder, categorise, caption, remove. */
export function GalleryItemsEditor({
  uid,
  label,
  items,
  error,
  onChange,
}: {
  uid: string;
  label: string;
  items: string;
  error?: string;
  onChange: (items: string) => void;
}) {
  const [picking, setPicking] = useState(false);
  const photos = parsePhotos(items);
  const set = (next: GalleryPhoto[]) => onChange(serializePhotos(next));
  const patch = (i: number, p: Partial<GalleryPhoto>) =>
    set(photos.map((x, j) => (j === i ? { ...x, ...p } : x)));
  const move = (i: number, d: -1 | 1) => {
    const j = i + d;
    if (j < 0 || j >= photos.length) return;
    const next = photos.slice();
    [next[i], next[j]] = [next[j]!, next[i]!];
    set(next);
  };
  const room = MAX_GALLERY_PHOTOS - photos.length;

  return (
    <div className="field gi">
      <span className="field-label" id={`${uid}-l`}>
        {label}{" "}
        <small className="muted">
          ({photos.length} of {MAX_GALLERY_PHOTOS})
        </small>
      </span>
      <div className="gi-bar">
        <button
          type="button"
          className="top-btn"
          disabled={room <= 0}
          aria-describedby={`${uid}-l`}
          onClick={() => setPicking(true)}
        >
          <ImagePlus size={14} aria-hidden="true" /> Add photos
        </button>
        {photos.length > 0 && (
          <button
            type="button"
            className="top-btn"
            onClick={() => {
              if (window.confirm("Remove all photos from this gallery?"))
                onChange("");
            }}
          >
            <Trash2 size={14} aria-hidden="true" /> Remove all
          </button>
        )}
      </div>
      {photos.length === 0 && (
        <p className="muted">
          No photos yet. Add some from your media library, or upload new ones.
        </p>
      )}
      <ol className="gi-list">
        {photos.map((p, i) => (
          <li key={`${i}-${p.src}`} className="gi-row">
            <div
              className="gi-thumb"
              style={{ backgroundImage: `url("${p.src}")` }}
              role="img"
              aria-label={p.caption || `Photo ${i + 1}`}
            />
            <div className="gi-fields">
              <input
                type="text"
                aria-label={`Photo ${i + 1} category`}
                placeholder="Category (for the filter buttons)"
                maxLength={60}
                value={p.category}
                onChange={e => patch(i, { category: e.target.value })}
              />
              <input
                type="text"
                aria-label={`Photo ${i + 1} caption and alt text`}
                placeholder="Caption (also the alt text)"
                maxLength={200}
                value={p.caption}
                onChange={e => patch(i, { caption: e.target.value })}
              />
            </div>
            <div className="gi-actions">
              <button
                type="button"
                className="icon-btn"
                disabled={i === 0}
                onClick={() => move(i, -1)}
                aria-label={`Move photo ${i + 1} up`}
              >
                <ArrowUp size={14} aria-hidden="true" />
              </button>
              <button
                type="button"
                className="icon-btn"
                disabled={i === photos.length - 1}
                onClick={() => move(i, 1)}
                aria-label={`Move photo ${i + 1} down`}
              >
                <ArrowDown size={14} aria-hidden="true" />
              </button>
              <button
                type="button"
                className="icon-btn danger"
                onClick={() => set(photos.filter((_, j) => j !== i))}
                aria-label={`Remove photo ${i + 1}`}
              >
                <Trash2 size={14} aria-hidden="true" />
              </button>
            </div>
          </li>
        ))}
      </ol>
      <details className="gi-text">
        <summary>Edit as text</summary>
        <textarea
          id={`${uid}-t`}
          rows={5}
          maxLength={12000}
          aria-label="Photos as text, one per line: image URL|Category|Caption"
          value={items}
          onChange={e => onChange(e.target.value)}
        />
        <small className="muted">
          One per line: image URL|Category|Caption. The first is shown large.
        </small>
      </details>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      {picking && (
        <MediaPicker
          multiple
          limit={room}
          onClose={() => setPicking(false)}
          onSelect={() => undefined}
          onSelectMany={ms => {
            set([...photos, ...ms.map(photoFromMedia)]);
            setPicking(false);
          }}
        />
      )}
    </div>
  );
}
