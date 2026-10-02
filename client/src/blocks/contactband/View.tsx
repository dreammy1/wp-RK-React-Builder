import { Mail, Phone } from "lucide-react";
import type { ViewProps } from "@/render/ViewProps";
import { emailHref, phoneHref } from "../contact/schema";
import type { ContactbandProps } from "./schema";

export function ContactbandView({ props }: ViewProps<ContactbandProps>) {
  const tel = phoneHref(props.phone);
  const mail = emailHref(props.email);
  return (
    <section className="pf-band">
      <div className="pf-wrap pf-band-grid">
        <div>
          <h2>{props.heading}</h2>
          {props.sub && <p>{props.sub}</p>}
        </div>
        <div className="pf-band-cards">
          {props.phone && tel && (
            <a className="pf-band-card solid" href={tel}>
              <span>
                <Phone size={16} aria-hidden="true" />
                {props.phone}
              </span>
              <small>Call now</small>
            </a>
          )}
          {props.email && mail && (
            <a className="pf-band-card" href={mail}>
              <span>
                <Mail size={16} aria-hidden="true" />
                Email us
              </span>
              <small>Reply-friendly</small>
            </a>
          )}
        </div>
      </div>
    </section>
  );
}
