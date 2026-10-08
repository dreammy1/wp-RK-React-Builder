import { z } from "zod";
import { cssLength, imageFit, imagePosition, paintColor } from "./primitives";

/**
 * Per-block presentation model — the "Style" and "Layout" halves of a mature visual editor
 * (Elementor's Advanced tab, Framer's right-hand inspector).
 *
 * Two rules shape this module:
 *
 * 1. **It is additive and optional.** Every block may carry an `advanced` object, but a block
 *    without one must serialise exactly as it did before this module existed. All fields are
 *    optional and `advancedToCss` returns "" for an empty object, so older documents, the PHP
 *    validator and the render-parity fixtures are untouched unless someone actually styles a block.
 *
 * 2. **Props never reach CSS as strings.** The only values that can be emitted are pixel lengths
 *    (`cssLength`), colours (`paintColor`/`hexColor`) and fixed enums. There is no free-form
 *    style string, so a saved layout cannot inject declarations into the public stylesheet.
 */

/** Breakpoints a block can be tuned at. `base` applies everywhere; the others are `min-width` overrides. */
export const BREAKPOINTS = ["base", "tablet", "phone"] as const;
export type Breakpoint = (typeof BREAKPOINTS)[number];

/** `min-width` for each non-base breakpoint. Kept in sync with site.css media queries. */
export const BREAKPOINT_MIN_WIDTH: Record<
  Exclude<Breakpoint, "base">,
  number
> = {
  tablet: 981,
  phone: 641,
};

export const SPACE_SIDES = ["top", "right", "bottom", "left"] as const;

const px = cssLength.optional();

export const SpacingSchema = z
  .strictObject({
    top: px,
    right: px,
    bottom: px,
    left: px,
  })
  .optional();

export const SizeSchema = z
  .strictObject({
    /** Max content width of the block. `full` spans the whole canvas, `boxed` uses the site column. */
    width: z.enum(["full", "wide", "boxed", "narrow"]).optional(),
    /** Explicit minimum height, mostly useful for hero-like sections. */
    minHeight: cssLength.optional(),
    /** Column span when the block sits inside a multi-column section. */
    colSpan: z.number().int().min(1).max(4).optional(),
  })
  .optional();

export const BackgroundSchema = z
  .strictObject({
    color: paintColor.optional(),
    /** A CSS gradient preset, never a raw string. */
    gradient: z.enum(["none", "fade", "diagonal", "radial"]).optional(),
    imageMediaId: z.number().int().min(0).max(2_147_483_647).optional(),
    imageUrl: z.string().max(500).optional(),
    imageFit: imageFit.optional(),
    imagePosition: imagePosition.optional(),
  })
  .optional();

export const BorderSchema = z
  .strictObject({
    width: cssLength.optional(),
    color: paintColor.optional(),
    style: z.enum(["solid", "dashed", "dotted"]).optional(),
    radius: cssLength.optional(),
  })
  .optional();

export const ShadowSchema = z
  .strictObject({
    preset: z.enum(["none", "sm", "md", "lg", "glow"]).optional(),
  })
  .optional();

export const TypographySchema = z
  .strictObject({
    /** Sizes only apply to heading-like blocks; body copy keeps the theme scale. */
    size: cssLength.optional(),
    weight: z.number().int().min(300).max(900).optional(),
    align: z.enum(["left", "center", "right"]).optional(),
    /** Text colour override for the block's primary text. */
    color: paintColor.optional(),
  })
  .optional();

export const VisibilitySchema = z
  .strictObject({
    hideDesktop: z.boolean().optional(),
    hideTablet: z.boolean().optional(),
    hidePhone: z.boolean().optional(),
  })
  .optional();

/** Per-breakpoint overrides: the same shape as the base, minus further nesting. */
export const BreakpointOverridesSchema = z
  .strictObject({
    spacing: SpacingSchema,
    size: SizeSchema,
    background: BackgroundSchema,
    border: BorderSchema,
    typography: TypographySchema,
  })
  .optional();

