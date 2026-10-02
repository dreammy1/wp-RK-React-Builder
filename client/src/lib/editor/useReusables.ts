import { useCallback, useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import type { ReusableItem } from "@/lib/schema/api";
import type { ReusableSource } from "@/render/reusable";

export type ReusableLibrary = {
  status: "loading" | "ready" | "error";
  items: ReusableItem[];
  error?: string;
  source: ReusableSource;
  create(name: string, block: ReusableItem["block"]): Promise<ReusableItem>;
  update(
    id: number,
    patch: { name?: string; block?: ReusableItem["block"] }
  ): Promise<ReusableItem>;
  reload(): void;
};

const byName = (a: ReusableItem, b: ReusableItem) =>
  a.name.localeCompare(b.name);

/** The shared block library, loaded once per editor session. Failure leaves the editor usable (blocks show as unavailable). */
export function useReusables(): ReusableLibrary {
  const [state, setState] = useState<{
    status: "loading" | "ready" | "error";
    items: ReusableItem[];
    error?: string;
  }>({ status: "loading", items: [] });
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let live = true;
    api
      .listReusables()
      .then(items => live && setState({ status: "ready", items }))
      .catch(
        e =>
          live &&
          setState({ status: "error", items: [], error: describeError(e) })
      );
    return () => {
      live = false;
    };
  }, [attempt]);

  const create = useCallback(
    async (name: string, block: ReusableItem["block"]) => {
      const item = await api.createReusable(name, block);
      setState(s => ({
        ...s,
        status: "ready",
        items: [...s.items, item].sort(byName),
      }));
      return item;
    },
    []
  );
  const update = useCallback(
    async (
      id: number,
      patch: { name?: string; block?: ReusableItem["block"] }
    ) => {
      const item = await api.updateReusable(id, patch);
      setState(s => ({
        ...s,
        items: s.items.map(i => (i.id === id ? item : i)).sort(byName),
      }));
      return item;
    },
    []
  );
  const source = useMemo<ReusableSource>(() => {
    const map = new Map(state.items.map(i => [i.id, i]));
    return { get: id => map.get(id) };
  }, [state.items]);

  return {
    ...state,
    source,
    create,
    update,
    reload: () => {
      setState(s => ({ ...s, status: "loading" }));
      setAttempt(a => a + 1);
    },
  };
}
