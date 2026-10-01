import { LayoutSchema, type LayoutDocument } from "./layout";
import { ThemeSchema, DEFAULT_THEME, type ThemeConfig } from "./theme";
import { LIMITS } from "./primitives";

export type Issue = { path: string; message: string };
export type ParseResult<T> =
  { ok: true; value: T; migrated: boolean } | { ok: false; issues: Issue[] };

const isObj = (v: unknown): v is Record<string, unknown> =>
  typeof v === "object" && v !== null && !Array.isArray(v);

/** Version-0 documents are the prototype's flat blocks: `{ id, type, heading, ... }`. */
function migrateLegacyBlock(raw: unknown): unknown {
  if (!isObj(raw)) return raw;
  if (isObj(raw.props)) return raw;
  const { id, type, ...props } = raw;
  if ((type === "services" || type === "portfolio") && "source" in props) {
    props.source = type === "services" ? "service" : "portfolio";
  }
  return { id, type, props };
}

/** Upgrade any known historical layout shape to the current version. */
export function migrateLayoutInput(input: unknown): {
  value: unknown;
  migrated: boolean;
} {
  if (!isObj(input)) return { value: input, migrated: false };
  const version = input.version;
  if (version === LIMITS.schemaVersion) {
    const blocks = Array.isArray(input.blocks) ? input.blocks : null;
    if (blocks?.some(b => isObj(b) && !isObj(b.props))) {
      return {
        value: { ...input, blocks: blocks.map(migrateLegacyBlock) },
        migrated: true,
      };
    }
    return { value: input, migrated: false };
  }
  if ((version === undefined || version === 0) && Array.isArray(input.blocks)) {
    return {
      value: { version: 1, blocks: input.blocks.map(migrateLegacyBlock) },
      migrated: true,
    };
  }
  return { value: input, migrated: false };
}

function toIssues(error: {
  issues: ReadonlyArray<{ path: PropertyKey[]; message: string }>;
}): Issue[] {
  return error.issues.map(i => ({
    path: i.path.map(String).join("."),
    message: i.message,
  }));
}

export function parseLayout(input: unknown): ParseResult<LayoutDocument> {
  const { value, migrated } = migrateLayoutInput(input);
  const result = LayoutSchema.safeParse(value);
  if (!result.success) return { ok: false, issues: toIssues(result.error) };
  return { ok: true, value: result.data, migrated };
}

export function migrateThemeInput(input: unknown): {
  value: unknown;
  migrated: boolean;
} {
  if (!isObj(input)) return { value: input, migrated: false };
  if (input.version === LIMITS.schemaVersion)
    return { value: input, migrated: false };
  if (input.version === undefined) {
    // v0: `logo` was a bare URL string, social was an arbitrary map.
    const { logo, ...rest } = input;
    const next: Record<string, unknown> = { ...rest, version: 1 };
    if (typeof logo === "string" && logo) next.logoUrl = logo;
    return { value: next, migrated: true };
  }
  return { value: input, migrated: false };
}

export function parseTheme(input: unknown): ParseResult<ThemeConfig> {
  const { value, migrated } = migrateThemeInput(input);
  const result = ThemeSchema.safeParse(value);
  if (!result.success) return { ok: false, issues: toIssues(result.error) };
  return { ok: true, value: result.data, migrated };
}

export { DEFAULT_THEME };