export const AdvancedStyleSchema = z
  .strictObject({
    spacing: SpacingSchema,
    size: SizeSchema,
    background: BackgroundSchema,
    border: BorderSchema,
    shadow: ShadowSchema,
    typography: TypographySchema,
    visibility: VisibilitySchema,
    /** Responsive overrides, keyed by breakpoint. */
    overrides: z
      .strictObject({
        tablet: BreakpointOverridesSchema,
        phone: BreakpointOverridesSchema,
      })
      .optional(),
    /** Free-form but inert: a stable anchor / CSS class hook, sanitised to a slug. */
    cssClass: z
      .string()
      .regex(/^[a-z0-9-]{0,40}$/, "Use lowercase letters, numbers and dashes")
      .optional(),
    /** Editor-only label shown in the layers panel; never rendered on the public site. */
    name: z.string().max(60).optional(),
  })
  .optional();

export type AdvancedStyle = z.infer<typeof AdvancedStyleSchema>;
export type Spacing = NonNullable<AdvancedStyle>["spacing"];
export type SizeConfig = NonNullable<AdvancedStyle>["size"];
export type BackgroundConfig = NonNullable<AdvancedStyle>["background"];
export type BorderConfig = NonNullable<AdvancedStyle>["border"];
export type ShadowConfig = NonNullable<AdvancedStyle>["shadow"];
export type TypographyConfig = NonNullable<AdvancedStyle>["typography"];
export type VisibilityConfig = NonNullable<AdvancedStyle>["visibility"];

/* ------------------------------------------------------------------ *
 * Editor-facing option tables (single source for the panel + tests)
 * ------------------------------------------------------------------ */

export const SPACE_PRESETS = [
  "0px",
  "8px",
  "16px",
  "24px",
  "32px",
  "48px",
  "64px",
  "96px",
] as const;
export const RADIUS_PRESETS = [
  "0px",
  "4px",
  "8px",
  "12px",
  "16px",
  "24px",
  "999px",
] as const;
export const BORDER_WIDTH_PRESETS = ["0px", "1px", "2px", "4px"] as const;
export const FONT_SIZE_PRESETS = [
  "14px",
  "16px",
  "18px",
  "20px",
  "24px",
  "32px",
  "40px",
  "56px",
  "72px",
] as const;

export const SHADOW_VALUES = {
  sm: "0 1px 2px rgba(0,0,0,.08)",
  md: "0 6px 18px rgba(0,0,0,.10)",
  lg: "0 18px 50px rgba(0,0,0,.16)",
  glow: "0 0 0 4px rgba(199,243,107,.35)",
} as const;

/** Editor option tables for the style panel. The `value` keys match the schema enums exactly. */
export const WIDTH_PRESETS = [
  { value: "full", label: "Full width" },
  { value: "wide", label: "Wide" },
  { value: "boxed", label: "Boxed" },
  { value: "narrow", label: "Narrow" },
] as const;

export const BACKGROUND_GRADIENTS = [
  { value: "none", label: "None" },
  { value: "fade", label: "Fade to bottom" },
  { value: "diagonal", label: "Diagonal" },
  { value: "radial", label: "Radial glow" },
] as const;

export const SHADOW_PRESETS = [
  { value: "none", label: "None" },
  { value: "sm", label: "Small" },
  { value: "md", label: "Medium" },
  { value: "lg", label: "Large" },
  { value: "glow", label: "Glow" },
] as const;

export const GRADIENT_VALUES = {
  fade: "linear-gradient(180deg, rgba(0,0,0,0) 0%, rgba(0,0,0,.45) 100%)",
  diagonal: "linear-gradient(135deg, rgba(0,0,0,.35) 0%, rgba(0,0,0,0) 70%)",
  radial:
    "radial-gradient(120% 120% at 50% 0%, rgba(0,0,0,.35) 0%, rgba(0,0,0,0) 60%)",
} as const;

