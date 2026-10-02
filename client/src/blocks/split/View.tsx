import { ArrowRight, ArrowUpRight } from "lucide-react";
import type { ViewProps } from "@/render/ViewProps";
import { paragraphs, parseFacts, parseLinks } from "../links";
import type { SplitProps } from "./schema";

export function SplitView({ props }: ViewProps<SplitProps>) {
  const facts = parseFacts(props.facts);
  const links = parseLinks(props.links ?? "", 4);
  return (
    <section className={`pf-section pf-split ${props.tone} ${props.side}`}>
      <div className="pf-wrap pf-split-grid">
        {props.imageUrl && (
          <img
            className="pf-split-img"
            src={props.imageUrl}
            alt={props.imageAlt}
            decoding="async"
            loading="lazy"
          />
        )}
        <div className="pf-split-copy">
          {props.eyebrow && <p className="pf-kicker">{props.eyebrow}</p>}
          <h2>{props.heading}</h2>
          {paragraphs(props.body).map((p, i) => (
            <p className="pf-body" key={i}>
              {p}
            </p>
          ))}
          {facts.length > 0 && (
            <dl className="pf-facts">
              {facts.map(f => (
                <div key={f.value + f.label}>
                  <dt>{f.value}</dt>
                  <dd>{f.label}</dd>
                </div>
              ))}
            </dl>
          )}
          {props.cta && props.ctaHref && (
            <a className="pf-btn dark" href={props.ctaHref}>
              {props.cta}
              <ArrowRight size={16} aria-hidden="true" />
            </a>
          )}
          {links.length > 0 && (
            <div className="pf-split-links">
              {links.map(l => (
                <a className="pf-more" href={l.href} key={l.href + l.label}>
                  {l.label}
                  <ArrowUpRight size={15} aria-hidden="true" />
                </a>
              ))}
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
