import { readFileSync } from "node:fs";
import { expect, test, type Page } from "@playwright/test";
import { WPE2E } from "../../playwright.wp.config";
import { STATE_FILE } from "./global-setup";

/** Add a block from the side panel (opens the Blocks tab first). */
async function addBlock(page: Page, label: string) {
  await page.getByRole("tab", { name: "Blocks" }).click();
  await page.getByRole("button", { name: `Add ${label} block` }).click();
}

const state = () =>
  JSON.parse(readFileSync(STATE_FILE, "utf8")) as { pageId: number };

test.skip(
  !process.env.RK_E2E_WP && !process.env.CI,
  "needs network + Playground; set RK_E2E_WP=1"
);

test.describe.configure({ mode: "serial" });

test("anonymous visitors cannot reach the builder screen or the API", async ({
  page,
  request,
}) => {
  await page.goto("/wp-admin/admin.php?page=rk-builder");
  await expect(page).toHaveURL(/wp-login\.php/);
  const res = await request.get(`${WPE2E.wp}/wp-json/rk/v1/builder/pages`);
  expect(res.status()).toBe(401);
});

test("logged-in editor uses the cookie + nonce flow end to end", async ({
  page,
  request,
  context,
}) => {
  await page.goto("/wp-login.php");
  await page.getByLabel("Username or Email Address").fill("admin");
  await page
    .getByLabel("Password", { exact: true })
    .fill("rk-e2e-admin-password");
  await page.getByRole("button", { name: "Log In" }).click();

  await page.goto("/wp-admin/admin.php?page=rk-builder");
  await expect(
    page.getByRole("complementary", { name: "Dashboard" })
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Sign in to edit" })
  ).toHaveCount(0);
  const boot = await page.evaluate(
    () =>
      (
        window as unknown as {
          RK_BUILDER_BOOT: { mode: string; nonce: string };
        }
      ).RK_BUILDER_BOOT
  );
  expect(boot.mode).toBe("nonce");

  await page
    .getByRole("button", { name: "Pages", exact: true })
    .first()
    .click();
  await page.getByRole("link", { name: "Smoke Page" }).click();
  await expect(page).toHaveURL(new RegExp(`page_id=${state().pageId}$`));
  await expect(page.getByTestId("save-status")).toBeVisible();

  await addBlock(page, "Hero");
  await page.getByLabel("Headline").fill("Edited inside wp-admin");
  await page.getByRole("button", { name: "Save draft" }).click();
  await expect(page.getByTestId("save-status")).toHaveText(
    "Draft saved to WordPress"
  );

  await page.reload();
  await expect(page.getByTestId("block-hero")).toContainText(
    "Edited inside wp-admin"
  );

  // CSRF: the login cookie alone is not enough — a request without X-WP-Nonce is treated as anonymous.
  const cookies = await context.cookies();
  const cookieHeader = cookies.map(c => `${c.name}=${c.value}`).join("; ");
  const noNonce = await request.post(
    `${WPE2E.wp}/wp-json/rk/v1/builder/layout/${state().pageId}`,
    {
      headers: { cookie: cookieHeader },
      data: {
        layout: { version: 1, blocks: [] },
        expectedRevision: 1,
        status: "draft",
      },
    }
  );
  expect([401, 403]).toContain(noNonce.status());
  const wrongNonce = await request.post(
    `${WPE2E.wp}/wp-json/rk/v1/builder/layout/${state().pageId}`,
    {
      headers: { cookie: cookieHeader, "x-wp-nonce": "0000000000" },
      data: {
        layout: { version: 1, blocks: [] },
        expectedRevision: 1,
        status: "draft",
      },
    }
  );
  expect([401, 403]).toContain(wrongNonce.status());

  // Publish from wp-admin; the SSR frontend (separate origin) serves it.
  expect((await request.get(`${WPE2E.app}/smoke`)).status()).toBe(404);
  await page.getByRole("button", { name: "Publish", exact: true }).click();
  await page.getByRole("button", { name: "Publish now" }).click();
  await expect(page.getByText(/Published revision/)).toBeVisible();
  await expect
    .poll(async () => (await request.get(`${WPE2E.app}/smoke`)).status(), {
      timeout: 20_000,
    })
    .toBe(200);
  expect(await (await request.get(`${WPE2E.app}/smoke`)).text()).toContain(
    "Edited inside wp-admin"
  );

  // The preview link goes to the frontend origin with a signed, short-lived token.
  const popup = context.waitForEvent("page");
  await page.getByRole("button", { name: "Preview link" }).click();
  const preview = await popup;
  await preview.waitForLoadState();
  expect(preview.url()).toContain(`${WPE2E.app}/preview/smoke?token=`);
  await expect(
    preview.getByRole("heading", { name: "Edited inside wp-admin" })
  ).toBeVisible();
});

test("the embed bundle is served cross-origin only to the WordPress origin", async ({
  request,
}) => {
  const res = await request.get(`${WPE2E.app}/embed/rk-builder.js`);
  expect(res.status()).toBe(200);
  expect(res.headers()["access-control-allow-origin"]).toBe(WPE2E.wp);
});