const SIZE_MAX_WIDTH = {
  full: "none",
  wide: "1320px",
  boxed: "var(--site-container, 1144px)",
  narrow: "760px",
} as const;

/* ------------------------------------------------------------------ *
 * CSS generation
 * ------------------------------------------------------------------ */

/** CSS declarations for one breakpoint's worth of style. Empty array = nothing to emit. */
function declarations(style: NonNullable<AdvancedStyle> | undefined): string[] {
  if (!style) return [];
  const out: string[] = [];
  const { spacing, size, background, border, shadow, typography } = style;

  if (spacing) {
    if (spacing.top) out.push(`padding-top:${spacing.top}`);
    if (spacing.right) out.push(`padding-right:${spacing.right}`);
    if (spacing.bottom) out.push(`padding-bottom:${spacing.bottom}`);
    if (spacing.left) out.push(`padding-left:${spacing.left}`);
  }
  if (size) {
    if (size.width) out.push(`max-width:${SIZE_MAX_WIDTH[size.width]}`);
    if (size.width && size.width !== "full") out.push("margin-inline:auto");
    if (size.minHeight) out.push(`min-height:${size.minHeight}`);
    if (size.colSpan) out.push(`grid-column:span ${size.colSpan}`);
  }
  if (background) {
    // `!important` because every block root paints its own background (e.g. `.pf-section`); without
    // it the chosen colour would be hidden behind the block's default and appear to do nothing.
    if (background.color) out.push(`background-color:${background.color}!important`);
    if (background.gradient && background.gradient !== "none")
      out.push(`background-image:${GRADIENT_VALUES[background.gradient]}!important`);
    if (background.imageUrl) {
      out.push(`background-image:url("${background.imageUrl}")!important`);
      out.push(`background-size:${background.imageFit ?? "cover"}!important`);
      out.push(`background-position:${background.imagePosition ?? "center"}!important`);
      out.push("background-repeat:no-repeat!important");
    }
  }
  if (border) {
    const w = border.width;
    if (w && w !== "0px")
      out.push(
        `border:${w} ${border.style ?? "solid"} ${border.color ?? "currentColor"}!important`
      );
    else if (border.color && border.style)
      out.push(`border-color:${border.color}!important`);
    if (border.radius) out.push(`border-radius:${border.radius}!important`);
  }
  if (shadow?.preset && shadow.preset !== "none")
    out.push(`box-shadow:${SHADOW_VALUES[shadow.preset]}!important`);
  if (typography) {
    if (typography.size) out.push(`--rk-block-size:${typography.size}`);
    if (typography.weight) out.push(`--rk-block-weight:${typography.weight}`);
    if (typography.align) out.push(`text-align:${typography.align}!important`);
    // Authoritative so the block's own `color` (e.g. `.pf-section { color: var(--site-ink) }`) cannot hide it.
    if (typography.color) out.push(`color:${typography.color}!important`);
  }
  return out;
}

const rule = (selector: string, decls: string[]) =>
  decls.length ? `${selector}{${decls.join(";")}}` : "";

/**
 * Selectors for a styled block.
 *
 * The `rk-style-<id>` class sits on a wrapper, but every block View paints its own background and
 * text colour on its own root element (e.g. `.pf-section { background: var(--site-bg) }`), and the
 * depth differs per surface:
 *
 *   editor :  .canvas-block.rk-style-x > .canvas-view > <block root>
 *   public :  .rk-style-x            > <block root>
 *
 * `.canvas-view` is an editor-only, transparent shim, so the block root is reached through it rather
 * than targeted directly. This works in both surfaces without every View having to accept a class.
 */
function selectorsFor(scope: "editor" | "public", id: string): string[] {
  const root = scope === "editor" ? ".editor-canvas" : ".site-root";
  const wrap = `${root} .rk-style-${id}`;
  // `> *` covers the public surface (block root is the direct child) and `> .canvas-view > *`
  // covers the editor, where the block root sits inside the transparent `.canvas-view` shim.
  return [wrap, `${wrap} > *`, `${wrap} > .canvas-view > *`];
}

