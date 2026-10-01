import type { z } from "zod";
import { ApiErrorBody } from "@/lib/schema/api";
import { ApiError, kindFromResponse } from "./errors";

export type ApiConfig = {
  mode: "proxy" | "nonce";
  /** Absolute or origin-relative URL ending in "/" that maps to wp-json/rk/v1/. */
  apiBase: string;
  /** X-WP-Nonce — nonce mode only. Held in memory, never persisted. */
  nonce?: string;
  /** CSRF token — proxy mode only. Held in memory, never persisted. */
  csrf?: string;
  publicSiteUrl: string;
};

let current: ApiConfig | null = null;
export const setApiConfig = (c: ApiConfig) => {
  current = c;
};
export const patchApiConfig = (p: Partial<ApiConfig>) => {
  if (current) current = { ...current, ...p };
};
export const getApiConfig = (): ApiConfig => {
  if (!current) throw new ApiError("server", "API is not configured");
  return current;
};

const TIMEOUT_MS = 20_000;

type Init = {
  method?: "GET" | "POST";
  body?: unknown;
  signal?: AbortSignal;
  absolute?: boolean;
};

export async function request<S extends z.ZodTypeAny>(
  path: string,
  schema: S,
  init: Init = {}
): Promise<z.infer<S>> {
  const cfg = getApiConfig();
  const method = init.method ?? "GET";
  const headers: Record<string, string> = { Accept: "application/json" };
  if (method !== "GET") headers["Content-Type"] = "application/json";
  if (cfg.mode === "nonce" && cfg.nonce) headers["X-WP-Nonce"] = cfg.nonce;
  if (cfg.mode === "proxy" && cfg.csrf && method !== "GET")
    headers["X-RK-CSRF"] = cfg.csrf;

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
  init.signal?.addEventListener("abort", () => controller.abort());
  let res: Response;
  try {
    res = await fetch(init.absolute ? path : cfg.apiBase + path, {
      method,
      headers,
      body: init.body === undefined ? undefined : JSON.stringify(init.body),
      credentials: cfg.mode === "nonce" ? "include" : "same-origin",
      signal: controller.signal,
    });
  } catch {
    throw new ApiError("network", "Network request failed");
  } finally {
    clearTimeout(timer);
  }

  let json: unknown = null;
  try {
    json = await res.json();
  } catch {
    /* non-JSON body handled below */
  }

  if (!res.ok) {
    const body = ApiErrorBody.safeParse(json);
    const code = body.success ? body.data.code : "";
    const data = body.success ? body.data.data : undefined;
    throw new ApiError(
      kindFromResponse(res.status, code),
      body.success ? body.data.message : `Request failed (${res.status})`,
      res.status,
      code,
      { currentRevision: data?.currentRevision, issues: data?.issues }
    );
  }
  const parsed = schema.safeParse(json);
  if (!parsed.success) {
    throw new ApiError(
      "invalid_response",
      "Response did not match the expected contract",
      res.status
    );
  }
  return parsed.data;
}
