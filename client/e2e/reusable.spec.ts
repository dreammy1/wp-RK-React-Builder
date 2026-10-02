import { expect, test } from "@playwright/test";
import { E2E } from "../../playwright.config";
import { openEditor, resetBackend, saveDraft, addBlock } from "./helpers";

test.beforeEach(async ({ request }) => resetBackend(request));

const wpAuth = {
  Authorization:
    "Basic " +
    Buffer.from(`${E2E.wpUser}:${E2E.wpPassword}`).toString("base64"),
};

test("reusable blocks: save once, reuse on another page, edit everywhere, detach, and the public site follows", async ({
  page,
  request,
}) => {
  // 1 · build a CTA on About and move it into the library
  await openEditor(page, 43);
  await addBlock(page, "CTA banner");
  await page.getByLabel("Heading", { exact: true }).fill("Ready for floors?");
  await page.getByRole("button", { name: "Save as reusable block" }).click();
  await page.getByLabel("Name in the library").fill("Footer CTA");
  await page.getByRole("button", { name: "Save to library" }).click();
  await expect(page.getByText("Reusable · Footer CTA")).toBeVisible();
  await page.getByRole("tab", { name: "Blocks" }).click();
  await expect(
    page.getByRole("button", { name: "Add reusable Footer CTA" })
  ).toBeVisible();
  await saveDraft(page);

  // 2 · reuse it on Contact (a different page)
  await page.goto("/builder?page=44");
  await expect(page.getByTestId("save-status")).toBeVisible();
  await page.getByRole("tab", { name: "Blocks" }).click();
  await page.getByRole("button", { name: "Add reusable Footer CTA" }).click();
  await expect(page.getByText("Ready for floors?").first()).toBeVisible();
  await saveDraft(page);

  // 3 · edit the shared block once
  await page
    .getByLabel("Heading", { exact: true })
    .fill("Book your free estimate");
  await page.getByRole("button", { name: "Update everywhere" }).click();
  await expect(page.getByText("Updated everywhere.")).toBeVisible();

  await page.goto("/builder?page=43");
  await expect(page.getByTestId("save-status")).toBeVisible();
  await expect(page.getByText("Book your free estimate").first()).toBeVisible();

  // 4 · detach on About: it becomes an ordinary block that no longer follows the library
  await page.locator(".canvas-block").first().click();
  await page.getByRole("button", { name: /Detach/ }).click();
  await expect(page.getByText("Reusable · Footer CTA")).toHaveCount(0);
  await page.getByLabel("Heading", { exact: true }).fill("About-only heading");
  await saveDraft(page);

  // 5 · the public site renders the linked block for Contact (published straight in WordPress)
  const cur = await (
    await request.get(`${E2E.wp}/wp-json/rk/v1/builder/layout/44`, {
      headers: wpAuth,
    })
  ).json();
  const pub = await request.post(`${E2E.wp}/wp-json/rk/v1/builder/publish/44`, {
    headers: wpAuth,
    data: { expectedRevision: cur.revision },
  });
  expect(pub.status()).toBe(200);
  await expect
    .poll(async () => (await request.get(`${E2E.app}/contact`)).text(), {
      timeout: 15_000,
    })
    .toContain("Book your free estimate");
  const html = await (await request.get(`${E2E.app}/contact`)).text();
  expect(html).not.toContain("About-only heading");
});
