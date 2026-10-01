import { defineConfig, devices } from "@playwright/test";

/**
 * Nonce-mode E2E: the real builder bundle running inside real WordPress (Playground) wp-admin,
 * authenticated by the WordPress login cookie + REST nonce. The Node server only renders the public site.
 *   RK_E2E_WP=1 pnpm exec playwright test -c playwright.wp.config.ts
 */
export const WPE2E = {
  app: "http://127.0.0.1:3199",
  wp: "http://127.0.0.1:9413",
  secret: "wp-e2e-revalidate-secret",
};

export default defineConfig({
  testDir: "client/e2e-wp",
  globalSetup: "./client/e2e-wp/global-setup.ts",
  globalTeardown: "./client/e2e-wp/global-teardown.ts",
  workers: 1,
  timeout: 60_000,
  reporter: process.env.CI
    ? [
        ["github"],
        ["html", { open: "never", outputFolder: "playwright-report-wp" }],
      ]
    : "list",
  use: { baseURL: WPE2E.wp, trace: "retain-on-failure" },
  projects: [{ name: "wp-admin", use: { ...devices["Desktop Chrome"] } }],
  webServer: {
    command: "node dist/index.js",
    url: `${WPE2E.app}/healthz`,
    reuseExistingServer: !process.env.CI,
    env: {
      NODE_ENV: "test",
      PORT: "3199",
      WORDPRESS_PUBLIC_URL: WPE2E.wp,
      WORDPRESS_API_URL: `${WPE2E.wp}/wp-json/`,
      PUBLIC_SITE_URL: WPE2E.app,
      WORDPRESS_AUTH_MODE: "nonce",
      REVALIDATE_SECRET: WPE2E.secret,
      PUBLIC_CACHE_TTL_SECONDS: "60",
    },
  },
});
