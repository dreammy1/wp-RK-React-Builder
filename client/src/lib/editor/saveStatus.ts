export type SaveStatus =
  | "clean"
  | "dirty"
  | "saving"
  | "saved"
  | "saved-local"
  | "auth"
  | "invalid"
  | "conflict"
  | "network"
  | "forbidden"
  | "error";

export const STATUS_LABEL: Record<SaveStatus, string> = {
  clean: "No changes",
  dirty: "Unsaved changes",
  saving: "Saving…",
  saved: "Draft saved to WordPress",
  "saved-local": "Saved on this device only — not yet on WordPress",
  auth: "Sign-in required — changes kept on this device",
  invalid: "Validation failed — fix the highlighted fields",
  conflict: "Conflict — page changed by someone else",
  network: "Network error — changes kept on this device",
  forbidden: "Not permitted — ask an administrator",
  error: "Save failed — try again",
};

export const STATUS_TONE: Record<SaveStatus, "ok" | "warn" | "bad" | "info"> = {
  clean: "ok",
  dirty: "info",
  saving: "info",
  saved: "ok",
  "saved-local": "warn",
  auth: "bad",
  invalid: "bad",
  conflict: "bad",
  network: "warn",
  forbidden: "bad",
  error: "bad",
};
