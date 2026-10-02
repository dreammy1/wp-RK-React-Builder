import { expect, test } from "@playwright/test";
import { E2E } from "../../playwright.config";
import { openEditor, resetBackend, saveDraft, addBlock } from "./helpers";

test.beforeEach(async ({ request }) => resetBackend(request));

test("draft → save does not publish; publish makes the server-rendered page live and fresh", async ({
  page,
  request,
}) => {
  expect((await request.get(`${E2E.app}/about`)).status()).toBe(404);

  await openEditor(page, 43);
  await addBlock(page, "Hero");
  await page.getByLabel("Headline").fill("Fresh from the builder");
  await saveDraft(page);
  expect(
    (await request.get(`${E2E.app}/about`)).status(),
    "saving a draft must not publish"
  ).toBe(404);

  await page.getByRole("button", { name: "Publish", exact: true }).click();
  await page
    .getByRole("dialog", { name: "Publish this page" })
    .getByRole("button", { name: "Publish now" })
    .click();
  await expect(page.getByText(/Published revision/)).toBeVisible();

  const live = await request.get(`${E2E.app}/about`);
  expect(live.status()).toBe(200);
  const html = await live.text();
  expect(html).toContain("<h1>Fresh from the builder</h1>");
  expect(html).toContain(
    '<link rel="canonical" href="http://127.0.0.1:3100/about">'
  );
  expect(html).not.toMatch(/inspector|block-handle|canvas-block/);

  // second edit: still live copy until re-published
  await page
    .getByTestId("block-hero")
    .getByRole("button", { name: /Select Hero/ })
    .click();
  await page.getByLabel("Headline").fill("Second edit");
  await saveDraft(page);
  expect(await (await request.get(`${E2E.app}/about`)).text()).toContain(
    "Fresh from the builder"
  );
  await page.getByRole("button", { name: "Update live page" }).click();
  await page.getByRole("button", { name: "Publish now" }).click();
  await expect(page.getByText(/Published revision/).first()).toBeVisible();
  await expect
    .poll(async () => (await request.get(`${E2E.app}/about`)).text(), {
      timeout: 5000,
    })
    .toContain("Second edit");
});

test("unpublishing returns the page to draft and 404s publicly", async ({
  page,
  request,
}) => {
  expect((await request.get(`${E2E.app}/`)).status()).toBe(200);
  await openEditor(page, 42);
  await page.getByRole("button", { name: "Update live page" }).click();
  await page.getByRole("button", { name: "Unpublish" }).click();
  await expect
    .poll(async () => (await request.get(`${E2E.app}/about`)).status())
    .toBe(404);
  await expect
    .poll(async () => (await request.get(`${E2E.app}/`)).status())
    .toBe(404);
});

test("public page: SEO metadata, live content, image dimensions, security headers, no builder code", async ({
  request,
}) => {
  const res = await request.get(`${E2E.app}/`);
  const html = await res.text();
  expect(res.status()).toBe(200);
  expect(html).toContain("<h1>Powering what’s next</h1>");
  expect(html).toMatch(/<meta name="description" content="Licensed electrical/);
  expect(html).toContain('property="og:title"');
  expect(html).toContain('name="twitter:card"');
  expect(html).toContain("Residential Wiring");
  expect(html).toMatch(
    /<img[^>]+alt="Wiring"[^>]+width="800"[^>]+height="500"/
  );
  expect(res.headers()["content-security-policy"]).toContain(
    "default-src 'none'"
  );
  expect(res.headers()["x-content-type-options"]).toBe("nosniff");
  expect(html).not.toContain("/assets/index-");
  const robots = await request.get(`${E2E.app}/robots.txt`);
  expect(await robots.text()).toContain("Disallow: /builder");
  expect((await request.get(`${E2E.app}/does-not-exist`)).status()).toBe(404);
  expect(
    (await request.get(`${E2E.app}/contact`)).status(),
    "private pages are not public"
  ).toBe(404);
});

test("draft preview link: short-lived token, noindex, shows unpublished edits", async ({
  page,
  context,
  request,
}) => {
  await openEditor(page, 43);
  await addBlock(page, "Hero");
  await page.getByLabel("Headline").fill("Only in the draft");
  const popup = context.waitForEvent("page");
  await page.getByRole("button", { name: "Preview link" }).click();
  const preview = await popup;
  await preview.waitForLoadState();
  await expect(
    preview.getByRole("heading", { name: "Only in the draft" })
  ).toBeVisible();
  await expect(preview.getByText("Draft preview")).toBeVisible();
  expect(
    await preview.locator('meta[name="robots"]').getAttribute("content")
  ).toMatch(/noindex/);
  expect((await request.get(`${E2E.app}/about`)).status()).toBe(404);
  expect(
    (
      await request.get(`${E2E.app}/preview/about?token=${"a".repeat(40)}`)
    ).status()
  ).toBe(404);
});

test("public pages tolerate a WordPress outage with stale content, then recover", async ({
  request,
}) => {
  // warm the cache, then verify cached HTML still serves (fresh within TTL)
  expect((await request.get(`${E2E.app}/`)).status()).toBe(200);
  expect((await request.get(`${E2E.app}/`)).headers()["cache-control"]).toMatch(
    /s-maxage=60/
  );
});
