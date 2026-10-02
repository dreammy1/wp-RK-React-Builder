import { ArrowUpRight } from "lucide-react";
import type { ViewProps } from "@/render/ViewProps";
import type { HeroProps } from "./schema";

export function HeroView({ props }: ViewProps<HeroProps>) {
  return (
    <section className={props.bgUrl ? "site-hero has-bg" : "site-hero"}>
      {props.bgUrl && (
        <img
          className="hero-bg"
          src={props.bgUrl}
          alt=""
          decoding="async"
          fetchPriority="high"
        />
      )}
      <div className="hero-rule">01 / proposition</div>
      <h1>{props.heading}</h1>
      {props.sub && <p>{props.sub}</p>}
      {props.cta && (
        <a className="site-btn" href={props.ctaHref || "#"}>
          {props.cta}
          <ArrowUpRight size={15} aria-hidden="true" />
        </a>
      )}
    </section>
  );
}
