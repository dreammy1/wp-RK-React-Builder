import { z } from "zod";
import {
  ContentListResponse,
  LoadResponse,
  MediaListResponse,
  MediaUploadResponse,
  ReusableListResponse,
  ReusableResponse,
  type ReusableItem,
  SiteBundle,
  SiteImportReport,
  type SiteImportOptions,
  ThemeAdded,
  ThemeInstallReport,
  ThemeList,
  ThemeSaved,
  type ThemeInstallOptions,
  type ThemeMetaInput,
  PageListResponse,
  PreviewTokenResponse,
  PublishResponse,
  RevisionDetailResponse,
  RevisionListResponse,
  SaveResponse,
  type SaveRequest,
} from "@/lib/schema/api";
import { parseLayout, parseTheme } from "@/lib/schema/migrate";
import type { LayoutDocument } from "@/lib/schema/layout";
import type { ThemeConfig } from "@/lib/schema/theme";
import type { ContentQuery } from "@/render/content";
import { getBoot } from "@/lib/boot";
import { ApiError } from "./errors";
import { getApiConfig, patchApiConfig, request, setApiConfig } from "./http";

export type LoadedPage = {
  page: z.infer<typeof LoadResponse>["page"];
  layout: LayoutDocument;
  theme: ThemeConfig;
  revision: number;
  publishedRevision: number | null;
  updatedAt: string;
  capabilities: { manageTheme: boolean; publish: boolean };
  /** True when stored data was an older shape that the client upgraded in memory. */
  migrated: boolean;
};

function strictLayout(raw: unknown) {
  const r = parseLayout(raw);
  if (!r.ok)
    throw new ApiError(
      "invalid_response",
      `Stored layout is invalid: ${r.issues[0]?.path} ${r.issues[0]?.message}`
    );
  return r;
}
function strictTheme(raw: unknown) {
  const r = parseTheme(raw);
  if (!r.ok)
    throw new ApiError(
      "invalid_response",
      `Stored theme is invalid: ${r.issues[0]?.path} ${r.issues[0]?.message}`
    );
  return r;
}

const Empty = z.object({}).passthrough();
const SessionResponse = z.object({
  authenticated: z.boolean(),
  csrf: z.string().optional(),
});
const ServerConfig = z.object({
  authMode: z.enum(["proxy", "nonce"]),
  publicSiteUrl: z.string(),
  wpPublicUrl: z.string().optional(),
});

