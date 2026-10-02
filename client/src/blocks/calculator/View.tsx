import { useState } from "react";
import { ArrowRight } from "lucide-react";
import type { ViewProps } from "@/render/ViewProps";
import { parseRows, safeHref } from "../links";
import type { CalculatorProps } from "./schema";

export type CalcType = { label: string; rate: number; unit: string };

/** "Label|rate|unit" lines; rows without a label or a positive rate are dropped. Mirrored by rk_builder_calc_types(). */
export function calcTypes(source: string): CalcType[] {
  return parseRows(source, 3, 8)
    .map(([label, rate, unit]) => ({ label, rate: Number(rate), unit }))
    .filter(t => t.label !== "" && Number.isFinite(t.rate) && t.rate > 0);
}

/** Whole dollars with thousands separators, e.g. "$4,400". Mirrored by rk_builder_calc_money(). */
export const calcMoney = (n: number) =>
  "$" + Math.round(n).toLocaleString("en-US", { maximumFractionDigits: 0 });

export function CalculatorView({ props }: ViewProps<CalculatorProps>) {
  const types = calcTypes(props.types);
  const [i, setI] = useState(0);
  const [amount, setAmount] = useState(props.amount);
  const cur = types[i] ?? types[0];
  const href = safeHref(props.ctaHref);
  return (
    <section className="pf-section pf-calc">
      <div className="pf-wrap pf-calc-grid">
        <div className="pf-calc-form">
          {props.heading && <h2>{props.heading}</h2>}
          <label>
            Project type
            <select
              data-calc-type=""
              onChange={e => setI(Number(e.target.value))}
            >
              {types.map((t, k) => (
                <option key={k} value={k} data-rate={t.rate} data-unit={t.unit}>
                  {t.label}
                </option>
              ))}
            </select>
          </label>
          <label>
            <span data-calc-unit="">{cur?.unit ?? ""}</span>
            <input
              type="number"
              min="1"
              defaultValue={props.amount}
              data-calc-amount=""
              onChange={e => setAmount(Number(e.target.value) || 0)}
            />
          </label>
        </div>
        <div className="pf-calc-result">
          {props.resultLabel && (
            <p className="pf-kicker">{props.resultLabel}</p>
          )}
          <p className="pf-calc-total" data-calc-total="">
            {calcMoney((cur?.rate ?? 0) * amount)}
          </p>
          {props.note && <p className="pf-calc-note">{props.note}</p>}
          {props.ctaLabel && href && (
            <a className="pf-btn dark" href={href}>
              {props.ctaLabel}
              <ArrowRight size={16} aria-hidden="true" />
            </a>
          )}
        </div>
      </div>
    </section>
  );
}
