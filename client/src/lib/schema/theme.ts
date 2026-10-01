import { z } from "zod";
import { hexColor, imageUrl, LIMITS } from "./primitives";

export const FONTS = ["Space Grotesk", "IBM Plex Mono", "Georgia"] as const;
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
};

/** The only way theme values reach CSS: validated tokens → custom properties. */
export function themeToCssVars(theme: ThemeConfig): Record<string, string> {
  return {
    "--site-primary": theme.primary,
    "--site-bg": theme.bg,
    "--site-ink": theme.ink,
    "--site-font": FONT_STACKS[theme.font],
  };
}

export function themeToCssText(theme: ThemeConfig, selector = ":root"): string {
  const vars = themeToCssVars(theme);
  return `${selector}{${Object.entries(vars)
    .map(([k, v]) => `${k}:${v}`)
    .join(";")}}`;
}
