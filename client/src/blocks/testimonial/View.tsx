import type { ViewProps } from "@/render/ViewProps";
import type { TestimonialProps } from "./schema";

export function TestimonialView({ props }: ViewProps<TestimonialProps>) {
  return (
    <section className="site-testimonial">
      <figure>
        <blockquote>
          <p>{props.quote}</p>
        </blockquote>
        <figcaption>
          <strong>{props.author}</strong>
          {props.role && <span>{props.role}</span>}
        </figcaption>
      </figure>
    </section>
  );
}
