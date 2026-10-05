import { createContext, useContext } from "react";
import type { ContentItem } from "@/lib/schema/api";

export type ContentQuery = {
  source: "service" | "portfolio" | "media";
  limit: number;
  category: string;
  orderBy: "date" | "title" | "menu_order";
  order: "asc" | "desc";
};

export type ContentResult = {
  status: "loading" | "ready" | "error";
  items: ContentItem[];
  total: number;
  error?: string;
};

export const contentKey = (q: ContentQuery) =>
  [q.source, q.limit, q.category, q.orderBy, q.order].join("|");

export type ContentSource = { get(query: ContentQuery): ContentResult };

const EMPTY: ContentResult = { status: "ready", items: [], total: 0 };
export const ContentContext = createContext<ContentSource>({
  get: () => EMPTY,
});
export const useContent = (q: ContentQuery) =>
  useContext(ContentContext).get(q);

/** Fixed, preloaded results — used by the server renderer and tests. */
export function staticContentSource(
  results: Map<string, ContentResult>
): ContentSource {
  return {
    get: q =>
      results.get(contentKey(q)) ?? {
        status: "error",
        items: [],
        total: 0,
        error: "Content unavailable",
      },
  };
}
