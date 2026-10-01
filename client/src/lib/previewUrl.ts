/**
 * Where "Preview link" opens. The all-in-one plugin returns a ready-made WordPress URL; a headless
 * deployment gets only a token and builds the link to its own frontend. Only http(s) is ever opened.
 */
export function previewUrl(
  token: { token: string; url?: string },
  publicSiteUrl: string,
  slug: string
): string | null {
  if (token.url) {
    try {
      const u = new URL(token.url);
      return u.protocol === "https:" || u.protocol === "http:" ? u.href : null;
    } catch {
      return null;
    }
  }
  if (!publicSiteUrl || !slug) return null;
  const base = publicSiteUrl.replace(/\/$/, "");
  return `${base}/preview/${encodeURIComponent(slug)}?token=${encodeURIComponent(token.token)}`;
}
