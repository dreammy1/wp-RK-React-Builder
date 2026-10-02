import { ArrowRight, Phone } from "lucide-react";
import type { ViewProps } from "@/render/ViewProps";
import { parseRows, safeHref } from "../links";
import type { CoverheroProps } from "./schema";

export function CoverheroView({ props }: ViewProps<CoverheroProps>) {
  const crumbs = parseRows(props.crumb, 2, 3).filter(([l]) => l !== "");
  return (
    <section className={`pf-hero ${props.size}`}>
      {props.bgUrl && (
        <img
          className="pf-hero-bg"
          src={props.bgUrl}
          alt=""
          decoding="async"
          fetchPriority="high"
        />
      )}
      <div className="pf-hero-shade" />
      <div className="pf-hero-body">
        {crumbs.length > 0 && (
          <nav className="pf-crumbs" aria-label="Breadcrumb">
            <a href="/">Home</a>
            {crumbs.map(([label, href], i) =>
              safeHref(href) && i < crumbs.length - 1 ? (
                <span key={i}>
                  <i aria-hidden="true">›</i>
                  <a href={href}>{label}</a>
                </span>
              ) : (
                <span key={i}>
                  <i aria-hidden="true">›</i>
                  {label}
                </span>
              )
            )}
          </nav>
        )}
        {props.eyebrow && <p className="pf-eyebrow">{props.eyebrow}</p>}
        <h1>{props.heading}</h1>
        {props.sub && <p className="pf-lead">{props.sub}</p>}
        {(props.cta || props.cta2) && (
          <div className="pf-actions">
            {props.cta && (
              <a className="pf-btn solid" href={props.ctaHref || "#"}>
                {props.ctaHref.startsWith("tel:") && (
                  <Phone size={16} aria-hidden="true" />
                )}
                {props.cta}
              </a>
            )}
            {props.cta2 && (
              <a className="pf-btn ghost" href={props.cta2Href || "#"}>
                {props.cta2}
                <ArrowRight size={16} aria-hidden="true" />
              </a>
            )}
          </div>
        )}
      </div>
    </section>
  );
}
