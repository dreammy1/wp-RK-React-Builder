import { z } from "zod";
import { LayoutSchema } from "./layout";
import { ThemeSchema } from "./theme";

/** Error codes the plugin returns; the client maps them to UI states. */
export const ERROR_CODES = [
  "rk_unauthorized",
  "rk_forbidden",
  "rk_not_found",
  "rk_invalid_layout",
  "rk_invalid_theme",
  "rk_revision_conflict",
  "rk_preview_invalid",
  "rk_payload_too_large",
  "rk_server_error",
  "rk_invalid_media",
  "rk_invalid_bundle",
  "rk_invalid_reusable",
  "rk_reusable_in_use",
] as const;
export type RkErrorCode = (typeof ERROR_CODES)[number];

export const ApiErrorBody = z.object({
  code: z.string(),
  message: z.string(),
  data: z
    .object({
      status: z.number().optional(),
      currentRevision: z.number().optional(),
      issues: z
        .array(z.object({ path: z.string(), message: z.string() }))
        .optional(),
    })
    .passthrough()
    .optional(),
});

export const PageStatus = z.enum([
  "draft",
  "publish",
  "private",
  "pending",
  "future",
]);

export const PageSummary = z.object({
  id: z.number().int(),
  title: z.string(),
  slug: z.string(),
  status: PageStatus,
  modified: z.string(),
  revision: z.number().int(),
  publishedRevision: z.number().int().nullable().optional(),
});
export type PageSummary = z.infer<typeof PageSummary>;
/** A page as the dashboard lists it (the extra fields come from the WordPress-hosted editor). */
export const PageRow = PageSummary.extend({
  link: z.string().optional(),
  isFront: z.boolean().optional(),
  noindex: z.boolean().optional(),
  hasDescription: z.boolean().optional(),
});
export type PageRow = z.infer<typeof PageRow>;
export const PageRowResponse = z.object({ page: PageRow });
export const PageListResponse = z.object({
  pages: z.array(PageRow),
  total: z.number().int(),
});

export const LoadResponse = z.object({
  page: z.object({
    id: z.number().int(),
    title: z.string(),
    slug: z.string(),
    status: PageStatus,
    link: z.string().optional(),
  }),
  layout: z.unknown(), // validated + migrated by parseLayout, never trusted raw
  theme: z.unknown(),
  revision: z.number().int(),
  publishedRevision: z.number().int().nullable().optional(),
  updatedAt: z.string(),
  capabilities: z
    .object({ manageTheme: z.boolean(), publish: z.boolean() })
    .optional(),
});

export const SaveRequest = z.strictObject({
  layout: LayoutSchema,
  theme: ThemeSchema.optional(),
  expectedRevision: z.number().int().min(0),
  status: z.literal("draft"),
});
export type SaveRequest = z.infer<typeof SaveRequest>;

export const SaveResponse = z.object({
  ok: z.literal(true),
  pageId: z.number().int(),
  revision: z.number().int(),
  status: PageStatus,
  updatedAt: z.string(),
});
export type SaveResponse = z.infer<typeof SaveResponse>;

export const PublishResponse = SaveResponse.extend({
  publishedRevision: z.number().int(),
  publishedAt: z.string(),
  link: z.string().optional(),
});
export type PublishResponse = z.infer<typeof PublishResponse>;

export const RevisionSummary = z.object({
  id: z.number().int(),
  kind: z.enum(["draft", "publish", "restore", "unpublish"]),
  savedAt: z.string(),
  author: z.string(),
  blocks: z.number().int(),
});
export type RevisionSummary = z.infer<typeof RevisionSummary>;
export const RevisionListResponse = z.object({
  revisions: z.array(RevisionSummary),
  retained: z.number().int(),
});
export const RevisionDetailResponse = z.object({
  revision: RevisionSummary,
  layout: z.unknown(),
});

export const PreviewTokenResponse = z.object({
  token: z.string(),
  expiresAt: z.string(),
  url: z.string().optional(),
});

