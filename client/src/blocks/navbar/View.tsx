import { useState } from "react";
import { Phone } from "lucide-react";
import type { ViewProps } from "@/render/ViewProps";
import { parseLinks } from "../links";
import type { NavbarProps } from "./schema";

export function NavbarView({ props }: ViewProps<NavbarProps>) {
  const links = parseLinks(props.links);
  const [open, setOpen] = useState(false);
  const cls =
    (props.overlay ? "pf-nav overlay" : "pf-nav") + (open ? " open" : "");
  return (
    <header className={cls}>
      <a className="pf-brand" href="/">
        {props.logoUrl ? (
          <img src={props.logoUrl} alt={props.brand} height="64" />
        ) : (
          <span>{props.brand}</span>
        )}
      </a>
      {links.length > 0 && (
        <nav aria-label="Main">
          <ul>
            {links.map(l => (
              <li key={l.href + l.label}>
                <a href={l.href}>{l.label}</a>
              </li>
            ))}
          </ul>
        </nav>
      )}
      {props.phone && props.phoneHref && (
        <a className="pf-nav-phone" href={props.phoneHref}>
          <Phone size={15} aria-hidden="true" />
          {props.phone}
        </a>
      )}
      {links.length > 0 && (
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
      {links.length > 0 && (
        <div className="pf-nav-panel">
          <ul>
            {links.map(l => (
              <li key={"m" + l.href + l.label}>
                <a href={l.href}>{l.label}</a>
              </li>
            ))}
          </ul>
          {props.phone && props.phoneHref && (
            <a className="pf-nav-panel-call" href={props.phoneHref}>
              Call {props.phone}
            </a>
          )}
        </div>
      )}
    </header>
  );
}
