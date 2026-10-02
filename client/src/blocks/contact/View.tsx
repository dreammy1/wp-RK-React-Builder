import type { ViewProps } from "@/render/ViewProps";
import { emailHref, phoneHref, type ContactProps } from "./schema";

function Row({
  label,
  value,
  href,
}: {
  label: string;
  value: string;
  href?: string;
}) {
  if (!value) return null;
  return (
    <li>
      <span>{label}</span>
      {href ? <a href={href}>{value}</a> : <strong>{value}</strong>}
    </li>
  );
}

export function ContactView({ props }: ViewProps<ContactProps>) {
  return (
    <section className="site-contact" id="contact">
      <h2>{props.heading}</h2>
      {props.intro && <p>{props.intro}</p>}
      <ul>
        <Row label="Call" value={props.phone} href={phoneHref(props.phone)} />
        <Row label="Email" value={props.email} href={emailHref(props.email)} />
        <Row label="Visit" value={props.address} />
        <Row label="Hours" value={props.hours} />
      </ul>
    </section>
  );
}
