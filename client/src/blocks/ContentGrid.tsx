import { useContent, type ContentQuery } from "@/render/content";
import { gridClass, type GridOptions } from "./gridOptions";
import { safeHref } from "./links";

type GridProps = {
  title: string;
  query: ContentQuery;
  cols: number;
  noun: string;
  mode: "editor" | "public";
  opts?: GridOptions;
};

/** Shared by the services and portfolio blocks: references a source + display rules, never copies content. */
export function ContentGrid({
  title,
  query,
  cols,
  noun,
  mode,
  opts = {},
}: GridProps) {
  const result = useContent(query);
  return (
    <section className={gridClass(opts)} data-state={result.status}>
      <div className="grid-head">
        {opts.eyebrow || opts.intro ? (
          <div>
            {opts.eyebrow && <p className="pf-kicker">{opts.eyebrow}</p>}
            <h2>{title}</h2>
            {opts.intro && <p className="grid-intro">{opts.intro}</p>}
          </div>
        ) : (
          <h2>{title}</h2>
        )}
        {mode === "editor" && result.status === "ready" && (
          <span className="grid-count">
            {result.items.length} / {result.total} live
          </span>
        )}
      </div>
      {result.status === "loading" && (
        <div
          className="cards"
          style={{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }}
          aria-busy="true"
        >
          {Array.from({ length: Math.min(query.limit, cols) }, (_, i) => (
            <div className="content-card skeleton" key={i} />
          ))}
        </div>
      )}
      {result.status === "error" && (
        <p className="grid-note" role={mode === "editor" ? "alert" : undefined}>
          {mode === "editor"
            ? `Could not load ${noun}s from WordPress. ${result.error ?? ""}`
            : `${noun[0]!.toUpperCase()}${noun.slice(1)}s are temporarily unavailable.`}
        </p>
      )}
      {result.status === "ready" && result.items.length === 0 && (
        <p className="grid-note">
          {mode === "editor"
            ? `No published ${noun}s match this filter yet.`
            : `No ${noun}s to show yet.`}
        </p>
      )}
      {result.status === "ready" && result.items.length > 0 && (
        <div
          className="cards"
          style={{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }}
        >
          {result.items.map(item => {
            const mode2 = opts.cardLink ?? "none";
            const href = mode2 !== "none" ? safeHref(item.link) : "";
            const lines = opts.excerptLines ?? 0;
            return (
              <article className="content-card" key={item.id}>
                {opts.showImage !== false &&
                  (item.image ? (
                    <img
                      src={item.image.url}
                      alt={item.image.alt}
                      width={item.image.width}
                      height={item.image.height}
                      srcSet={item.image.srcset}
                      sizes={`(min-width: 900px) ${Math.round(100 / cols)}vw, 100vw`}
                      loading={mode === "public" ? "lazy" : undefined}
                      decoding="async"
                    />
                  ) : (
                    <div className="card-placeholder" aria-hidden="true" />
                  ))}
                <div>
                  {opts.showCategories && item.categories.length > 0 && (
                    <p className="card-tags">
                      {item.categories.map((c, i) => (
                        <span key={i}>{c}</span>
                      ))}
                    </p>
                  )}
                  <h3>
                    {mode2 === "title" && href ? (
                      <a href={href}>{item.title}</a>
                    ) : (
                      item.title
                    )}
                  </h3>
                  {opts.showExcerpt !== false && item.excerpt && (
                    <p
                      className={lines > 0 ? "clamp" : undefined}
                      style={lines > 0 ? { WebkitLineClamp: lines } : undefined}
                    >
                      {item.excerpt}
                    </p>
                  )}
                  {mode2 === "button" && href && (
                    <a className="card-btn" href={href}>
                      {opts.buttonLabel || "Learn more"}
                    </a>
                  )}
                </div>
              </article>
            );
          })}
        </div>
      )}
      {result.status === "ready" &&
        result.items.length > 0 &&
        opts.viewAllLabel &&
        safeHref(opts.viewAllHref ?? "") && (
          <div className="grid-more">
            <a className="card-btn" href={safeHref(opts.viewAllHref ?? "")}>
              {opts.viewAllLabel}
            </a>
          </div>
        )}
    </section>
  );
}
