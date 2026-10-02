import { z } from "zod";
import { text } from "@/lib/schema/primitives";

export const contactProps = z.strictObject({
  heading: text(1, 160),
  intro: text(0, 400),
  phone: text(0, 40),
  email: text(0, 120),
  address: text(0, 200),
  hours: text(0, 200),
});
export type ContactProps = z.infer<typeof contactProps>;
export const contactDefaults: ContactProps = {
  heading: "Get in touch",
  intro: "Call or email us for a free estimate.",
  phone: "",
  email: "",
  address: "",
  hours: "",
};

/** Digits and "+" only, so a typed number can never inject anything into a tel: link. */
export const phoneHref = (phone: string): string => {
  const d = phone.replace(/[^0-9+]/g, "");
  return d.replace(/\+/g, "").length >= 3 ? `tel:${d}` : "";
};
const EMAIL = /^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/;
export const emailHref = (email: string): string =>
  EMAIL.test(email) ? `mailto:${email}` : "";
