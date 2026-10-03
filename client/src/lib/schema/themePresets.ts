import type { ThemeConfig } from "./theme";

/** The design-system part of a theme: what a style preset sets (logo, social, header and footer are left alone). */
export type ThemeStyle = Pick<
  ThemeConfig,
  | "primary"
  | "bg"
  | "ink"
  | "font"
  | "accent"
  | "dark"
  | "surface"
  | "bodyFont"
  | "headingFont"
  | "headingWeight"
  | "radius"
  | "buttonStyle"
>;

export const THEME_PRESETS: {
  name: string;
  note: string;
  style: ThemeStyle;
}[] = [
  {
    name: "Modern",
    note: "Clean sans, soft corners",
    style: {
      primary: "#4F46E5",
      bg: "#FFFFFF",
      ink: "#0F172A",
      font: "System Sans",
      accent: "#4F46E5",
      dark: "#0F172A",
      surface: "#F1F5F9",
      bodyFont: "System Sans",
      headingFont: "System Sans",
      headingWeight: 700,
      radius: "soft",
      buttonStyle: "solid",
    },
  },
  {
    name: "Classic",
    note: "Serif headings, square edges",
    style: {
      primary: "#A97C50",
      bg: "#FBF8F3",
      ink: "#1F1A17",
      font: "Humanist Sans",
      accent: "#8A5A2B",
      dark: "#1F1A17",
      surface: "#F1EADF",
      bodyFont: "Humanist Sans",
      headingFont: "Classic Serif",
      headingWeight: 500,
      radius: "square",
      buttonStyle: "solid",
    },
  },
  {
    name: "Bold",
    note: "Heavy rounded type, pill buttons",
    style: {
      primary: "#FF5A36",
      bg: "#FFF9F2",
      ink: "#111111",
      font: "Rounded Sans",
      accent: "#FF5A36",
      dark: "#111111",
      surface: "#FFE9D6",
      bodyFont: "Rounded Sans",
      headingFont: "Rounded Sans",
      headingWeight: 800,
      radius: "round",
      buttonStyle: "solid",
    },
  },
  {
    name: "Minimal",
    note: "Black and white, outline buttons",
    style: {
      primary: "#111111",
      bg: "#FFFFFF",
      ink: "#111111",
      font: "System Sans",
      accent: "#555555",
      dark: "#111111",
      surface: "#F5F5F5",
      bodyFont: "System Sans",
      headingFont: "Georgia",
      headingWeight: 400,
      radius: "square",
      buttonStyle: "outline",
    },
  },
];
