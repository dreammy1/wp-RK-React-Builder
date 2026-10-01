import { defineConfig, devices } from "@playwright/test";

/**
 * All-in-one E2E: ONLY WordPress (Playground) running the packaged plugin ZIP contents — no Node server,
 * no Docker. This is what a cPanel customer gets.
 *   pnpm build:plugin && RK_E2E_ALLINONE=1 pnpm exec playwright test -c playwright.allinone.config.ts
 */
export const AIO = {
  wp: "http://127.0.0.1:9414",
  admin: "admin",
  adminPass: "rk-e2e-admin-password",
  editor: "smoke_editor",
  editorPass: "rk-e2e-editor-password",
  subscriber: "smoke_sub",
  subscriberPass: "rk-e2e-subscriber-password",
};

export default defineConfig({
  testDir: "client/e2e-allinone",
  globalSetup: "./client/e2e-allinone/global-setup.ts",
  workers: 1,
  timeout: 90_000,
  reporter: process.env.CI
    ? [
        ["github"],
        ["html", { open: "never", outputFolder: "playwright-report-allinone" }],
      ]
    : "list",
  use: { baseURL: AIO.wp, trace: "retain-on-failure" },
  projects: [{ name: "all-in-one", use: { ...devices["Desktop Chrome"] } }],
});