export const MediaItem = z.object({
  id: z.number().int(),
  url: z.string(),
  alt: z.string(),
  title: z.string(),
  width: z.number().int().optional(),
  height: z.number().int().optional(),
  srcset: z.string().optional(),
});
export type MediaItem = z.infer<typeof MediaItem>;
export const MediaListResponse = z.object({ items: z.array(MediaItem) });
export const ReusableItem = z.object({
  id: z.number().int(),
  name: z.string(),
  block: z.object({
    type: z.enum([
      "hero",
      "heading",
      "text",
      "image",
      "cta",
      "services",
      "portfolio",
      "spacer",
      "divider",
      "testimonial",
      "contact",
      "navbar",
      "coverhero",
      "sitefooter",
      "section",
      "split",
      "contactband",
      "panel",
      "values",
      "catalog",
      "detail",
      "gallery",
      "calculator",
      "brandstrip",
      "visualizer",
    ]),
    props: z.unknown(),
  }),
});
export type ReusableItem = z.infer<typeof ReusableItem>;
export const ReusableListResponse = z.object({ items: z.array(ReusableItem) });
export const ReusableResponse = z.object({ item: ReusableItem });
export const MediaUploadResponse = z.object({ item: MediaItem });

/** Whole-site export file. Contents are validated by the server on import; the client only checks the envelope. */
export const SiteBundle = z
  .object({ format: z.literal("rk-builder-site"), version: z.literal(1) })
  .passthrough();
export type SiteBundle = z.infer<typeof SiteBundle>;

export type SiteImportOptions = {
  dryRun: boolean;
  theme: boolean;
  content: boolean;
  contentStatus: "draft" | "publish";
};
const Skipped = z.object({ slug: z.string(), issues: z.array(z.string()) });
export const SiteImportReport = z.object({
  dryRun: z.boolean(),
  pages: z.object({
    create: z.number(),
    update: z.number(),
    skipped: z.array(Skipped),
    done: z.array(
      z.object({
        slug: z.string(),
        id: z.number(),
        action: z.enum(["created", "updated"]),
        revision: z.number(),
        link: z.string(),
      })
    ),
  }),
  media: z.object({
    total: z.number(),
    imported: z.number(),
    reused: z.number(),
    failed: z.array(z.object({ url: z.string(), reason: z.string() })),
  }),
  reusables: z
    .object({
      create: z.number(),
      update: z.number(),
      skipped: z.array(Skipped),
    })
    .optional(),
  theme: z.object({ included: z.boolean(), applied: z.boolean() }),
  content: z.object({
    included: z.number(),
    created: z.number(),
    updated: z.number(),
  }),
  warnings: z.array(z.string()),
});
export type SiteImportReport = z.infer<typeof SiteImportReport>;

/** Theme engine: packages kept in this site's library. */
export const ThemeSummary = z.object({
  slug: z.string(),
  name: z.string(),
  description: z.string(),
  version: z.string(),
  author: z.string(),
  pages: z.number(),
  reusables: z.number(),
  media: z.number(),
  content: z.number(),
  preview: z.string(),
  createdAt: z.string(),
  bytes: z.number(),
});
export type ThemeSummary = z.infer<typeof ThemeSummary>;
export const ThemeList = z.object({ items: z.array(ThemeSummary) });
export const ThemeSaved = z.object({ theme: ThemeSummary });
export const ThemeAdded = z.object({
  theme: ThemeSummary,
  check: SiteImportReport,
});
export const ThemeInstallReport = SiteImportReport.extend({
  published: z.number(),
  frontPage: z.boolean(),
});
export type ThemeInstallReport = z.infer<typeof ThemeInstallReport>;
export type ThemeInstallOptions = {
  dryRun: boolean;
  theme: boolean;
  content: boolean;
  publish: boolean;
  frontPage: boolean;
};
export type ThemeMetaInput = {
  name: string;
  description: string;
  version: string;
  author: string;
};

