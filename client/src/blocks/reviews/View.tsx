import { Star } from "lucide-react";
import type { ViewProps } from "@/render/ViewProps";
import { useReviews } from "@/lib/reviews";
import { safeHref } from "../links";
import type { ReviewsProps } from "./schema";

export function Stars({ rating }: { rating: number }) {
  const on = Math.max(0, Math.min(5, Math.round(rating)));
  return (
    <span className="pf-stars" role="img" aria-label={`${on} out of 5`}>
      {[0, 1, 2, 3, 4].map(i => (
        <Star
          key={i}
          size={16}
          aria-hidden="true"
          fill={i < on ? "currentColor" : "none"}
          className={i < on ? "on" : ""}
        />
      ))}
    </span>
  );
}

const clip = (s: string, n: number) =>
  s.length > n ? `${s.slice(0, n - 1).trimEnd()}…` : s;

export function ReviewsView({ props }: ViewProps<ReviewsProps>) {
  const data = useReviews();
  const items = (data?.items ?? [])
    .filter(r => !r.hidden && r.rating >= props.minRating)
    .slice(0, props.limit);
  const profile = safeHref(data?.links.profile ?? "");
  const write = safeHref(data?.links.write ?? "");
  const summary = data?.summary;
  return (
    <section className={`pf-section pf-reviews ${props.tone}`}>
      <div className="pf-wrap">
        {(props.eyebrow || props.heading || props.intro) && (
          <div className="pf-center">
            {props.eyebrow && <p className="pf-kicker">{props.eyebrow}</p>}
            {props.heading && <h2>{props.heading}</h2>}
            {props.intro && <p className="pf-intro">{props.intro}</p>}
          </div>
        )}
        {props.showSummary && summary && summary.count > 0 && (
          <div className="pf-rv-summary">
            <Stars rating={summary.rating} />
            <strong>{summary.rating.toFixed(1)}</strong>
            <span>
              {summary.count} Google review{summary.count === 1 ? "" : "s"}
            </span>
          </div>
        )}
        {props.showLinks && (profile || write) && (
          <div className="pf-rv-links">
            {profile && (
              <a
                className="pf-btn outline"
                href={profile}
                target="_blank"
                rel="noopener noreferrer"
              >
                See all reviews on Google
              </a>
            )}
            {write && (
              <a
                className="pf-btn dark"
                href={write}
                target="_blank"
                rel="noopener noreferrer"
              >
                Leave a review
              </a>
            )}
          </div>
        )}
        <div
          className="pf-cardgrid pf-rv-grid"
          style={{
            gridTemplateColumns: `repeat(${props.cols}, minmax(0, 1fr))`,
          }}
        >
          {items.map(r => (
            <article key={r.id}>
              <Stars rating={r.rating} />
              {r.text && <p className="pf-rv-text">{clip(r.text, 280)}</p>}
              <footer>
                <strong>{r.author}</strong>
                {r.date && <small>{r.date}</small>}
                {r.source === "google" && (
                  <span className="pf-rv-src">Google</span>
                )}
              </footer>
            </article>
          ))}
        </div>
      </div>
    </section>
  );
}
