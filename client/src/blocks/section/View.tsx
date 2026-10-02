import { ArrowUpRight } from "lucide-react";
import type { ViewProps } from "@/render/ViewProps";
import { paragraphs } from "../links";
import type { SectionProps } from "./schema";

export function SectionView({ props }: ViewProps<SectionProps>) {
  return (
    <section
      className={`pf-section ${props.tone}${props.center ? " center" : ""}`}
    >
      <div className="pf-wrap">
        <div className="pf-section-head">
          <div>
            {props.eyebrow && <p className="pf-kicker">{props.eyebrow}</p>}
            {props.heading && <h2>{props.heading}</h2>}
          </div>
          {props.linkLabel && props.linkHref && (
            <a className="pf-more" href={props.linkHref}>
              {props.linkLabel}
              <ArrowUpRight size={15} aria-hidden="true" />
            </a>
          )}
        </div>
        {paragraphs(props.body).map((p, i) => (
          <p className="pf-body" key={i}>
            {p}
          </p>
        ))}
      </div>
    </section>
  );
}
