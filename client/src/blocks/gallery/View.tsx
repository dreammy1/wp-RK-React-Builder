import type { ViewProps } from "@/render/ViewProps";
import { parseRows, safeImg, uniqueTags } from "../links";
import type { GalleryProps } from "./schema";

export function GalleryView({ props }: ViewProps<GalleryProps>) {
  const rows = parseRows(props.items, 3, 40).filter(
    ([src]) => safeImg(src) !== ""
  );
  const tags = props.filters ? uniqueTags(rows.map(r => r[1])) : [];
  const grid = rows.map(([src, cat, alt], i) => (
    <figure
      key={i}
      className={i === 0 ? "big" : undefined}
      {...(props.filters && cat !== "" ? { "data-tag": cat } : {})}
    >
      <img src={src} alt={alt} decoding="async" loading="lazy" />
      {(cat || alt) && (
        <figcaption>{cat && alt ? `${cat} · ${alt}` : cat || alt}</figcaption>
      )}
    </figure>
  ));
  return (
    <section className="pf-section pf-gallery">
      {tags.length > 0 ? (
        <div className="pf-wrap">
          <div className="pf-filters" role="group" aria-label="Filter">
            {["All", ...tags].map((t, k) => (
              <button
                type="button"
                className={k === 0 ? "on" : undefined}
                data-filter={t}
                key={t}
              >
                {t}
              </button>
            ))}
          </div>
          <div className="pf-gallery-grid">{grid}</div>
        </div>
      ) : (
        <div className="pf-wrap pf-gallery-grid">{grid}</div>
      )}
    </section>
  );
}
