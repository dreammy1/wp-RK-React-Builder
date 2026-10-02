import { ArrowRight, ArrowUpRight, CheckCircle2 } from "lucide-react";
import type { ViewProps } from "@/render/ViewProps";
import {
  HEX,
  blurbTag,
  paragraphs,
  parseRows,
  safeHref,
  safeImg,
  semi,
  uniqueTags,
} from "../links";
import type { CatalogProps } from "./schema";

export function CatalogView({ props }: ViewProps<CatalogProps>) {
  const rows = parseRows(props.items, 7, 24);
  const modals = parseRows(props.modals ?? "", 4, 24);
  const modalLabel = props.modalLabel || "View all products";
  const [ctaLabel, ctaLink] = parseRows(props.modalCta ?? "", 2, 1)[0] ?? [
    "",
    "",
  ];
  const ctaHref = safeHref(ctaLink);
  const tags = props.filters ? uniqueTags(rows.map(r => blurbTag(r[3]))) : [];
  return (
    <section className={`pf-section pf-catalog ${props.tone}`}>
      <div className="pf-wrap">
        {(props.eyebrow || props.heading || props.intro) && (
          <div className="pf-catalog-head">
            {props.eyebrow && <p className="pf-kicker">{props.eyebrow}</p>}
            {props.heading && <h2>{props.heading}</h2>}
            {paragraphs(props.intro).map((p, i) => (
              <p className="pf-body" key={i}>
                {p}
              </p>
            ))}
          </div>
        )}
        {tags.length > 0 && (
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
        )}
        <div
          className={props.joined ? "pf-cards joined" : "pf-cards"}
          style={{
            gridTemplateColumns: `repeat(${props.cols}, minmax(0, 1fr))`,
          }}
        >
          {rows.map(
            ([image, eyebrow, title, blurb, specs, bullets, link], i) => {
              const swatch = HEX.test(image);
              const src = swatch ? "" : safeImg(image);
              const href = safeHref(link);
              const specRows = semi(specs).flatMap(s => {
                const cut = s.indexOf(":");
                if (cut < 1) return [];
                const k = s.slice(0, cut).trim();
                const v = s.slice(cut + 1).trim();
                return k !== "" && v !== "" ? [[k, v] as const] : [];
              });
              const bulletList = semi(bullets);
              const body = (
                <>
                  {swatch && (
                    <div
                      className="pf-card-media swatch"
                      style={{ backgroundColor: image }}
                    />
                  )}
                  {src && (
                    <div className="pf-card-media">
                      <img src={src} alt="" decoding="async" loading="lazy" />
                      {props.numbered && (
                        <span className="pf-badge">
                          {String(i + 1).padStart(2, "0")}
                        </span>
                      )}
                    </div>
                  )}
                  <div className="pf-card-body">
                    {eyebrow && <p className="pf-card-eyebrow">{eyebrow}</p>}
                    {href ? (
                      <div className="pf-card-title">
                        <h3>{title}</h3>
                        <ArrowUpRight size={20} aria-hidden="true" />
                      </div>
                    ) : (
                      <h3>{title}</h3>
                    )}
                    {blurb && <p>{blurb}</p>}
                    {specRows.length > 0 && (
                      <dl className="pf-specs">
                        {specRows.map(([k, v]) => (
                          <div key={k}>
                            <dt>{k}</dt>
                            <dd>{v}</dd>
                          </div>
                        ))}
                      </dl>
                    )}
                    {bulletList.length > 0 && (
                      <ul className="pf-bullets">
                        {bulletList.map((b, j) => (
                          <li key={j}>
                            <CheckCircle2 size={16} aria-hidden="true" />
                            {b}
                          </li>
                        ))}
                      </ul>
                    )}
                    {!href && modals[i] && modals[i][1] !== "" && (
                      <button
                        type="button"
                        className="pf-open"
                        data-modal-open={i}
                      >
                        {modalLabel}
                        <ArrowRight size={16} aria-hidden="true" />
                      </button>
                    )}
                  </div>
                </>
              );
              const tag = props.filters ? blurbTag(blurb) : "";
              const tagAttr = tag !== "" ? { "data-tag": tag } : {};
              return href ? (
                <a className="pf-card link" href={href} key={i} {...tagAttr}>
                  {body}
                </a>
              ) : (
                <article className="pf-card" key={i} {...tagAttr}>
                  {body}
                </article>
              );
            }
          )}
        </div>
        {modals.map(([image, title, intro, items], i) => {
          if (title === "") return null;
          const src = safeImg(image);
          return (
            <dialog
              className="pf-modal"
              data-modal={i}
              aria-label={title}
              key={i}
            >
              <button
                type="button"
                className="pf-modal-x"
                aria-label="Close"
                data-modal-close=""
              >
                ×
              </button>
              <div className="pf-modal-grid">
                {src && (
                  <div className="pf-modal-img">
                    <img src={src} alt="" decoding="async" loading="lazy" />
                  </div>
                )}
                <div className="pf-modal-body">
                  <p className="pf-kicker">Product catalog</p>
                  <h2>{title}</h2>
                  {intro && <p>{intro}</p>}
                  <ul className="pf-modal-items">
                    {semi(items, 40).map((it, j) => (
                      <li key={j}>{it}</li>
                    ))}
                  </ul>
                  {ctaLabel && ctaHref && (
                    <a className="pf-btn dark" href={ctaHref}>
                      {ctaLabel}
                      <ArrowRight size={16} aria-hidden="true" />
                    </a>
                  )}
                </div>
              </div>
            </dialog>
          );
        })}
      </div>
    </section>
  );
}
