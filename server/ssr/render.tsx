import { renderToString } from "react-dom/server";
import { LayoutRenderer } from "@/render/BlockRenderer";
import { ContentContext, staticContentSource } from "@/render/content";
import { ReusableContext, staticReusableSource } from "@/render/reusable";
import { themeToCssText } from "@/lib/schema/theme";
import type { Env } from "../env";
import type { PublicPage } from "./data";

export const escapeHtml = (s: string) =>
  s
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");

const FONTS =
  "https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Space+Grotesk:wght@400;500;700&display=swap";

export type DocOptions = {
  env: Env;
  nonce: string;
  cssVersion: string;
  telemetry: boolean;
};

function shell(o: DocOptions, head: string, body: string, bodyClass = "") {
  const { env } = o;
  const analytics =
    env.ANALYTICS_ENDPOINT && env.ANALYTICS_WEBSITE_ID
      ? `<script defer src="${escapeHtml(env.ANALYTICS_ENDPOINT.replace(/\/$/, ""))}/umami" data-website-id="${escapeHtml(env.ANALYTICS_WEBSITE_ID)}"></script>`
      : "";
  const rum = o.telemetry
    ? `<script defer src="/rum.js?v=${o.cssVersion}"></script>`
    : "";
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${head}<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="${FONTS}"><link rel="stylesheet" href="/site.css?v=${o.cssVersion}">${analytics}${rum}</head><body class="${bodyClass}">${body}</body></html>`;
}

const absolute = (env: Env, path: string) =>
  `${env.PUBLIC_SITE_URL.replace(/\/$/, "")}${path}`;
export const pagePath = (env: Env, slug: string) =>
  slug === env.PUBLIC_HOME_SLUG ? "/" : `/${slug}`;

export function renderPageHtml(p: PublicPage, o: DocOptions): string {
  const { env } = o;
  const { page, theme } = p;
  const title =
    page.slug === env.PUBLIC_HOME_SLUG
      ? `${env.SITE_NAME}`
      : `${page.title} — ${env.SITE_NAME}`;
  const url = absolute(env, pagePath(env, page.slug));
  const img = page.image
    ? page.image.startsWith("http")
      ? page.image
      : absolute(env, page.image)
    : "";
  const meta = [
    `<title>${escapeHtml(title)}</title>`,
    `<meta name="description" content="${escapeHtml(page.description)}">`,
    p.preview
      ? `<meta name="robots" content="noindex, nofollow">`
      : `<link rel="canonical" href="${escapeHtml(url)}">`,
    `<meta property="og:type" content="website"><meta property="og:site_name" content="${escapeHtml(env.SITE_NAME)}">`,
    `<meta property="og:title" content="${escapeHtml(page.title)}"><meta property="og:description" content="${escapeHtml(page.description)}"><meta property="og:url" content="${escapeHtml(url)}">`,
    img ? `<meta property="og:image" content="${escapeHtml(img)}">` : "",
    `<meta name="twitter:card" content="${img ? "summary_large_image" : "summary"}"><meta name="twitter:title" content="${escapeHtml(page.title)}"><meta name="twitter:description" content="${escapeHtml(page.description)}">`,
    img ? `<meta name="twitter:image" content="${escapeHtml(img)}">` : "",
    `<style nonce="${o.nonce}">${themeToCssText(theme, ".site-root")}</style>`,
  ].join("");

  const main = renderToString(
    <ReusableContext.Provider value={staticReusableSource(p.reusables)}>
      <ContentContext.Provider value={staticContentSource(p.content)}>
        <LayoutRenderer layout={p.layout} mode="public" />
      </ContentContext.Provider>
    </ReusableContext.Provider>
  );
  const logo = theme.logoUrl
    ? `<img class="site-logo" src="${escapeHtml(theme.logoUrl)}" alt="${escapeHtml(env.SITE_NAME)}" height="32">`
    : `<span class="site-wordmark">${escapeHtml(env.SITE_NAME)}</span>`;
  const social = Object.entries(theme.social ?? {})
    .filter(([, v]) => v)
    .map(
      ([k, v]) =>
        `<a href="${escapeHtml(v!)}" rel="noopener noreferrer">${escapeHtml(k)}</a>`
    )
    .join(" ");
  const banner = p.preview
    ? `<div class="preview-banner" role="status">Draft preview — not published</div>`
    : "";
  const body = `<div class="site-root">${banner}<header class="site-header${theme.header?.sticky ? " sticky" : ""}"><a href="/" class="site-brand">${logo}</a></header><main id="main">${main}</main><footer class="site-footer"><span>${escapeHtml(env.SITE_NAME)}</span><span class="site-social">${social}</span></footer></div>`;
  return shell(o, meta, body);
}

export function renderStatusHtml(o: DocOptions, status: 404 | 503): string {
  const copy =
    status === 404
      ? {
          h: "Page not found",
          p: "The page you’re looking for doesn’t exist or isn’t published.",
        }
      : {
          h: "We’ll be right back",
          p: "This page can’t be loaded right now. Please try again in a moment.",
        };
  const head = `<title>${copy.h} — ${escapeHtml(o.env.SITE_NAME)}</title><meta name="robots" content="noindex"><style nonce="${o.nonce}">:root{--site-primary:#C7F36B;--site-bg:#F8F5ED;--site-ink:#1B2430;--site-font:system-ui,sans-serif}</style>`;
  return shell(
    o,
    head,
    `<div class="site-root status-page"><main><h1>${copy.h}</h1><p>${copy.p}</p><p><a class="site-btn" href="/">Back to home</a></p></main></div>`
  );
}
