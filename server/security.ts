import type { NextFunction, Request, RequestHandler, Response } from "express";
import { randomBytes } from "node:crypto";
import type { Env } from "./env";

export const nonceFor = (res: Response): string =>
  res.locals.cspNonce as string;

export function securityHeaders(env: Env): RequestHandler {
  const wpOrigin = new URL(env.WORDPRESS_PUBLIC_URL).origin;
  const analytics = env.ANALYTICS_ENDPOINT
    ? new URL(env.ANALYTICS_ENDPOINT).origin
    : "";
  const https = env.PUBLIC_SITE_URL.startsWith("https://");
  return (req: Request, res: Response, next: NextFunction) => {
    const nonce = randomBytes(16).toString("base64");
    res.locals.cspNonce = nonce;
    const isBuilder =
      req.path === "/builder" ||
      req.path.startsWith("/builder/") ||
      req.path.startsWith("/assets/");
    const csp = isBuilder
      ? [
          "default-src 'none'",
          "script-src 'self'",
          "style-src 'self' https://fonts.googleapis.com",
          "style-src-attr 'unsafe-inline'",
          "font-src https://fonts.gstatic.com",
          `img-src 'self' data: ${wpOrigin} https:`,
          "connect-src 'self'",
          "base-uri 'none'",
          "form-action 'self'",
          "frame-ancestors 'none'",
        ]
      : [
          "default-src 'none'",
          `script-src 'self'${analytics ? ` ${analytics}` : ""}`,
          `style-src 'self' 'nonce-${nonce}' https://fonts.googleapis.com`,
          "style-src-attr 'unsafe-inline'",
          "font-src https://fonts.gstatic.com",
          `img-src 'self' data: ${wpOrigin} https:`,
          `connect-src 'self'${analytics ? ` ${analytics}` : ""}`,
          "base-uri 'none'",
          "form-action 'none'",
          "frame-ancestors 'none'",
        ];
    res.setHeader("Content-Security-Policy", csp.join("; "));
    res.setHeader("X-Content-Type-Options", "nosniff");
    res.setHeader("Referrer-Policy", "strict-origin-when-cross-origin");
    res.setHeader(
      "Permissions-Policy",
      "camera=(), microphone=(), geolocation=()"
    );
    res.setHeader("X-Frame-Options", "DENY");
    res.removeHeader("X-Powered-By");
    if (https)
      res.setHeader(
        "Strict-Transport-Security",
        "max-age=31536000; includeSubDomains"
      );
    next();
  };
}

/** Fixed-window limiter keyed by caller; plenty for login and telemetry endpoints. */
export function rateLimit(
  max: number,
  windowMs: number,
  now: () => number = Date.now
) {
  const hits = new Map<string, { n: number; reset: number }>();
  return {
    take(key: string): boolean {
      const t = now();
      const cur = hits.get(key);
      if (!cur || cur.reset <= t) {
        hits.set(key, { n: 1, reset: t + windowMs });
        return true;
      }
      cur.n++;
      return cur.n <= max;
    },
    clear: () => hits.clear(),
  };
}
