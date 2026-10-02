import { ArrowRight, ArrowUpRight } from "lucide-react";
import type { ViewProps } from "@/render/ViewProps";
import { lines, safeHref } from "../links";
import {
  VIZ_GROUPS,
  VIZ_GROUPS_AFTER_STYLE,
  vizSlug,
  type VizGroup,
} from "./options";
import type { VisualizerProps } from "./schema";

function Group({ group }: { group: VizGroup }) {
  return (
    <fieldset className="pf-viz-group">
      <legend>{group.legend}</legend>
      <div>
        {group.options.map(opt => (
          <label className="pf-chip" key={opt.value}>
            <input type="radio" name={group.name} value={opt.value} />
            <span>{opt.label}</span>
          </label>
        ))}
      </div>
    </fieldset>
  );
}

/** The AI visualizer form and preview. Behaviour comes from the inline site script; the markup is complete without it. */
export function VisualizerView({ props }: ViewProps<VisualizerProps>) {
  const cities = lines(props.cities, 20).map(label => ({
    value: vizSlug(label),
    label,
  }));
  const href = safeHref(props.ctaHref);
  return (
    <section className="pf-section pf-viz">
      <div className="pf-wrap pf-viz-grid">
        <form className="pf-viz-form" noValidate data-viz-form="">
          <label className="pf-viz-drop">
            <span className="pf-viz-drop-title">Upload a room photo</span>
            <span className="pf-viz-hint" aria-live="polite" data-viz-hint="">
              Your photo stays in this browser preview until you generate a
              visualization.
            </span>
            <input
              type="file"
              name="image"
              accept="image/jpeg,image/png,image/webp"
            />
          </label>
          <p className="pf-viz-error" role="alert" hidden data-viz-error=""></p>
          {VIZ_GROUPS.map(g => (
            <Group group={g} key={g.name} />
          ))}
          <label className="pf-viz-field" hidden data-viz-custom="">
            Describe the style you want
            <textarea
              name="customStyleDescription"
              maxLength={500}
              placeholder="Warm medium-brown oak with a natural, matte finish"
            ></textarea>
          </label>
          {VIZ_GROUPS_AFTER_STYLE.map(g => (
            <Group group={g} key={g.name} />
          ))}
          {cities.length > 0 && (
            <Group
              group={{
                name: "serviceCity",
                legend: "Service city (optional)",
                options: cities,
              }}
            />
          )}
          <label className="pf-viz-field">
            Approximate square footage (optional)
            <input
              type="number"
              name="squareFootage"
              min="0"
              inputMode="numeric"
            />
          </label>
          <p className="pf-viz-quota" aria-live="polite" data-viz-quota=""></p>
          <button type="submit" className="pf-btn dark" data-viz-submit="">
            {props.submitLabel}
            <ArrowRight size={16} aria-hidden="true" />
          </button>
        </form>
        <aside className="pf-viz-aside">
          <div className="pf-viz-stage">
            <img className="pf-viz-img" alt="" hidden data-viz-img="" />
            <p className="pf-viz-empty" data-viz-empty="">
              Upload a room photo to preview your space here.
            </p>
            <span className="pf-viz-badge" data-viz-badge="">
              Approximate color preview
            </span>
            <div className="pf-viz-busy" hidden data-viz-busy="">
              <span>Creating your floor visualization…</span>
            </div>
          </div>
          <div className="pf-viz-done" hidden data-viz-done="">
            <p>
              Your visualization concept is ready. This is an AI-assisted
              concept to help you explore ideas — not an exact rendering, a
              guaranteed color match, or a construction-ready plan.
            </p>
            <div className="pf-viz-orig">
              <img
                alt="Your original uploaded room photo, unchanged"
                data-viz-orig=""
              />
              <span>Original photo</span>
            </div>
          </div>
          {props.ctaLabel && href && (
            <div className="pf-viz-talk">
              <p>Want a real sample in your lighting?</p>
              <a className="pf-more" href={href}>
                {props.ctaLabel}
                <ArrowUpRight size={15} aria-hidden="true" />
              </a>
            </div>
          )}
        </aside>
        <dialog
          className="pf-modal pf-viz-lead"
          aria-label="Unlock one more visualization"
          data-viz-lead=""
        >
          <button
            type="button"
            className="pf-modal-x"
            aria-label="Close"
            data-modal-close=""
          >
            ×
          </button>
          <form className="pf-viz-leadform" noValidate data-viz-leadform="">
            <h2>Unlock one more visualization</h2>
            <p>
              Share your contact details and we will unlock another free
              visualization.
            </p>
            <label className="pf-viz-field">
              Name
              <input
                type="text"
                name="name"
                maxLength={120}
                autoComplete="name"
              />
            </label>
            <label className="pf-viz-field">
              Email
              <input type="email" name="email" autoComplete="email" />
            </label>
            <label className="pf-viz-field">
              Phone (optional)
              <input
                type="tel"
                name="phone"
                maxLength={30}
                autoComplete="tel"
              />
            </label>
            <p
              className="pf-viz-error"
              role="alert"
              hidden
              data-viz-lead-error=""
            ></p>
            <button type="submit" className="pf-btn dark">
              Unlock my visualization
              <ArrowRight size={16} aria-hidden="true" />
            </button>
          </form>
        </dialog>
      </div>
    </section>
  );
}
