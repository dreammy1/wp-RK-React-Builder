import express, { type Express, type Request, type Response } from "express";
import { existsSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { z } from "zod";
import { createAuth, safeEqual } from "./auth";
import { RUM_JS, loadSiteCss } from "./assets";
import { PageCache } from "./cache";
import type { Env } from "./env";
import { log } from "./logger";
import { Metrics } from "./metrics";
import { makeErrorReporter } from "./telemetry";
import { rateLimit, securityHeaders, nonceFor } from "./security";
import { fetchPublicPage, type PublicPage } from "./ssr/data";
import { pagePath, renderPageHtml, renderStatusHtml } from "./ssr/render";
import { createProxy, type WpFetch } from "./wp";

const RESERVED = new Set([
  "builder",
  "api",
  "assets",
  "preview",
  "healthz",
  "metrics",
  "site.css",
  "rum.js",
  "robots.txt",
  "favicon.svg",
  "placeholder.svg",
]);
const SLUG = /^[a-z0-9][a-z0-9-]{0,99}$/;

const Telemetry = z.object({
  type: z.enum(["vitals", "image_error", "js_error", "load_failure"]),
  path: z.string().max(200).optional(),
  detail: z.string().max(240).optional(),
  lcp: z.number().min(0).max(120000).optional(),
  cls: z.number().min(0).max(100).optional(),
});

export type AppDeps = {
  fetch?: WpFetch;
  now?: () => number;
  staticDir?: string;
};

export function createApp(
  env: Env,
  deps: AppDeps = {}
): { app: Express; cache: PageCache<PublicPage>; metrics: Metrics } {
  const fetchImpl = deps.fetch ?? fetch;
  const app = express();
  const metrics = new Metrics();
  const report = makeErrorReporter(env);
  const site = loadSiteCss();
  const cache = new PageCache<PublicPage>(
    env.PUBLIC_CACHE_TTL_SECONDS * 1000,
    undefined,
    deps.now
  );
  const auth = createAuth(env, metrics, deps.now);
  const telemetryLimit = rateLimit(120, 60_000, deps.now);

  app.disable("x-powered-by");
  if (env.TRUST_PROXY) app.set("trust proxy", 1);
  app.use(securityHeaders(env));

  app.get("/healthz", (_req, res) => void res.json({ ok: true }));
  app.get(
    "/robots.txt",
    (_req, res) =>
      void res
        .type("text/plain")
        .send(
          "User-agent: *\nDisallow: /builder\nDisallow: /api\nDisallow: /preview\n"
        )
  );
  app.get(
    "/site.css",
    (_req, res) =>
      void res
        .type("text/css")
        .setHeader("Cache-Control", "public, max-age=31536000, immutable")
        .send(site.css)
  );
  app.get(
    "/rum.js",
    (_req, res) =>
      void res
        .type("application/javascript")
        .setHeader("Cache-Control", "public, max-age=86400")
        .send(RUM_JS)
  );

  app.get("/api/config", (_req, res) => {
    res.setHeader("Cache-Control", "no-store");
    res.json({
      authMode: env.WORDPRESS_AUTH_MODE,
      publicSiteUrl: env.PUBLIC_SITE_URL,
      wpPublicUrl: env.WORDPRESS_PUBLIC_URL,
    });
  });

  if (env.WORDPRESS_AUTH_MODE === "proxy") {
    app.post("/api/auth/login", express.json({ limit: "2kb" }), (req, res) =>
      auth.login(req, res)
    );
    app.get("/api/auth/session", (req, res) => auth.session(req, res));
    app.post("/api/auth/logout", auth.requireSession, (req, res) =>
      auth.logout(req, res)
    );
    app.use(
      "/api/wp",
      createProxy(env, auth, metrics, () => cache.purgeAll(), fetchImpl)
    );
  }

  app.post("/api/revalidate", express.json({ limit: "2kb" }), (req, res) => {
    const given = req.header("x-rk-revalidate-secret") ?? "";
    if (
      !env.REVALIDATE_SECRET ||
      !given ||
      !safeEqual(given, env.REVALIDATE_SECRET)
    ) {
      metrics.inc("rk_revalidate_total", { result: "denied" });
      return void res.status(401).json({ ok: false });
    }
    const slug =
      typeof req.body?.slug === "string" && SLUG.test(req.body.slug)
        ? req.body.slug
        : null;
    if (slug && req.body?.type !== "theme") cache.purgeSlug(slug);
    else cache.purgeAll();
    metrics.inc("rk_revalidate_total", { result: "ok" });
    log("info", "revalidate", { type: String(req.body?.type ?? ""), slug });
    res.json({ ok: true });
  });

  app.post("/api/telemetry", express.json({ limit: "4kb" }), (req, res) => {
    res.status(204).end();
    if (!env.TELEMETRY_ENABLED || !telemetryLimit.take(req.ip ?? "x")) return;
    const t = Telemetry.safeParse(req.body);
    if (!t.success) return;
    metrics.inc("rk_client_events_total", { type: t.data.type });
    if (t.data.type === "vitals") {
      if (t.data.lcp !== undefined)
        metrics.observe("rk_web_vitals_lcp_ms", t.data.lcp);
      if (t.data.cls !== undefined)
        metrics.observe("rk_web_vitals_cls", t.data.cls);
    } else {
      log("warn", `client_${t.data.type}`, {
        path: t.data.path,
        detail: t.data.detail,
      });
    }
  });

  app.get("/metrics", (req, res) => {
    const given = (req.header("authorization") ?? "").replace(/^Bearer /, "");
    if (!env.METRICS_TOKEN || !given || !safeEqual(given, env.METRICS_TOKEN))
      return void res.status(404).end();
    res.type("text/plain").send(metrics.render());
  });

  // ---- Builder SPA (production build only; in dev Vite serves it) ----
  const here = path.dirname(fileURLToPath(import.meta.url));
  const staticDir = deps.staticDir ?? path.resolve(here, "public");
  if (existsSync(path.join(staticDir, "index.html"))) {
    app.use(
      "/assets",
      express.static(path.join(staticDir, "assets"), {
        immutable: true,
        maxAge: "1y",
        fallthrough: false,
      })
    );
    for (const f of ["favicon.svg", "placeholder.svg"])
      app.get(
        `/${f}`,
        (_req, res) => void res.sendFile(path.join(staticDir, f))
      );
    // Stable-named bundle for the WordPress-embedded builder (nonce mode); loaded cross-origin by the WP admin screen.
    app.use(
      "/embed",
      (_req, res, next) => {
        res.setHeader(
          "Access-Control-Allow-Origin",
          new URL(env.WORDPRESS_PUBLIC_URL).origin
        );
        res.setHeader("Cross-Origin-Resource-Policy", "cross-origin");
        res.setHeader("Vary", "Origin");
        next();
      },
      express.static(path.join(staticDir, "embed"), {
        maxAge: "5m",
        fallthrough: false,
      })
    );
    app.get(["/builder", "/builder/*"], (_req, res) => {
      res.setHeader("Cache-Control", "no-store");
      res.setHeader("X-Robots-Tag", "noindex, nofollow");
      res.sendFile(path.join(staticDir, "index.html"));
    });
  }

  // ---- Public server-rendered site ----
  const docOpts = (res: Response) => ({
    env,
    nonce: nonceFor(res),
    cssVersion: site.version,
    telemetry: env.TELEMETRY_ENABLED,
  });

  async function servePage(res: Response, slug: string, previewToken?: string) {
    const started = Date.now();
    let data: PublicPage | null = null;
    let stale = false;
    if (!previewToken) {
      const hit = cache.get(slug);
      if (hit?.fresh) data = hit.value;
      else if (hit) stale = true;
    }
    if (!data) {
      const result = await fetchPublicPage(env, fetchImpl, slug, previewToken);
      if (result.kind === "ok" && previewToken && !result.data.preview) {
        // WordPress ignored the token (invalid or expired): a preview URL must never fall back to public content.
        metrics.inc("rk_public_render_total", { status: 404 });
        return void res
          .status(404)
          .setHeader("Cache-Control", "no-store")
          .send(renderStatusHtml(docOpts(res), 404));
      }
      if (result.kind === "ok") {
        data = result.data;
        stale = false;
        if (!previewToken) cache.set(slug, data, slug);
      } else if (result.kind === "not_found") {
        metrics.inc("rk_public_render_total", { status: 404 });
        return void res
          .status(404)
          .setHeader("Cache-Control", "no-store")
          .send(renderStatusHtml(docOpts(res), 404));
      } else {
        const fallback = !previewToken ? cache.get(slug) : null;
        log("error", "public_fetch_failed", { slug, reason: result.reason });
        if (fallback) {
          data = fallback.value;
          stale = true;
        } else {
          metrics.inc("rk_public_render_total", { status: 503 });
          report(new Error(`Public page unavailable: ${result.reason}`), {
            slug,
          });
          return void res
            .status(503)
            .setHeader("Retry-After", "30")
            .setHeader("Cache-Control", "no-store")
            .send(renderStatusHtml(docOpts(res), 503));
        }
      }
    }
    try {
      const html = renderPageHtml(data, docOpts(res));
      if (data.preview)
        res
          .setHeader("Cache-Control", "no-store")
          .setHeader("X-Robots-Tag", "noindex, nofollow");
      else
        res.setHeader(
          "Cache-Control",
          `public, max-age=0, s-maxage=${env.PUBLIC_CACHE_TTL_SECONDS}, stale-while-revalidate=300`
        );
      if (stale) res.setHeader("X-RK-Stale", "1");
      metrics.inc("rk_public_render_total", { status: 200 });
      metrics.observe("rk_public_render_ms", Date.now() - started);
      res.status(200).type("html").send(html);
    } catch (e) {
      report(e, { slug });
      metrics.inc("rk_public_render_total", { status: 500 });
      res
        .status(503)
        .setHeader("Retry-After", "30")
        .send(renderStatusHtml(docOpts(res), 503));
    }
  }

  app.get("/preview/:slug", (req, res, next) => {
    const token = typeof req.query.token === "string" ? req.query.token : "";
    if (!SLUG.test(req.params.slug!) || !/^[A-Za-z0-9_.-]{20,600}$/.test(token))
      return void res.status(404).send(renderStatusHtml(docOpts(res), 404));
    servePage(res, req.params.slug!, token).catch(next);
  });
  app.get("/", (_req, res, next) => {
    servePage(res, env.PUBLIC_HOME_SLUG).catch(next);
  });
  app.get("/:slug", (req, res, next) => {
    const slug = req.params.slug!;
    if (RESERVED.has(slug) || !SLUG.test(slug))
      return void res
        .status(404)
        .setHeader("Cache-Control", "no-store")
        .send(renderStatusHtml(docOpts(res), 404));
    // The home page has one canonical URL: "/".
    if (slug === env.PUBLIC_HOME_SLUG)
      return void res.redirect(301, pagePath(env, slug));
    servePage(res, slug).catch(next);
  });
  app.use(
    (_req, res) =>
      void res.status(404).send(renderStatusHtml(docOpts(res), 404))
  );

  app.use(
    (
      err: unknown,
      _req: Request,
      res: Response,
      _next: express.NextFunction
    ) => {
      const status = (err as { status?: number })?.status;
      if (typeof status === "number" && status >= 400 && status < 500) {
        if (!res.headersSent)
          res.status(status).json({
            code: status === 404 ? "rk_not_found" : "rk_server_error",
            message: "Request could not be served.",
            data: { status },
          });
        return;
      }
      report(err, { where: "express" });
      if (res.headersSent) return;
      res.status(500).json({
        code: "rk_server_error",
        message: "Internal error.",
        data: { status: 500 },
      });
    }
  );
  return { app, cache, metrics };
}