/** The heading-like elements inside a block, so typography size/weight can reach them. */
function headingSelectorsFor(
  scope: "editor" | "public",
  id: string
): string[] {
  const root = scope === "editor" ? ".editor-canvas" : ".site-root";
  return [`${root} .rk-style-${id} :is(h1,h2,h3,h4,.pf-kicker)`];
}

/**
 * Turn one block's advanced style into scoped CSS.
 *
 * @param id    block id (used to scope every selector to a single block)
 * @param style the block's `advanced` object (may be undefined)
 * @param scope the surface the block lives on: the editor canvas or the public site
 */
export function advancedToCss(
  id: string,
  style: AdvancedStyle,
  scope: "editor" | "public" = "public"
): string {
  if (!style) return "";
  const targets = selectorsFor(scope, id);
  const base = targets.join(",");
  const out: string[] = [];

  // Visibility: independent of the styled declarations so hiding works on its own.
  const vis = style.visibility;
  if (vis) {
    const hidden = [
      vis.hideDesktop ? "" : null,
      vis.hideTablet
        ? `@media (min-width:${BREAKPOINT_MIN_WIDTH.tablet}px){${base}{display:none!important}}`
        : null,
      vis.hidePhone
        ? `@media (min-width:${BREAKPOINT_MIN_WIDTH.phone}px){${base}{display:none!important}}`
        : null,
    ].filter(Boolean);
    if (vis.hideDesktop) hidden.unshift(`${base}{display:none!important}`);
    out.push(...(hidden as string[]));
  }

  out.push(rule(base, declarations(style)));

  // Typography tokens cascade to headings/copy inside the block without touching the theme scale
  // when they are unset.
  if (style.typography?.size || style.typography?.weight) {
    const parts: string[] = [];
    if (style.typography.size)
      parts.push(`font-size:var(--rk-block-size)!important`);
    if (style.typography.weight)
      parts.push(`font-weight:var(--rk-block-weight)!important`);
    out.push(rule(headingSelectorsFor(scope, id).join(","), parts));
  }

  const overrides = style.overrides;
  if (overrides) {
    for (const bp of ["tablet", "phone"] as const) {
      const decls = declarations(overrides[bp]);
      if (decls.length)
        out.push(
          `@media (min-width:${BREAKPOINT_MIN_WIDTH[bp]}px){${rule(base, decls)}}`
        );
    }
  }

  return out.filter(Boolean).join("");
}

/** Convenience for callers that only need "does this block have anything to emit?". */
export function hasAdvancedStyle(style: AdvancedStyle): boolean {
  if (!style) return false;
  return Object.keys(style).length > 0;
}

/* ------------------------------------------------------------------ *
 * Layout-wide helpers
 * ------------------------------------------------------------------ */

/** Minimal shape `layoutStyles` needs — keeps this module free of a layout.ts import cycle. */
type StyleCarrier = {
  id: string;
  advanced?: AdvancedStyle;
};

/** The class every rendered block root carries, so advanced CSS can target it. */
export const styleClass = (id: string) => `rk-style-${id}`;

/**
 * All advanced-style CSS for a layout, as one string. Used by the editor canvas, the preview and
 * the server-rendered site so a styled block looks identical in every surface.
 *
 * Returns "" when no block has any advanced style — the common case, and the reason existing
 * documents render byte-for-byte as before.
 */
export function layoutStyles(
  blocks: readonly StyleCarrier[],
  scope: "editor" | "public" = "public"
): string {
  const parts: string[] = [];
  for (const block of blocks) {
    if (!hasAdvancedStyle(block.advanced)) continue;
    const css = advancedToCss(block.id, block.advanced, scope);
    if (css) parts.push(css);
  }
  return parts.join("");
}
