import type { ViewProps } from "@/render/ViewProps";
import { parseRows } from "../links";
import type { ValuesProps } from "./schema";

export function ValuesView({ props }: ViewProps<ValuesProps>) {
  const items = parseRows(props.items, 2, 8);
  return (
    <section className={`pf-section pf-values ${props.tone}`}>
      <div className="pf-wrap">
        <div className="pf-center">
          {props.eyebrow && <p className="pf-kicker">{props.eyebrow}</p>}
          <h2>{props.heading}</h2>
        </div>
        <div
          className="pf-cardgrid"
          style={{ gridTemplateColumns: `repeat(${props.cols}, minmax(0, 1fr))` }}
        >
          {items.map(([title, body], i) => (
            <article key={i}>
              <h3>{title}</h3>
              {body && <p>{body}</p>}
            </article>
          ))}
        </div>
      </div>
    </section>
  );
}
