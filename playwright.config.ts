import { defineConfig, devices } from "@playwright/test";

const APP = "http://127.0.0.1:3100";
const WP = "http://127.0.0.1:8099";
export const E2E = {
  app: APP,
  wp: WP,
  editorPassword: "e2e-editor-password",
  revalidateSecret: "e2e-revalidate-secret",
  wpUser: "editor",
  wpPassword: "mock-app-password",
};

export default defineConfig({
  testDir: "client/e2e",
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [["github"], ["html", { open: "never" }]] : "list",
  use: { baseURL: APP, trace: "retain-on-failure" },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
  webServer: [
    {
      command: "pnpm exec tsx scripts/mock-wp/server.ts",
      url: `${WP}/wp-json/rk/v1/theme-config`,
      reuseExistingServer: !process.env.CI,
      env: {
        MOCK_WP_PORT: "8099",
        MOCK_WP_REVALIDATE_URL: `${APP}/api/revalidate`,
        MOCK_WP_REVALIDATE_SECRET: E2E.revalidateSecret,
      },
    },
    {
      command: "node dist/index.js",
      url: `${APP}/healthz`,
      reuseExistingServer: !process.env.CI,
      env: {
        NODE_ENV: "test",
        PORT: "3100",
        WORDPRESS_PUBLIC_URL: WP,
        WORDPRESS_API_URL: `${WP}/wp-json/`,
        PUBLIC_SITE_URL: APP,
        WORDPRESS_AUTH_MODE: "proxy",
        WORDPRESS_APP_USER: E2E.wpUser,
        WORDPRESS_APP_PASSWORD: E2E.wpPassword,
        BUILDER_EDITOR_PASSWORD: E2E.editorPassword,
        REVALIDATE_SECRET: E2E.revalidateSecret,
        PUBLIC_CACHE_TTL_SECONDS: "60",
        LOGIN_RATE_LIMIT_MAX: "1000",
      },
    },
  ],
});
