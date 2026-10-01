import { z } from "zod";

export const spacerProps = z.strictObject({
  h: z.number().int().min(8).max(240),
});
export type SpacerProps = z.infer<typeof spacerProps>;
export const spacerDefaults: SpacerProps = { h: 40 };
