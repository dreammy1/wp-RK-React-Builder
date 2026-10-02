import express, { type Request, type Response, Router } from "express";
import type { Env } from "./env";
import { log } from "./logger";
import type { Metrics } from "./metrics";
import type { Auth } from "./auth";

export type WpFetch = typeof fetch;

export const wpUrl = (env: Env, path: string) =>
  new URL(path.replace(/^\//, ""), env.WORDPRESS_API_URL.replace(/\/?$/, "/"));

/** Only these REST routes are reachable through the editor proxy. Everything else is refused. */
const ALLOWED: { method: "GET" | "POST"; re: RegExp }[] = [
  { method: "GET", re: /^builder\/pages$/ },
  { method: "GET", re: /^builder\/layout\/\d+$/ },
  { method: "POST", re: /^builder\/layout\/\d+$/ },
  { method: "GET", re: /^builder\/revisions\/\d+$/ },
  { method: "GET", re: /^builder\/revisions\/\d+\/\d+$/ },
  { method: "POST", re: /^builder\/revisions\/\d+\/\d+\/restore$/ },
  { method: "POST", re: /^builder\/publish\/\d+$/ },
  { method: "POST", re: /^builder\/unpublish\/\d+$/ },
  { method: "POST", re: /^builder\/preview-token\/\d+$/ },
  { method: "GET", re: /^builder\/media$/ },
  { method: "GET", re: /^builder\/reusables$/ },
  { method: "POST", re: /^builder\/reusables$/ },
  { method: "POST", re: /^builder\/reusables\/\d+$/ },
  { method: "POST", re: /^builder\/reusables\/\d+\/delete$/ },
  { method: "GET", re: /^theme-config$/ },
  { method: "POST", re: /^theme-config$/ },
  { method: "GET", re: /^content\/(service|portfolio)$/ },
];
const INVALIDATING = [
  /^builder\/reusables\/\d+$/, // a shared block changed: every cached page may differ
  /^builder\/publish\/\d+$/,
  /^builder\/unpublish\/\d+$/,
  /^theme-config$/,
  /^builder\/revisions\/\d+\/\d+\/restore$/,
];

export const MAX_BODY = 300 * 1024;

export function createProxy(
  env: Env,
  auth: Auth,
  metrics: Metrics,
  onInvalidate: () => void,
  fetchImpl: WpFetch = fetch
) {
  const router = Router();
  const basic =
    "Basic " +
    Buffer.from(
      `${env.WORDPRESS_APP_USER}:${env.WORDPRESS_APP_PASSWORD}`
    ).toString("base64");

  router.use(auth.requireSession);
  router.use(express.json({ limit: MAX_BODY }));

  router.all("/rk/v1/*", async (req: Request, res: Response) => {
    const path = (req.params as Record<string, string>)[0] ?? "";
    const method = req.method as "GET" | "POST";
    const route = ALLOWED.find(r => r.method === method && r.re.test(path));
    if (!route)
      return void res.status(404).json({
        code: "rk_not_found",
        message: "Unknown route.",
        data: { status: 404 },
      });

    const url = wpUrl(env, `rk/v1/${path}`);
    for (const [k, v] of Object.entries(req.query))
      if (typeof v === "string") url.searchParams.set(k, v);

    const started = Date.now();
    try {
      const upstream = await fetchImpl(url, {
        method,
        headers: {
          Authorization: basic,
          Accept: "application/json",
          ...(method === "POST" ? { "Content-Type": "application/json" } : {}),
        },
        body: method === "POST" ? JSON.stringify(req.body ?? {}) : undefined,
        signal: AbortSignal.timeout(15_000),
        redirect: "error",
      });
      const text = await upstream.text();
      let code = "";
      try {
        code = (JSON.parse(text) as { code?: string }).code ?? "";
      } catch {
        /* non-JSON */
      }
      const ms = Date.now() - started;
      const routeLabel = path.replace(/\d+/g, ":id");
      metrics.inc("rk_wp_requests_total", {
        route: routeLabel,
        status: upstream.status,
        code: code || "ok",
      });
      metrics.observe("rk_wp_request_ms", ms, { route: routeLabel });
      log(upstream.ok ? "info" : "warn", "wp_proxy", {
        route: routeLabel,
        method,
        status: upstream.status,
        code,
        ms,
      });
      if (upstream.ok && INVALIDATING.some(r => r.test(path))) onInvalidate();
      res
        .status(upstream.status)
        .setHeader("Cache-Control", "no-store")
        .type("application/json")
        .send(text);
    } catch (e) {
      metrics.inc("rk_wp_requests_total", {
        route: path.replace(/\d+/g, ":id"),
        status: 504,
        code: "unreachable",
      });
      log("error", "wp_proxy_unreachable", {
        route: path.replace(/\d+/g, ":id"),
        message: e instanceof Error ? e.message : "unknown",
      });
      res.status(504).json({
        code: "rk_server_error",
        message: "WordPress could not be reached.",
        data: { status: 504 },
      });
    }
  });
  router.use(
    (_req, res) =>
      void res.status(404).json({
        code: "rk_not_found",
        message: "Unknown route.",
        data: { status: 404 },
      })
  );

  // Body parser errors (oversized / malformed JSON) → stable error codes.
  router.use(
    (
      err: { type?: string },
      _req: Request,
      res: Response,
      next: express.NextFunction
    ) => {
      if (err?.type === "entity.too.large")
        return void res.status(413).json({
          code: "rk_payload_too_large",
          message: "Payload too large.",
          data: { status: 413 },
        });
      if (err?.type === "entity.parse.failed")
        return void res.status(400).json({
          code: "rk_invalid_layout",
          message: "Malformed JSON.",
          data: { status: 400 },
        });
      next(err);
    }
  );
  return router;
}
