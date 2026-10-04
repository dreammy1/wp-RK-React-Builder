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
  rk_invalid_types: "invalid_layout",
  rk_invalid_entry: "invalid_layout",
  rk_invalid_template: "invalid_layout",
  rk_invalid_block: "invalid_layout",
  rk_template_in_use: "conflict",
  rk_invalid_library: "invalid_layout",
  rk_invalid_kit: "invalid_layout",
  rk_library_not_connected: "conflict",
  rk_library_unreachable: "server",
  rk_library_denied: "forbidden",
  rk_library_invalid: "server",
  rk_unsupported: "server",
  rk_nothing_to_undo: "not_found",
  rk_limit: "conflict",
  rk_empty: "conflict",
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
  "rk_template_in_use",
  "rk_invalid_kit",
  "rk_library_not_connected",
  "rk_library_unreachable",
  "rk_library_denied",
  "rk_library_invalid",
  "rk_unsupported",
  "rk_nothing_to_undo",
  "rk_limit",
  "rk_empty",
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

/** The server's own list of what is wrong ("fields.price: Enter a number"), when it sent one; else the usual text. */
export function describeIssues(e: unknown): string {
  if (isApiError(e) && e.extra.issues && e.extra.issues.length > 0)
    return e.extra.issues
      .slice(0, 4)
      .map(i => (i.path ? `${i.path}: ${i.message}` : i.message))
      .join(" · ");
  return describeError(e);
}

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
