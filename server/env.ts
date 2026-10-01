import { z } from "zod";

const url = z.string().url();
const optionalUrl = z.preprocess(
  v => (v === "" ? undefined : v),
  url.optional()
);
const bool = z.preprocess(
  v =>
    typeof v === "string" ? ["1", "true", "yes"].includes(v.toLowerCase()) : v,
  z.boolean()
);

const EnvSchema = z
  .object({
    NODE_ENV: z
      .enum(["development", "production", "test"])
      .default("development"),
    PORT: z.coerce.number().int().min(0).max(65535).optional(),
    WORDPRESS_PUBLIC_URL: url,
    WORDPRESS_API_URL: url,
    WORDPRESS_FRONTEND_ORIGIN: optionalUrl,
    WORDPRESS_AUTH_MODE: z.enum(["proxy", "nonce"]).default("proxy"),
    WORDPRESS_APP_USER: z.string().optional(),
    WORDPRESS_APP_PASSWORD: z.string().optional(),
    BUILDER_EDITOR_PASSWORD: z.string().optional(),
    PUBLIC_SITE_URL: url,
    PUBLIC_HOME_SLUG: z.string().default("home"),
    SITE_NAME: z.string().default("RK"),
    PUBLIC_CACHE_TTL_SECONDS: z.coerce
      .number()
      .int()
      .min(0)
      .max(86400)
      .default(60),
    REVALIDATE_SECRET: z.string().optional(),
    METRICS_TOKEN: z.string().optional(),
    SENTRY_DSN: optionalUrl,
    ANALYTICS_ENDPOINT: optionalUrl,
    ANALYTICS_WEBSITE_ID: z.string().optional(),
    TELEMETRY_ENABLED: bool.default(true),
    TRUST_PROXY: bool.default(false),
    LOGIN_RATE_LIMIT_MAX: z.coerce.number().int().min(1).max(10000).default(5),
  })
  .superRefine((e, ctx) => {
    const need = (key: keyof typeof e, why: string) => {
      const v = e[key];
      if (v === undefined || v === "")
        ctx.addIssue({
          code: "custom",
          path: [key],
          message: `${key} is required ${why}`,
        });
    };
    if (e.WORDPRESS_AUTH_MODE === "proxy") {
      need("WORDPRESS_APP_USER", "when WORDPRESS_AUTH_MODE=proxy");
      need("WORDPRESS_APP_PASSWORD", "when WORDPRESS_AUTH_MODE=proxy");
      need("BUILDER_EDITOR_PASSWORD", "when WORDPRESS_AUTH_MODE=proxy");
      if (
        e.NODE_ENV === "production" &&
        (e.BUILDER_EDITOR_PASSWORD ?? "").length < 12
      ) {
        ctx.addIssue({
          code: "custom",
          path: ["BUILDER_EDITOR_PASSWORD"],
          message:
            "BUILDER_EDITOR_PASSWORD must be at least 12 characters in production",
        });
      }
    }
    if (e.NODE_ENV === "production") {
      need(
        "REVALIDATE_SECRET",
        "in production (WordPress calls /api/revalidate with it)"
      );
      if (
        e.PUBLIC_SITE_URL.startsWith("http://") &&
        !/localhost|127\.0\.0\.1/.test(e.PUBLIC_SITE_URL)
      ) {
        ctx.addIssue({
          code: "custom",
          path: ["PUBLIC_SITE_URL"],
          message: "PUBLIC_SITE_URL must be https in production",
        });
      }
    }
    if (
      (e.ANALYTICS_ENDPOINT === undefined) !==
      (e.ANALYTICS_WEBSITE_ID === undefined || e.ANALYTICS_WEBSITE_ID === "")
    ) {
      ctx.addIssue({
        code: "custom",
        path: ["ANALYTICS_ENDPOINT"],
        message:
          "ANALYTICS_ENDPOINT and ANALYTICS_WEBSITE_ID must be set together (or both omitted)",
      });
    }
  });

export type Env = z.infer<typeof EnvSchema>;

/** Fails fast with every problem listed; never prints values (they may be secrets). */
export function loadEnv(
  source: Record<string, string | undefined> = process.env
): Env {
  const parsed = EnvSchema.safeParse(source);
  if (!parsed.success) {
    const lines = parsed.error.issues.map(
      i => `  - ${i.path.join(".") || "env"}: ${i.message}`
    );
    throw new Error(
      `Invalid environment configuration:\n${lines.join("\n")}\nSee .env.example and DEPLOYMENT.md.`
    );
  }
  return parsed.data;
}
