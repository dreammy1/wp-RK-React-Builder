import type { AdvancedStyle, Breakpoint } from "@/lib/schema/style";
import {
  BACKGROUND_GRADIENTS,
  BORDER_WIDTH_PRESETS,
  FONT_SIZE_PRESETS,
  RADIUS_PRESETS,
  SHADOW_PRESETS,
  SPACE_PRESETS,
  WIDTH_PRESETS,
} from "@/lib/schema/style";

/**
 * Bridge between the flat "one control → one patch" style panel and the nested, breakpoint-aware
 * `advanced` object on a block.
 *
 * Keeping this mapping in one place means the panel never has to know that phone padding lives at
 * `advanced.overrides.phone.spacing`, and the reducer only has to merge the returned object.
 */

/** The style groups a control can write to (mirrors the schema's group names). */
export const FIELD_KEYS = [
  "spacing",
  "size",
  "background",
  "border",
  "shadow",
  "typography",
  "visibility",
] as const;
export type FieldKey = (typeof FIELD_KEYS)[number];

/** The vocabulary the style panel offers, re-exported from the schema so there is one source. */
export {
  BACKGROUND_GRADIENTS,
  BORDER_WIDTH_PRESETS,
  FONT_SIZE_PRESETS,
  RADIUS_PRESETS,
  SHADOW_PRESETS,
  SPACE_PRESETS,
  WIDTH_PRESETS,
};

/** The values shown for the active breakpoint, with the base style underneath as fallback. */
export function styleForBreakpoint(
  advanced: AdvancedStyle,
  breakpoint: Breakpoint
): AdvancedStyle {
  if (!advanced) return undefined;
  if (breakpoint === "base") return advanced;
  const override = advanced.overrides?.[breakpoint];
  if (!override) return {};
  // Overlay the override's groups on the base so each control shows what is actually in effect.
  return {
    ...advanced,
    spacing: { ...advanced.spacing, ...override.spacing },
    size: { ...advanced.size, ...override.size },
    background: { ...advanced.background, ...override.background },
    border: { ...advanced.border, ...override.border },
    typography: { ...advanced.typography, ...override.typography },
  };
}

/**
 * Apply a panel edit at the right level.
 *
 * On the base breakpoint the group is written directly. On an override breakpoint it is written
 * into `overrides.<bp>`, leaving every other breakpoint and the base untouched. Clearing a group
 * (no keys left) drops it, which is how a control returns a block to its designed default.
 */
export function buildStylePatch(
  field: FieldKey,
  value: unknown,
  breakpoint: Breakpoint
): Record<string, unknown> {
  if (breakpoint === "base") {
    return { [field]: isEmpty(value) ? undefined : value };
  }
  return {
    overrides: { [breakpoint]: { [field]: isEmpty(value) ? undefined : value } },
  };
}

const isEmpty = (value: unknown): boolean => {
  if (value === undefined || value === null) return true;
  if (typeof value === "object" && !Array.isArray(value))
    return Object.keys(value as object).length === 0;
  return false;
};

/**
 * Deep-merge a patch produced by {@link buildStylePatch} into an existing `advanced` object.
 *
 * The model is only two levels deep (group → breakpoint → group), so this stays readable rather
 * than reaching for a general-purpose merge. Removed leaves make the block fall back to the theme.
 */
export function mergeAdvanced(
  current: AdvancedStyle,
  patch: Record<string, unknown>
): AdvancedStyle {
  const base = { ...(current ?? {}) } as Record<string, unknown>;
  for (const [key, value] of Object.entries(patch)) {
    if (key !== "overrides") {
      if (value === undefined) delete base[key];
      else base[key] = value;
      continue;
    }
    const overrides = {
      ...((base.overrides as Record<string, unknown>) ?? {}),
    };
    for (const [bp, groups] of Object.entries(
      value as Record<string, Record<string, unknown>>
    )) {
      const merged = { ...((overrides[bp] as Record<string, unknown>) ?? {}) };
      for (const [group, groupValue] of Object.entries(groups)) {
        if (groupValue === undefined) delete merged[group];
        else merged[group] = groupValue;
      }
      if (Object.keys(merged).length) overrides[bp] = merged;
      else delete overrides[bp];
    }
    if (Object.keys(overrides).length) base.overrides = overrides;
    else delete base.overrides;
  }
  return Object.keys(base).length ? (base as AdvancedStyle) : undefined;
}

/** True when any breakpoint carries an override — drives the "reset responsive" affordance. */
export function hasOverrides(advanced: AdvancedStyle): boolean {
  const o = advanced?.overrides;
  if (!o) return false;
  return Boolean(
    (o.tablet && Object.keys(o.tablet).length) ||
      (o.phone && Object.keys(o.phone).length)
  );
}
