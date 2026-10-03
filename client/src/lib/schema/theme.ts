import { z } from "zod";
import { hexColor, imageUrl, LIMITS } from "./primitives";

export const FONTS = [
  "Space Grotesk",
  "IBM Plex Mono",
  "Georgia",
  "System Sans",
  "Humanist Sans",
  "Classic Serif",
  "Rounded Sans",
] as const;
export const HEADING_WEIGHTS = [400, 500, 600, 700, 800] as const;
export const RADII = ["square", "soft", "round"] as const;
export const BUTTON_STYLES = ["solid", "outline"] as const;
export type ThemeFont = (typeof FONTS)[number];

const socialUrl = z
  .string()
  .max(LIMITS.maxUrl)
  .refine(
    v => v === "" || /^https:\/\/[^\s]+$/.test(v),
    "Expected an https URL"
  );

export const ThemeSchema = z.strictObject({
  version: z.literal(LIMITS.schemaVersion),
  primary: hexColor,
  bg: hexColor,
  ink: hexColor,
  font: z.enum(FONTS),
  // Design system (all optional: a theme without them looks exactly as before).
  accent: hexColor.optional(),
  dark: hexColor.optional(),
  surface: hexColor.optional(),
  bodyFont: z.enum(FONTS).optional(),
  headingFont: z.enum(FONTS).optional(),
  headingWeight: z
    .number()
    .refine(v => (HEADING_WEIGHTS as readonly number[]).includes(v), "Invalid")
    .optional(),
  radius: z.enum(RADII).optional(),
  buttonStyle: z.enum(BUTTON_STYLES).optional(),
  logoMediaId: z.number().int().min(0).max(2_147_483_647).optional(),
  logoUrl: imageUrl.optional(),
  social: z
    .strictObject({
      instagram: socialUrl.optional(),
      linkedin: socialUrl.optional(),
    })
    .optional(),
  header: z.strictObject({ sticky: z.boolean().optional() }).optional(),
  footer: z
    .strictObject({ columns: z.number().int().min(1).max(4).optional() })
    .optional(),
});
export type ThemeConfig = z.infer<typeof ThemeSchema>;

export const DEFAULT_THEME: ThemeConfig = {
  version: 1,
  primary: "#C7F36B",
  bg: "#F8F5ED",
  ink: "#1B2430",
  font: "Space Grotesk",
};

const FONT_STACKS: Record<ThemeFont, string> = {
  "Space Grotesk": "'Space Grotesk', system-ui, sans-serif",
  "IBM Plex Mono": "'IBM Plex Mono', ui-monospace, monospace",
  Georgia: "Georgia, 'Times New Roman', serif",
  "System Sans": "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
  "Humanist Sans":
    "'Gill Sans', 'Gill Sans MT', Seravek, 'Trebuchet MS', sans-serif",
  "Classic Serif":
    "'Iowan Old Style', 'Palatino Linotype', Palatino, 'Book Antiqua', Georgia, serif",
  "Rounded Sans":
    "ui-rounded, 'SF Pro Rounded', 'Hiragino Maru Gothic ProN', Quicksand, 'Varela Round', sans-serif",
};

const RADIUS_PX = {
  square: ["0", "0"],
  soft: ["8px", "6px"],
  round: ["16px", "999px"],
} as const;

/** The only way theme values reach CSS: validated tokens → custom properties. */
export function themeToCssVars(theme: ThemeConfig): Record<string, string> {
  const vars: Record<string, string> = {
    "--site-primary": theme.primary,
    "--site-bg": theme.bg,
    "--site-ink": theme.ink,
  };
  let font = FONT_STACKS[theme.font];
  if (theme.bodyFont) {
    font = FONT_STACKS[theme.bodyFont];
    vars["--site-body-font"] = font;
  }
  vars["--site-font"] = font;
  if (theme.accent) vars["--site-accent"] = theme.accent;
  if (theme.dark) vars["--site-dark"] = theme.dark;
  if (theme.surface) vars["--site-surface"] = theme.surface;
  if (theme.headingFont)
    vars["--site-heading-font"] = FONT_STACKS[theme.headingFont];
  if (theme.radius) {
    vars["--site-radius"] = RADIUS_PX[theme.radius][0];
    vars["--site-btn-radius"] = RADIUS_PX[theme.radius][1];
  }
  return vars;
}

/** Design-system rules for what the theme sets (nothing extra for a theme without them). Mirrors rk_builder_theme_design_rules(). */
export function themeToDesignRules(
  theme: ThemeConfig,
  scope = ".site-root"
): string {
  const r: string[] = [];
  const heads = `${scope} :is(h1,h2,h3,h4)`;
  if (theme.headingFont)
    r.push(`${heads}{font-family:var(--site-heading-font)!important}`);
  if (theme.headingWeight)
    r.push(`${heads}{font-weight:${theme.headingWeight}!important}`);
  if (theme.accent)
    r.push(`${scope} :is(p,li,td,dd) a:not([class]){color:var(--site-accent)}`);
  if (theme.surface)
    r.push(
      `${scope} :is(.content-card,.pf-panel,.site-card){background:var(--site-surface)}`
    );
  if (theme.radius) {
    r.push(
      `${scope} :is(.content-card,.content-card img,.pf-section img,.site-grid img,.pf-panel,.site-card,input,select,textarea){border-radius:var(--site-radius)}`
    );
    r.push(
      `${scope} :is(.site-btn,.pf-btn){border-radius:var(--site-btn-radius)}`
    );
  }
  if (theme.buttonStyle === "outline") {
    r.push(
      `${scope} .site-btn{background:transparent!important;color:var(--site-ink)!important;box-shadow:inset 0 0 0 2px var(--site-ink)}`,
      `${scope} .pf-btn.solid{background:transparent!important;color:#fff!important;box-shadow:inset 0 0 0 2px #fff}`,
      `${scope} .pf-btn.dark{background:transparent!important;color:var(--pf-ink)!important;box-shadow:inset 0 0 0 2px var(--pf-ink)}`
    );
  }
  return r.join("");
}

export function themeToCssText(theme: ThemeConfig, selector = ":root"): string {
  const vars = themeToCssVars(theme);
  return `${selector}{${Object.entries(vars)
    .map(([k, v]) => `${k}:${v}`)
    .join(";")}}${themeToDesignRules(theme)}`;
}
