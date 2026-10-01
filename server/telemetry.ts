import { log } from "./logger";
import type { Env } from "./env";

/** Minimal Sentry-compatible error forwarder (envelope API). No SDK, no request bodies, no credentials. */
export function makeErrorReporter(env: Env, fetchImpl: typeof fetch = fetch) {
  const dsn = env.SENTRY_DSN ? new URL(env.SENTRY_DSN) : null;
  return function report(err: unknown, context: Record<string, unknown> = {}) {
    const e = err instanceof Error ? err : new Error(String(err));
    log("error", "exception", {
      name: e.name,
      message: e.message,
      stack: e.stack?.split("\n").slice(0, 6),
      ...context,
    });
    if (!dsn) return;
    const projectId = dsn.pathname.replace(/^\//, "");
    const key = dsn.username;
    const event = {
      event_id: crypto.randomUUID().replace(/-/g, ""),
      timestamp: Date.now() / 1000,
      platform: "node",
      level: "error",
      environment: env.NODE_ENV,
      exception: { values: [{ type: e.name, value: e.message }] },
      tags: Object.fromEntries(
        Object.entries(context).filter(([, v]) => typeof v === "string")
      ),
    };
    const body = [
      JSON.stringify({
        event_id: event.event_id,
        sent_at: new Date().toISOString(),
      }),
      JSON.stringify({ type: "event" }),
      JSON.stringify(event),
    ].join("\n");
    void fetchImpl(
      `${dsn.protocol}//${dsn.host}/api/${projectId}/envelope/?sentry_key=${key}&sentry_version=7`,
      {
        method: "POST",
        body,
        headers: { "Content-Type": "application/x-sentry-envelope" },
        signal: AbortSignal.timeout(3000),
      }
    ).catch(() => {
      /* never let reporting break a request */
    });
  };
}
export type ErrorReporter = ReturnType<typeof makeErrorReporter>;
