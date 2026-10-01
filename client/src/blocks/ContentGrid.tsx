import { useContent, type ContentQuery } from "@/render/content";

type GridProps = {
  title: string;
  query: ContentQuery;
  cols: number;
  noun: string;
  mode: "editor" | "public";
};

/** Shared by the services and portfolio blocks: references a source + display rules, never copies content. */
export function ContentGrid({ title, query, cols, noun, mode }: GridProps) {
  const result = useContent(query);
  return (
    <section className="site-grid" data-state={result.status}>
      <div className="grid-head">
        <h2>{title}</h2>
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
          {result.items.map(item => (
            <article className="content-card" key={item.id}>
              {item.image ? (
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
              )}
              <div>
                <h3>{item.title}</h3>
                {item.excerpt && <p>{item.excerpt}</p>}
              </div>
            </article>
          ))}
        </div>
      )}
    </section>
  );
}
