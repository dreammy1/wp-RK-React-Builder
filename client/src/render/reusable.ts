import { createContext, useContext } from "react";
import type { Block } from "@/lib/schema/layout";

/** A library entry: one content block (never another reusable) with a name. */
export type ReusableRecord = {
  id: number;
  name: string;
  block: { type: Exclude<Block["type"], "reusable">; props: unknown };
};

export type ReusableSource = { get(id: number): ReusableRecord | undefined };

export const ReusableContext = createContext<ReusableSource>({
  get: () => undefined,
});
export const useReusable = (id: number) => useContext(ReusableContext).get(id);

/** Fixed lookup for the server renderer and tests. */
export function staticReusableSource(
  items: Iterable<ReusableRecord>
): ReusableSource {
  const map = new Map<number, ReusableRecord>();
  for (const r of items) map.set(r.id, r);
  return { get: id => map.get(id) };
}
