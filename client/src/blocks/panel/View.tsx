import { ArrowRight, ArrowUpRight, Check } from "lucide-react";
import type { ViewProps } from "@/render/ViewProps";
import { paragraphs, parseRows, safeHref } from "../links";
import type { PanelProps } from "./schema";

/** A label starting with "!" is a solid button. With no "!" anywhere, `solidFirst` makes the first one solid. */
function Actions({
  source,
  solidFirst,
  buttons = false,
}: {
  source: string;
  solidFirst: boolean;
  buttons?: boolean;
}) {
  const rows = parseRows(source, 2, 6).filter(([l, h]) => l !== "" && safeHref(h) !== "");
  if (rows.length === 0) return null;
  const marked = rows.some(([l]) => l.startsWith("!"));
  return (
    <div className={buttons ? "pf-links btns" : "pf-links"}>
      {rows.map(([label, href], i) => {
        const solid = marked ? label.startsWith("!") : solidFirst && i === 0;
        const text = label.startsWith("!") ? label.slice(1).trim() : label;
        const cls = solid ? "pf-btn dark" : buttons ? "pf-btn outline" : "pf-more";
        return (
          <a key={i} className={cls} href={href}>
            {text}
            <ArrowRight size={16} aria-hidden="true" />
          </a>
        );
      })}
    </div>
  );
}

function Checks({ source }: { source: string }) {
  const lines = source.split("\n").map(s => s.trim()).filter(Boolean).slice(0, 8);
  if (lines.length === 0) return null;
  return (
    <ul className="pf-checks">
      {lines.map((l, i) => (
        <li key={i}>
          <Check size={16} aria-hidden="true" />
          {l}
        </li>
      ))}
    </ul>
  );
}

export function PanelView({ props }: ViewProps<PanelProps>) {
  const rows = parseRows(props.items, 3, 8);
  const intro = props.mode === "intro";
  const copy = (
    <div className={props.box ? "pf-panel-copy box" : "pf-panel-copy"}>
      {props.eyebrow && <p className="pf-kicker">{props.eyebrow}</p>}
      {props.heading && <h2>{props.heading}</h2>}
      {!intro && paragraphs(props.body).map((p, i) => (
        <p className="pf-body" key={i}>{p}</p>
      ))}
      {!intro && !props.flip && rows.length > 0 && <Actions source={props.actions} solidFirst />}
    </div>
  );
  const side = intro ? (
    <div className="pf-panel-rows">
      {paragraphs(props.body).map((p, i) => (
        <p className="pf-body" key={i}>{p}</p>
      ))}
      <Checks source={props.checks} />
      <Actions source={props.actions} solidFirst={false} />
    </div>
  ) : rows.length === 0 ? (
    <div className="pf-panel-rows">
      <Actions source={props.actions} solidFirst={false} buttons />
    </div>
  ) : (
    <div className="pf-panel-rows">
      {props.flip && props.kicker && <p className="pf-kicker">{props.kicker}</p>}
      <div className={`pf-rows ${props.itemStyle}`}>
        {rows.map(([title, body, href], i) => {
          const inner = (
            <>
              <span className="pf-row-text">
                <strong>{title}</strong>
                {body && <span>{body}</span>}
              </span>
              {safeHref(href) && <ArrowUpRight size={16} aria-hidden="true" />}
            </>
          );
          return safeHref(href) ? (
            <a key={i} className="pf-row" href={href}>{inner}</a>
          ) : (
            <div key={i} className="pf-row">{inner}</div>
          );
        })}
      </div>
      {props.flip && <Actions source={props.actions} solidFirst={false} />}
    </div>
  );
  return (
    <section className={`pf-section pf-panel ${props.tone} ${props.mode}${props.flip ? " flip" : ""}`}>
      <div className="pf-wrap pf-panel-grid">
        {props.flip ? <>{side}{copy}</> : <>{copy}{side}</>}
      </div>
    </section>
  );
}
