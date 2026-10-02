import { z } from "zod";

/** A page holds only a reference; the block itself lives in the reusable library. */
export const reusableProps = z.strictObject({
  refId: z.number().int().min(1).max(2_147_483_647),
});
export type ReusableProps = z.infer<typeof reusableProps>;
export const reusableDefaults: ReusableProps = { refId: 1 };
