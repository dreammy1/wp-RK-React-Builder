import type { ViewProps } from "@/render/ViewProps";
import type { BrandstripProps } from "./schema";

export function BrandstripView({ props }: ViewProps<BrandstripProps>) {
  const names = props.items
    .split("\n")
    .map(s => s.trim())
    .filter(s => s !== "")
    .slice(0, 12);
  return (
    <section className="pf-section pf-brands">
      <div className="pf-wrap">
        {props.label && <p className="pf-brands-label">{props.label}</p>}
        {names.length > 0 && (
          <ul>
            {names.map((n, i) => (
              <li key={i}>{n}</li>
            ))}
          </ul>
        )}
      </div>
    </section>
  );
}
