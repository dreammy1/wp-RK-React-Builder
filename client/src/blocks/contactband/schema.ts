import { z } from "zod";
import { text } from "@/lib/schema/primitives";

export const contactbandProps = z.strictObject({
  heading: text(1, 160),
  sub: text(0, 400),
  phone: text(0, 40),
  email: text(0, 120),
});
export type ContactbandProps = z.infer<typeof contactbandProps>;
export const contactbandDefaults: ContactbandProps = {
  heading: "Ready to talk about your floors?",
  sub: "",
  phone: "",
  email: "",
};
