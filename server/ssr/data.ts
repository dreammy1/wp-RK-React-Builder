import { ContentListResponse, PublicPageResponse } from "@/lib/schema/api";
import { parseLayout, parseTheme } from "@/lib/schema/migrate";
import { registry } from "@/blocks/registry";
import type { ServicesProps } from "@/blocks/services/schema";
import type { LayoutDocument } from "@/lib/schema/layout";
import type { ThemeConfig } from "@/lib/schema/theme";
import { staticReusableSource, type ReusableRecord } from "@/render/reusable";
import {
  contentKey,
  type ContentQuery,
  type ContentResult,
} from "@/render/content";
import type { Env } from "../env";
import { wpUrl, type WpFetch } from "../wp";

export type PublicPage = {
  page: {
    id: number;
    title: string;
    slug: string;
    description: string;
    modified: string;
    image: string | null;
  };
  layout: LayoutDocument;
  theme: ThemeConfig;
  content: Map<string, ContentResult>;
  reusables: ReusableRecord[];
  preview: boolean;
};

export type Fetched =
  | { kind: "ok"; data: PublicPage }
  | { kind: "not_found" }
  | { kind: "unavailable"; reason: string };

const TIMEOUT = 8000;

export function collectQueries(
  layout: LayoutDocument,
  reusables: ReusableRecord[] = []
): Map<string, ContentQuery> {
  const out = new Map<string, ContentQuery>();
  const lookup = staticReusableSource(reusables);
  // A reusable can hold a services/portfolio grid, so look at what each reference resolves to.
  for (const top of layout.blocks) {
    const b =
      top.type === "reusable" ? lookup.get(top.props.refId)?.block : top;
    if (b?.type === "services" || b?.type === "portfolio") {
      const p = b.props as ServicesProps;
      const q: ContentQuery = {
        source: p.source,
        limit: p.limit,
        category: p.category,
        orderBy: p.orderBy,
        order: p.order,
      };
      out.set(contentKey(q), q);
    }
  }
  return out;
}

async function getJson(
  env: Env,
  fetchImpl: WpFetch,
  path: string,
  params: Record<string, string> = {}
) {
  const url = wpUrl(env, path);
  for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
  const res = await fetchImpl(url, {
    headers: { Accept: "application/json" },
    signal: AbortSignal.timeout(TIMEOUT),
    redirect: "error",
  });
  return {
    status: res.status,
    json:
      res.ok || res.status === 404 ? await res.json().catch(() => null) : null,
    ok: res.ok,
  };
}

export async function fetchPublicPage(
  env: Env,
  fetchImpl: WpFetch,
  slug: string,
  previewToken?: string
): Promise<Fetched> {
  let page;
  try {
    page = await getJson(
      env,
      fetchImpl,
      `rk/v1/public/page/${encodeURIComponent(slug)}`,
      previewToken ? { preview: previewToken } : {}
    );
  } catch (e) {
    return {
      kind: "unavailable",
      reason: e instanceof Error ? e.message : "fetch failed",
    };
  }
  if (page.status === 404) return { kind: "not_found" };
  if (!page.ok)
    return {
      kind: "unavailable",
      reason: `WordPress responded ${page.status}`,
    };
  const body = PublicPageResponse.safeParse(page.json);
  if (!body.success)
    return {
      kind: "unavailable",
      reason: "Page response failed contract validation",
    };
  const layout = parseLayout(body.data.layout);
  const theme = parseTheme(body.data.theme);
  if (!layout.ok || !theme.ok)
    return {
      kind: "unavailable",
      reason: "Stored layout or theme failed validation",
    };

  // Resolved library entries that came with the page; anything that no longer matches its block schema is ignored.
  const reusables: ReusableRecord[] = [];
  for (const r of Object.values(body.data.reusables ?? {})) {
    const parsedBlock = registry[r.block.type].schema.safeParse(r.block.props);
    if (parsedBlock.success)
      reusables.push({
        id: r.id,
        name: r.name,
        block: { type: r.block.type, props: parsedBlock.data },
      });
  }
  const queries = collectQueries(layout.value, reusables);
  const content = new Map<string, ContentResult>();
  await Promise.all(
    [...queries].map(async ([key, q]) => {
      try {
        const r = await getJson(env, fetchImpl, `rk/v1/content/${q.source}`, {
          limit: String(q.limit),
          orderby: q.orderBy,
          order: q.order,
          ...(q.category ? { category: q.category } : {}),
        });
        const parsed = ContentListResponse.safeParse(r.json);
        content.set(
          key,
          r.ok && parsed.success
            ? {
                status: "ready",
                items: parsed.data.items,
                total: parsed.data.total,
              }
            : {
                status: "error",
                items: [],
                total: 0,
                error: `content ${r.status}`,
              }
        );
      } catch {
        content.set(key, {
          status: "error",
          items: [],
          total: 0,
          error: "content unreachable",
        });
      }
    })
  );
  return {
    kind: "ok",
    data: {
      page: { ...body.data.page, image: body.data.page.image ?? null },
      layout: layout.value,
      theme: theme.value,
      content,
      reusables,
      preview: body.data.preview === true,
    },
  };
}
