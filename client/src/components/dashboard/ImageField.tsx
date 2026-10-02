import { useState } from "react";
import { ImagePlus, X } from "lucide-react";
import { MediaPicker } from "../editor/MediaPicker";

/** An image address with a preview, a media-library picker (which also uploads) and a way to clear it. */
export function ImageField({
  id,
  label,
  value,
  onChange,
  help,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (url: string) => void;
  help?: string;
}) {
  const [picking, setPicking] = useState(false);
  return (
    <div className="field">
      <label htmlFor={id}>
        <span>{label}</span>
      </label>
      <div className="image-field">
        {value && <img src={value} alt="" />}
        <div className="image-field-body">
          <input
            id={id}
            type="url"
            placeholder="https://"
            value={value}
            onChange={e => onChange(e.target.value)}
          />
          <div className="dash-actions">
            <button
              type="button"
              className="top-btn"
              onClick={() => setPicking(true)}
            >
              <ImagePlus size={14} aria-hidden="true" /> Choose or upload
            </button>
            {value && (
              <button
                type="button"
                className="top-btn"
                onClick={() => onChange("")}
              >
                <X size={14} aria-hidden="true" /> Remove
              </button>
            )}
          </div>
        </div>
      </div>
      {help && <small className="muted">{help}</small>}
      {picking && (
        <MediaPicker
          onClose={() => setPicking(false)}
          onSelect={m => {
            onChange(m.url);
            setPicking(false);
          }}
        />
      )}
    </div>
  );
}
