import { ArrowRight, Check, Phone } from "lucide-react";
import type { ViewProps } from "@/render/ViewProps";
import { phoneHref } from "../contact/schema";
import { paragraphs, parseRows, safeHref } from "../links";
import type { DetailProps } from "./schema";

const lines = (s: string, max: number) =>
  s
    .split("\n")
    .map(x => x.trim())
    .filter(Boolean)
    .slice(0, max);

export function DetailView({ props }: ViewProps<DetailProps>) {
  const steps = lines(props.steps, 10);
  const factors = lines(props.factors, 10);
  const links = parseRows(props.links, 2, 6).filter(
    ([l, h]) => l !== "" && safeHref(h) !== ""
  );
  const faq = parseRows(props.faq, 2, 10).filter(([q]) => q !== "");
  const tel = phoneHref(props.phone);
  return (
    <section className="pf-section pf-detail">
      <div className="pf-wrap pf-detail-grid">
        <div className="pf-detail-main">
          <div>
            {props.eyebrow && <p className="pf-kicker">{props.eyebrow}</p>}
            <h2>{props.heading}</h2>
            {paragraphs(props.body).map((p, i) => (
              <p className="pf-body" key={i}>
                {p}
              </p>
            ))}
          </div>
          {props.note && <p className="pf-note">{props.note}</p>}
          {steps.length > 0 && (
            <div>
              {props.stepsTitle && <h3>{props.stepsTitle}</h3>}
              <ol className="pf-steps">
                {steps.map((s, i) => (
                  <li key={i}>
                    <span className="pf-step-n">{i + 1}</span>
                    <span>{s}</span>
                  </li>
                ))}
              </ol>
            </div>
          )}
          {factors.length > 0 && (
            <div>
              {props.factorsTitle && <h3>{props.factorsTitle}</h3>}
              {props.factorsIntro && (
                <p className="pf-small">{props.factorsIntro}</p>
              )}
              <ul className="pf-checkgrid">
                {factors.map((f, i) => (
                  <li key={i}>
                    <Check size={16} aria-hidden="true" />
                    <span>{f}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
          {links.length > 0 && (
            <div className="pf-linkbar">
              {links.map(([l, h], i) => (
                <a key={i} href={h}>
                  {l}
                </a>
              ))}
            </div>
          )}
          {faq.length > 0 && (
            <div>
              {props.faqTitle && <h3>{props.faqTitle}</h3>}
              <div className="pf-faq">
                {faq.map(([q, a], i) => (
                  <details key={i}>
                    <summary>
                      {q}
                      <span>+</span>
                    </summary>
                    {a && <p>{a}</p>}
                  </details>
                ))}
              </div>
            </div>
          )}
        </div>
        <aside className="pf-aside">
          {props.asideTitle && <h3>{props.asideTitle}</h3>}
          {props.asideText && <p>{props.asideText}</p>}
          {props.phone && tel && (
            <a className="pf-btn dark wide" href={tel}>
              <Phone size={16} aria-hidden="true" />
              {props.phone}
            </a>
          )}
          {props.ctaLabel && props.ctaHref && (
            <a className="pf-btn light wide" href={props.ctaHref}>
              {props.ctaLabel}
              <ArrowRight size={16} aria-hidden="true" />
            </a>
          )}
        </aside>
      </div>
    </section>
  );
}
