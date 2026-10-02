import { Mail, MapPin, Phone } from "lucide-react";
import type { ViewProps } from "@/render/ViewProps";
import { emailHref, phoneHref } from "../contact/schema";
import { parseLinks } from "../links";
import type { SitefooterProps } from "./schema";

function Column({ title, source }: { title: string; source: string }) {
  const links = parseLinks(source);
  if (links.length === 0) return null;
  return (
    <div className="pf-foot-col">
      {title && <h3>{title}</h3>}
      <ul>
        {links.map(l => (
          <li key={l.href + l.label}>
            <a href={l.href}>{l.label}</a>
          </li>
        ))}
      </ul>
    </div>
  );
}

function Social({ source }: { source: string }) {
  const links = parseLinks(source, 8);
  if (links.length === 0) return null;
  return (
    <ul className="pf-foot-social">
      {links.map(l => (
        <li key={l.href + l.label}>
          <a href={l.href} rel="noopener noreferrer">
            {l.label}
          </a>
        </li>
      ))}
    </ul>
  );
}

export function SitefooterView({ props }: ViewProps<SitefooterProps>) {
  const tel = phoneHref(props.phone);
  const mail = emailHref(props.email);
  const legal = parseLinks(props.legal ?? "", 6);
  return (
    <footer
      className={
        props.tone && props.tone !== "dark"
          ? `pf-foot tone-${props.tone}`
          : "pf-foot"
      }
    >
      <div className="pf-foot-grid">
        <div className="pf-foot-col">
          {props.logoUrl ? (
            <img
              className="pf-foot-logo"
              src={props.logoUrl}
              alt={props.brand}
              height="64"
            />
          ) : (
            <strong className="pf-foot-brand">{props.brand}</strong>
          )}
          {props.tagline && <p>{props.tagline}</p>}
          {props.social && <Social source={props.social} />}
        </div>
        <Column title={props.colATitle} source={props.colALinks} />
        <Column title={props.colBTitle} source={props.colBLinks} />
        {(props.phone || props.email || props.address) && (
          <div className="pf-foot-col">
            {props.contactTitle && <h3>{props.contactTitle}</h3>}
            <ul>
              {props.phone && (
                <li>
                  <Phone size={16} aria-hidden="true" />
                  {tel ? <a href={tel}>{props.phone}</a> : props.phone}
                </li>
              )}
              {props.email && (
                <li>
                  <Mail size={16} aria-hidden="true" />
                  {mail ? <a href={mail}>{props.email}</a> : props.email}
                </li>
              )}
              {props.address && (
                <li>
                  <MapPin size={16} aria-hidden="true" />
                  <span>{props.address}</span>
                </li>
              )}
            </ul>
          </div>
        )}
      </div>
      {(props.copyright || props.note || legal.length > 0) && (
        <div className="pf-foot-base">
          {props.copyright && <p>{props.copyright}</p>}
          {legal.length > 0 && (
            <ul className="pf-foot-legal">
              {legal.map(l => (
                <li key={l.href + l.label}>
                  <a href={l.href}>{l.label}</a>
                </li>
              ))}
            </ul>
          )}
          {props.note && <p>{props.note}</p>}
        </div>
      )}
    </footer>
  );
}
