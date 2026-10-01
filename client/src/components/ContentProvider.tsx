import { useMemo, useSyncExternalStore, type ReactNode } from "react";
import { ContentStore } from "@/lib/api/contentStore";
import { ContentContext } from "@/render/content";

export function ContentProvider({
  store,
  children,
}: {
  store: ContentStore;
  children: ReactNode;
}) {
  const version = useSyncExternalStore(store.subscribe, store.getVersion);
  // A new context value per store version re-renders consumers when results arrive.
  const value = useMemo(
    () => ({ get: store.get.bind(store), version }),
    [store, version]
  );
  return (
    <ContentContext.Provider value={value}>{children}</ContentContext.Provider>
  );
}
