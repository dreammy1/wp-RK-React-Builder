import type { Request, RequestHandler, Response } from "express";
import { createHash, randomBytes, timingSafeEqual } from "node:crypto";
import type { Env } from "./env";
import { log } from "./logger";
import type { Metrics } from "./metrics";
import { rateLimit } from "./security";

const COOKIE = "rk_sid";
const SESSION_MS = 8 * 60 * 60 * 1000;

type Session = { csrf: string; expires: number };

const digest = (s: string) => createHash("sha256").update(s).digest();
export const safeEqual = (a: string, b: string) =>
  timingSafeEqual(digest(a), digest(b));

function readCookie(req: Request, name: string): string | undefined {
  const header = req.headers.cookie;
  if (!header) return undefined;
  for (const part of header.split(";")) {
    const [k, ...v] = part.trim().split("=");
    if (k === name) return decodeURIComponent(v.join("="));
  }
  return undefined;
}

export function createAuth(
  env: Env,
  metrics: Metrics,
  now: () => number = Date.now
) {
  const sessions = new Map<string, Session>();
  const loginLimit = rateLimit(env.LOGIN_RATE_LIMIT_MAX, 15 * 60 * 1000, now);
  const secure = env.PUBLIC_SITE_URL.startsWith("https://");
  const sweep = setInterval(() => {
    for (const [id, s] of sessions) if (s.expires <= now()) sessions.delete(id);
  }, 60_000);
  sweep.unref();

  const current = (req: Request): { id: string; session: Session } | null => {
    const id = readCookie(req, COOKIE);
    const session = id ? sessions.get(id) : undefined;
    if (!id || !session) return null;
    if (session.expires <= now()) {
      sessions.delete(id);
      return null;
    }
    return { id, session };
  };

  const setCookie = (res: Response, id: string, maxAgeS: number) =>
    res.append(
      "Set-Cookie",
      `${COOKIE}=${encodeURIComponent(id)}; HttpOnly; SameSite=Strict; Path=/; Max-Age=${maxAgeS}${secure ? "; Secure" : ""}`
    );

  /** Requires a valid session; mutating requests additionally need the CSRF header and a same-site Origin. */
  const requireSession: RequestHandler = (req, res, next) => {
    const cur = current(req);
    if (!cur) {
      metrics.inc("rk_auth_failures_total", { reason: "no_session" });
      return void res.status(401).json({
        code: "rk_unauthorized",
        message: "Sign in required.",
        data: { status: 401 },
      });
    }
    if (!["GET", "HEAD", "OPTIONS"].includes(req.method)) {
      const given = req.header("x-rk-csrf") ?? "";
      const origin = req.header("origin");
      const allowed = new Set([
        new URL(env.PUBLIC_SITE_URL).origin,
        ...(env.WORDPRESS_FRONTEND_ORIGIN
          ? [new URL(env.WORDPRESS_FRONTEND_ORIGIN).origin]
          : []),
      ]);
      const sameSite =
        !origin ||
        allowed.has(origin) ||
        origin === `${req.protocol}://${req.get("host")}`;
      if (!given || !safeEqual(given, cur.session.csrf) || !sameSite) {
        metrics.inc("rk_auth_failures_total", { reason: "csrf" });
        return void res.status(403).json({
          code: "rk_forbidden",
          message: "Request blocked (CSRF check failed).",
          data: { status: 403 },
        });
      }
    }
    next();
  };

  const handlers = {
    session(req: Request, res: Response) {
      const cur = current(req);
      res.setHeader("Cache-Control", "no-store");
      res.json(
        cur
          ? { authenticated: true, csrf: cur.session.csrf }
          : { authenticated: false }
      );
    },
    login(req: Request, res: Response) {
      const key = req.ip ?? "unknown";
      res.setHeader("Cache-Control", "no-store");
      if (!loginLimit.take(key)) {
        metrics.inc("rk_auth_failures_total", { reason: "rate_limited" });
        return void res.status(429).json({
          code: "rk_forbidden",
          message: "Too many attempts. Try again later.",
          data: { status: 429 },
        });
      }
      const password =
        typeof req.body?.password === "string" ? req.body.password : "";
      if (
        !password ||
        !safeEqual(password, env.BUILDER_EDITOR_PASSWORD ?? "")
      ) {
        metrics.inc("rk_auth_failures_total", { reason: "bad_password" });
        log("warn", "login_failed", { ip: key });
        return void res.status(401).json({
          code: "rk_unauthorized",
          message: "Incorrect password.",
          data: { status: 401 },
        });
      }
      const id = randomBytes(32).toString("hex");
      const csrf = randomBytes(24).toString("hex");
      sessions.set(id, { csrf, expires: now() + SESSION_MS });
      setCookie(res, id, SESSION_MS / 1000);
      log("info", "login_ok", { ip: key });
      res.json({ authenticated: true, csrf });
    },
    logout(req: Request, res: Response) {
      const cur = current(req);
      if (cur) sessions.delete(cur.id);
      setCookie(res, "", 0);
      res.json({ ok: true });
    },
  };
  return { requireSession, ...handlers, sessions };
}
export type Auth = ReturnType<typeof createAuth>;
