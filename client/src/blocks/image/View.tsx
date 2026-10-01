import type { ViewProps } from "@/render/ViewProps";
import type { ImageProps } from "./schema";

export function ImageView({ props, mode }: ViewProps<ImageProps>) {
  return (
    <section className="site-image">
      <img
        src={props.url}
        alt={props.decorative ? "" : props.alt}
        width={props.width}
        height={props.height}
        srcSet={props.srcset || undefined}
        sizes={props.srcset ? "(min-width: 1040px) 1040px, 100vw" : undefined}
        loading={mode === "public" ? "lazy" : undefined}
        decoding="async"
      />
    </section>
  );
}
