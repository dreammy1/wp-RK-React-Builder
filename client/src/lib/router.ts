import { useCallback, useEffect, useState } from "react";
import { getBoot } from "./boot";

export type Route =
  { name: "pages" } | { name: "builder"; pageId: number; demo: boolean };

const parseId = (raw: string | null) =>
  raw && /^\d{1,10}$/.test(raw) && Number(raw) > 0 ? Number(raw) : null;

/**
 * Standalone: `/builder?page=42`.
 * Embedded in wp-admin: `…/admin.php?page=rk-builder&page_id=42` (`rk_page` is accepted as an alias).
 */
export function parseRoute(
  pathname: string,
  search: string,
  embedded = false
): Route {
  const params = new URLSearchParams(search);
  if (embedded) {
    const id = parseId(params.get("page_id") ?? params.get("rk_page"));
    return id
      ? { name: "builder", pageId: id, demo: params.get("demo") === "1" }
      : { name: "pages" };
  }
  const id = parseId(params.get("page"));
  if (pathname.replace(/\/+$/, "") === "/builder" && id) {
    return { name: "builder", pageId: id, demo: params.get("demo") === "1" };
  }
  return { name: "pages" };
}

/** URL for the page list (no id) or one page's editor. */
export function pageHref(id?: number): string {
  const boot = getBoot();
  if (boot) {
    const base = boot.adminUrl ?? `${location.pathname}?page=rk-builder`;
    return id ? `${base}&page_id=${id}` : base;
  }
  return id ? `/builder?page=${id}` : "/builder";
}

export function useRoute() {
  const read = () =>
    parseRoute(location.pathname, location.search, Boolean(getBoot()));
  const [route, setRoute] = useState<Route>(read);
  useEffect(() => {
    const onPop = () => setRoute(read());
    window.addEventListener("popstate", onPop);
    return () => window.removeEventListener("popstate", onPop);
  }, []);
  const navigate = useCallback((to: string) => {
    history.pushState(null, "", to);
    setRoute(read());
  }, []);
  return { route, navigate };
}
