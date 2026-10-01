import type { ViewProps } from "@/render/ViewProps";
import type { TextProps } from "./schema";

/** Plain text only: paragraphs split on blank lines, always rendered as text nodes. */
export function TextView({ props }: ViewProps<TextProps>) {
  const paragraphs = props.text.split(/\n{2,}/).filter(p => p.trim() !== "");
  return (
    <section className="site-text">
      {paragraphs.map((p, i) => (
        <p key={i}>{p}</p>
      ))}
    </section>
  );
}
