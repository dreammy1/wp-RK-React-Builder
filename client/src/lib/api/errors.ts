import type { Issue } from "@/lib/schema/migrate";

export type ApiErrorKind =
  | "unauthorized"
  | "forbidden"
  | "not_found"
  | "invalid_layout"
  | "invalid_theme"
  | "conflict"
  | "too_large"
  | "server"
  | "network"
  | "invalid_response";

export class ApiError extends Error {
  constructor(
    public kind: ApiErrorKind,
    message: string,
    public status = 0,
    public code = "",
    public extra: { currentRevision?: number; issues?: Issue[] } = {}
  ) {
    super(message);
    this.name = "ApiError";
  }
}

const BY_CODE: Record<string, ApiErrorKind> = {
  rk_unauthorized: "unauthorized",
  rk_forbidden: "forbidden",
  rk_not_found: "not_found",
  rk_invalid_layout: "invalid_layout",
  rk_invalid_theme: "invalid_theme",
  rk_revision_conflict: "conflict",
  rk_preview_invalid: "not_found",
  rk_payload_too_large: "too_large",
  rk_server_error: "server",
  rk_invalid_media: "invalid_layout",
  rk_invalid_bundle: "invalid_layout",
  rk_invalid_reusable: "invalid_layout",
  rk_reusable_in_use: "conflict",
  // WordPress core codes that can surface before the plugin runs
  rest_cookie_invalid_nonce: "unauthorized",
  rest_not_logged_in: "unauthorized",
  incorrect_password: "unauthorized",
};

const PLAIN_MESSAGE_CODES = new Set([
  "rk_invalid_media",
  "rk_invalid_bundle",
  "rk_invalid_reusable",
  "rk_reusable_in_use",
  "rk_payload_too_large",
]);

export function kindFromResponse(status: number, code?: string): ApiErrorKind {
  if (code && BY_CODE[code]) return BY_CODE[code]!;
  if (status === 401) return "unauthorized";
  if (status === 403) return "forbidden";
  if (status === 404) return "not_found";
  if (status === 409) return "conflict";
  if (status === 413) return "too_large";
  return "server";
}

export const isApiError = (e: unknown): e is ApiError => e instanceof ApiError;

/** Human text for banners. Never includes request bodies, tokens or headers. */
export function describeError(e: unknown): string {
  if (!isApiError(e)) return "Something unexpected went wrong.";
  // These codes carry a plain-language, server-authored explanation (limits, file types, what is still in use).
  if (PLAIN_MESSAGE_CODES.has(e.code) && e.message) return e.message;
  switch (e.kind) {
    case "unauthorized":
      return "You need to sign in to WordPress to continue.";
    case "forbidden":
      return "Your account is not allowed to do that.";
    case "not_found":
      return "That page could not be found.";
    case "invalid_layout":
      return "The server rejected this layout as invalid.";
    case "invalid_theme":
      return "The server rejected these theme settings as invalid.";
    case "conflict":
      return "This page was changed by someone else.";
    case "too_large":
      return "This page is too large to save.";
    case "network":
      return "Could not reach the server. Check your connection.";
    case "invalid_response":
      return "The server sent data this editor could not understand.";
    default:
      return "The server hit an error. Try again shortly.";
  }
}
