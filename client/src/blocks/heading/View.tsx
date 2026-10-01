import type { ViewProps } from "@/render/ViewProps";
import type { HeadingProps } from "./schema";

export function HeadingView({ props }: ViewProps<HeadingProps>) {
  const Tag = props.level === 3 ? "h3" : "h2";
  return (
    <section className="site-heading">
      <Tag>{props.text}</Tag>
    </section>
  );
}