export const api = {
  /** Resolve how this deployment authenticates: WP-embedded nonce, or the server proxy session. */
  async bootstrap(): Promise<{ authenticated: boolean }> {
    const boot = getBoot();
    if (boot?.mode === "nonce") {
      setApiConfig({
        mode: "nonce",
        apiBase: boot.apiBase,
        nonce: boot.nonce,
        publicSiteUrl: boot.publicSiteUrl ?? "",
      });
      return { authenticated: true };
    }
    let cfg;
    try {
      const res = await fetch("/api/config", { credentials: "same-origin" });
      cfg = ServerConfig.parse(await res.json());
    } catch {
      throw new ApiError("network", "Could not load builder configuration");
    }
    setApiConfig({
      mode: "proxy",
      apiBase: "/api/wp/rk/v1/",
      publicSiteUrl: cfg.publicSiteUrl,
    });
    const s = await request("/api/auth/session", SessionResponse, {
      absolute: true,
    });
    patchApiConfig({ csrf: s.csrf });
    return { authenticated: s.authenticated };
  },
  async login(password: string) {
    const s = await request("/api/auth/login", SessionResponse, {
      method: "POST",
      body: { password },
      absolute: true,
    });
    patchApiConfig({ csrf: s.csrf });
  },
  async logout() {
    await request("/api/auth/logout", Empty, {
      method: "POST",
      absolute: true,
    });
    patchApiConfig({ csrf: undefined });
  },
  publicSiteUrl: () => getApiConfig().publicSiteUrl,

  listPages: (
    p: { search?: string; status?: string; page?: number } = {},
    signal?: AbortSignal
  ) => {
    const qs = new URLSearchParams();
    if (p.search) qs.set("search", p.search);
    if (p.status) qs.set("status", p.status);
    qs.set("per_page", "50");
    qs.set("page", String(p.page ?? 1));
    return request(`builder/pages?${qs}`, PageListResponse, { signal });
  },

  async loadPage(id: number): Promise<LoadedPage> {
    const res = await request(`builder/layout/${id}`, LoadResponse);
    const layout = strictLayout(res.layout);
    const theme = strictTheme(res.theme);
    return {
      page: res.page,
      layout: layout.value,
      theme: theme.value,
      revision: res.revision,
      publishedRevision: res.publishedRevision ?? null,
      updatedAt: res.updatedAt,
      capabilities: res.capabilities ?? { manageTheme: true, publish: true },
      migrated: layout.migrated || theme.migrated,
    };
  },
  saveLayout: (id: number, body: SaveRequest) =>
    request(`builder/layout/${id}`, SaveResponse, { method: "POST", body }),
  publish: (id: number, expectedRevision: number) =>
    request(`builder/publish/${id}`, PublishResponse, {
      method: "POST",
      body: { expectedRevision },
    }),
  unpublish: (id: number) =>
    request(`builder/unpublish/${id}`, SaveResponse, {
      method: "POST",
      body: {},
    }),
  listRevisions: (id: number) =>
    request(`builder/revisions/${id}`, RevisionListResponse),
  async getRevision(id: number, rev: number) {
    const res = await request(
      `builder/revisions/${id}/${rev}`,
      RevisionDetailResponse
    );
    return { revision: res.revision, layout: strictLayout(res.layout).value };
  },
  restoreRevision: (id: number, rev: number, expectedRevision: number) =>
    request(`builder/revisions/${id}/${rev}/restore`, SaveResponse, {
      method: "POST",
      body: { expectedRevision },
    }),
  previewToken: (id: number) =>
    request(`builder/preview-token/${id}`, PreviewTokenResponse, {
      method: "POST",
      body: {},
    }),
  saveTheme: (theme: ThemeConfig) =>
    request("theme-config", z.object({ ok: z.literal(true) }).passthrough(), {
      method: "POST",
      body: theme,
    }),
  listMedia: (search: string, signal?: AbortSignal) =>
    request(
      `builder/media?${new URLSearchParams({ search, per_page: "24" })}`,
      MediaListResponse,
      { signal }
    ),

  listReusables: () =>
    request("builder/reusables", ReusableListResponse).then(r => r.items),
  createReusable: (name: string, block: ReusableItem["block"]) =>
    request("builder/reusables", ReusableResponse, {
      method: "POST",
      body: { name, block },
    }).then(r => r.item),
  updateReusable: (
    id: number,
    patch: { name?: string; block?: ReusableItem["block"] }
  ) =>
    request(`builder/reusables/${id}`, ReusableResponse, {
      method: "POST",
      body: patch,
    }).then(r => r.item),
  deleteReusable: (id: number) =>
    request(`builder/reusables/${id}/delete`, Empty, {
      method: "POST",
      body: {},
    }),
  /** Upload an image into the WordPress media library. WordPress-hosted editor only. */
  uploadMedia(file: File, alt: string) {
    const form = new FormData();
    form.append("file", file, file.name);
    if (alt.trim()) form.append("alt", alt.trim());
    return request("builder/media", MediaUploadResponse, {
      method: "POST",
      body: form,
      timeoutMs: 120_000,
    }).then(r => r.item);
  },
  /** Media upload and site export/import run through the WordPress REST API (not the headless proxy allow-list). */
  canUploadMedia: () => getBoot()?.mode === "nonce",
  canTransferSite: () =>
    getBoot()?.mode === "nonce" &&
    getBoot()?.currentUser?.capabilities.manageTheme === true,
  exportSite: () =>
    request("builder/site-export", SiteBundle, { timeoutMs: 120_000 }),
  importSite: (bundle: unknown, options: SiteImportOptions) =>
    request("builder/site-import", SiteImportReport, {
      method: "POST",
      body: { bundle, options },
      timeoutMs: 300_000,
    }),

  listThemes: () => request("builder/themes", ThemeList),
  captureTheme: (meta: ThemeMetaInput) =>
    request("builder/themes", ThemeSaved, {
      method: "POST",
      body: meta,
      timeoutMs: 120_000,
    }),
  importTheme: (bundle: unknown) =>
    request("builder/themes/import", ThemeAdded, {
      method: "POST",
      body: { bundle },
      timeoutMs: 120_000,
    }),
  installTheme: (slug: string, options: ThemeInstallOptions) =>
    request("builder/themes/install", ThemeInstallReport, {
      method: "POST",
      body: { slug, options },
      timeoutMs: 300_000,
    }),
  exportTheme: (slug: string) =>
    request(
      `builder/themes/export?slug=${encodeURIComponent(slug)}`,
      SiteBundle,
      { timeoutMs: 120_000 }
    ),
  deleteTheme: (slug: string) =>
    request("builder/themes/delete", z.object({ deleted: z.string() }), {
      method: "POST",
      body: { slug },
    }),

  listContent(q: ContentQuery, signal?: AbortSignal) {
    const qs = new URLSearchParams({
      limit: String(q.limit),
      orderby: q.orderBy,
      order: q.order,
    });
    if (q.category) qs.set("category", q.category);
    return request(`content/${q.source}?${qs}`, ContentListResponse, {
      signal,
    });
  },
};
