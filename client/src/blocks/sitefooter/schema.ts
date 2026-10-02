import { z } from "zod";
import { imageUrl, text } from "@/lib/schema/primitives";

export const sitefooterProps = z.strictObject({
  brand: text(1, 80),
  logoMediaId: z.number().int().min(0).max(2_147_483_647).optional(),
  logoUrl: imageUrl.optional(),
  tagline: text(0, 300),
  colATitle: text(0, 60),
  colALinks: text(0, 1500),
  colBTitle: text(0, 60),
  colBLinks: text(0, 1500),
  contactTitle: text(0, 60),
  phone: text(0, 40),
  email: text(0, 120),
  address: text(0, 300),
  copyright: text(0, 200),
  note: text(0, 300),
});
export type SitefooterProps = z.infer<typeof sitefooterProps>;
export const sitefooterDefaults: SitefooterProps = {
  brand: "Your brand",
  tagline: "",
  colATitle: "Services",
  colALinks: "",
  colBTitle: "Explore",
  colBLinks: "",
  contactTitle: "Get in touch",
  phone: "",
  email: "",
  address: "",
  copyright: "",
  note: "",
};
