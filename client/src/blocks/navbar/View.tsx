import { useState } from "react";
import { Phone } from "lucide-react";
import type { ViewProps } from "@/render/ViewProps";
import { parseMenu } from "../links";
import type { NavbarProps } from "./schema";

const LOGO_HEIGHT = { sm: "40", md: "64", lg: "88" } as const;

/** The bar's class list. Mirrored by rk_builder_render_navbar(). */
export function navClasses(props: NavbarProps, open = false): string {
  const bg = props.bg ?? "auto";
  const size = props.size ?? "regular";
  const buttons = props.buttons ?? "auto";
  return (
    (props.overlay ? "pf-nav overlay" : "pf-nav") +
    (bg !== "auto" ? ` bg-${bg}` : "") +
    (size !== "regular" ? ` size-${size}` : "") +
    (props.align === "left" ? " align-left" : "") +
    (buttons !== "auto" ? ` btn-${buttons}` : "") +
    (props.shadow ? " shadow" : "") +
    (props.shrink ? " shrink" : "") +
    (open ? " open" : "")
  );
}

export function NavbarView({ props }: ViewProps<NavbarProps>) {
  const menu = parseMenu(props.links);
  const [open, setOpen] = useState(false);
  const hasCta = Boolean(props.ctaText && props.ctaHref);
  return (
    <header className={navClasses(props, open)}>
      <a className="pf-brand" href="/">
        {props.logoUrl ? (
          <img
            src={props.logoUrl}
            alt={props.brand}
            height={LOGO_HEIGHT[props.logoSize ?? "md"]}
          />
        ) : (
          <span>{props.brand}</span>
        )}
      </a>
      {menu.length > 0 && (
        <nav aria-label="Main">
          <ul>
            {menu.map(l =>
              l.children.length > 0 ? (
                <li key={l.href + l.label} className="has-sub">
                  <a href={l.href}>{l.label}</a>
                  <button
                    type="button"
                    className="pf-sub-toggle"
                    aria-label={`${l.label} submenu`}
                    aria-haspopup="true"
                    aria-expanded="false"
                  >
                    <span className="pf-chev" aria-hidden="true"></span>
                  </button>
                  <ul className="pf-sub">
                    {l.children.map(c => (
                      <li key={c.href + c.label}>
                        <a href={c.href}>
                          {c.label}
                          {c.desc && (
                            <span className="pf-sub-desc">{c.desc}</span>
                          )}
                        </a>
                      </li>
                    ))}
                  </ul>
                </li>
              ) : (
                <li key={l.href + l.label}>
                  <a href={l.href}>{l.label}</a>
                </li>
              )
            )}
          </ul>
        </nav>
      )}
      {props.phone && props.phoneHref && (
        <a className="pf-nav-phone" href={props.phoneHref}>
          <Phone size={15} aria-hidden="true" />
          {props.phone}
        </a>
      )}
      {hasCta && (
        <a className="pf-nav-cta" href={props.ctaHref}>
          {props.ctaText}
        </a>
      )}
      {menu.length > 0 && (
        <button
          type="button"
          className="pf-nav-toggle"
          aria-label={open ? "Close menu" : "Open menu"}
          aria-expanded={open ? "true" : "false"}
          data-nav-toggle=""
          onClick={() => setOpen(v => !v)}
        >
          <span className="pf-burger" aria-hidden="true"></span>
        </button>
      )}
      {menu.length > 0 && (
        <div className="pf-nav-panel">
          <ul>
            {menu.map(l => (
              <li
                key={"m" + l.href + l.label}
                className={l.children.length > 0 ? "has-sub" : undefined}
              >
                {l.children.length > 0 ? (
                  <div className="pf-panel-row">
                    <a href={l.href}>{l.label}</a>
                    <button
                      type="button"
                      className="pf-panel-toggle"
                      aria-label={`${l.label} submenu`}
                      aria-expanded="false"
                    >
                      <span className="pf-chev" aria-hidden="true"></span>
                    </button>
                  </div>
                ) : (
                  <a href={l.href}>{l.label}</a>
                )}
                {l.children.length > 0 && (
                  <ul className="pf-panel-sub">
                    {l.children.map(c => (
                      <li key={"m" + c.href + c.label}>
                        <a href={c.href}>{c.label}</a>
                      </li>
                    ))}
                  </ul>
                )}
              </li>
            ))}
          </ul>
          {props.phone && props.phoneHref && (
            <a className="pf-nav-panel-call" href={props.phoneHref}>
              Call {props.phone}
            </a>
          )}
          {hasCta && (
            <a className="pf-nav-panel-cta" href={props.ctaHref}>
              {props.ctaText}
            </a>
          )}
        </div>
      )}
    </header>
  );
}
