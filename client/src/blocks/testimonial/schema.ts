import { z } from "zod";
import { text } from "@/lib/schema/primitives";

export const testimonialProps = z.strictObject({
  quote: text(1, 600),
  author: text(1, 80),
  role: text(0, 120),
});
export type TestimonialProps = z.infer<typeof testimonialProps>;
export const testimonialDefaults: TestimonialProps = {
  quote: "They did exactly what they promised, and the result looks great.",
  author: "Customer name",
  role: "Customer",
};
