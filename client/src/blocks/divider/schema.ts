import { z } from "zod";

export const dividerProps = z.strictObject({
  style: z.enum(["solid", "dashed"]),
});
export type DividerProps = z.infer<typeof dividerProps>;
export const dividerDefaults: DividerProps = { style: "solid" };