export const ContentItem = z.object({
  id: z.number().int(),
  title: z.string(),
  excerpt: z.string(),
  link: z.string(),
  categories: z.array(z.string()),
  image: z
    .object({
      url: z.string(),
      width: z.number().int(),
      height: z.number().int(),
      alt: z.string(),
      srcset: z.string().optional(),
    })
    .nullable(),
});
export type ContentItem = z.infer<typeof ContentItem>;
export const ContentListResponse = z.object({
  items: z.array(ContentItem),
  total: z.number().int(),
});

export const PublicPageResponse = z.object({
  page: z.object({
    id: z.number().int(),
    title: z.string(),
    slug: z.string(),
    description: z.string(),
    modified: z.string(),
    image: z.string().nullable().optional(),
  }),
  layout: z.unknown(),
  theme: z.unknown(),
  revision: z.number().int(),
  preview: z.boolean().optional(),
  /** Library entries referenced by the layout, keyed by id (stringified). */
  reusables: z.record(z.string(), ReusableItem).optional(),
});

/* ---- Dashboard ---- */
export const Overview = z.object({
  pages: z.object({
    total: z.number(),
    publish: z.number(),
    draft: z.number(),
    other: z.number(),
  }),
  recent: z.array(PageRow),
  attention: z.object({
    unpublishedChanges: z.array(PageRow),
    missingDescription: z.array(PageRow),
  }),
  media: z.number(),
  content: z.object({ services: z.number(), projects: z.number() }),
  themes: z.number(),
  visualizer: z.object({
    enabled: z.boolean(),
    ready: z.boolean(),
    provider: z.string(),
    label: z.string(),
    leads: z.number(),
    lastLead: z.string(),
  }),
  site: z.object({
    name: z.string(),
    tagline: z.string(),
    url: z.string(),
    adminUrl: z.string(),
    frontPageId: z.number(),
    searchVisible: z.boolean(),
    plugin: z.string(),
  }),
});
export type Overview = z.infer<typeof Overview>;

export const PageSeo = z.object({
  seo: z.object({
    title: z.string(),
    description: z.string(),
    image: z.string(),
    noindex: z.boolean(),
    pageTitle: z.string(),
  }),
});
export type PageSeoFields = z.infer<typeof PageSeo>["seo"];

export const SiteSettings = z.object({
  name: z.string(),
  tagline: z.string(),
  searchVisible: z.boolean(),
  frontPageId: z.number(),
  organization: z.object({
    name: z.string(),
    telephone: z.string(),
    email: z.string(),
    description: z.string(),
    logo: z.string(),
  }),
});
export type SiteSettings = z.infer<typeof SiteSettings>;
export const SiteSettingsResponse = z.object({
  site: SiteSettings,
  pages: z.array(z.object({ id: z.number(), title: z.string() })),
});

export const VizLead = z.object({
  name: z.string(),
  email: z.string(),
  phone: z.string(),
  at: z.string(),
});
export const VizSettings = z.object({
  enabled: z.boolean(),
  provider: z.string(),
  gemini_model: z.string(),
  custom_url: z.string(),
  custom_header: z.string(),
  free_count: z.number(),
  bonus_count: z.number(),
  cooldown_hours: z.number(),
  ip_per_hour: z.number(),
  timeout: z.number(),
  notify_email: z.string(),
  hf_token_set: z.boolean(),
  gemini_key_set: z.boolean(),
  custom_key_set: z.boolean(),
  hf_token_env: z.boolean(),
  gemini_key_env: z.boolean(),
});
export type VizSettings = z.infer<typeof VizSettings>;
export const VizAdmin = z.object({
  settings: VizSettings,
  providers: z.record(z.string(), z.string()),
  ready: z.boolean(),
  leads: z.array(VizLead),
});
export type VizAdmin = z.infer<typeof VizAdmin>;
