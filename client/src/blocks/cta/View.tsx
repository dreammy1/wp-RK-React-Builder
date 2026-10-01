import { ArrowUpRight } from "lucide-react";
import type { ViewProps } from "@/render/ViewProps";
import type { CtaProps } from "./schema";

export function CtaView({ props }: ViewProps<CtaProps>) {
  return (
    <section className="site-cta">
      <h3>{props.heading}</h3>
      <a className="site-btn inverse" href={props.ctaHref || "#"}>
        {props.cta}
        <ArrowUpRight size={15} aria-hidden="true" />
      </a>
    </section>
  );
}
