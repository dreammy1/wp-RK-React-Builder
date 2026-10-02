import { expect, type APIRequestContext, type Page } from "@playwright/test";
import { E2E } from "../../playwright.config";

export async function resetBackend(request: APIRequestContext) {
  await request.post(`${E2E.wp}/__reset`);
  await request.post(`${E2E.app}/api/revalidate`, {
    headers: { "x-rk-revalidate-secret": E2E.revalidateSecret },
    data: { type: "theme" },
  });
}

export async function signIn(page: Page, password = E2E.editorPassword) {
  await page.goto("/builder");
  await page.getByLabel("Editor password").fill(password);
  await page.getByRole("button", { name: "Sign in" }).click();
}

export async function signInAndWait(page: Page) {
  await signIn(page);
  await expect(
    page.getByRole("heading", { name: "Choose a page to edit" })
  ).toBeVisible();
}

export async function openEditor(page: Page, id: number, query = "") {
  await signInAndWait(page);
  await page.goto(`/builder?page=${id}${query}`);
  await expect(page.getByTestId("save-status")).toBeVisible();
}

export const saveDraft = async (page: Page) => {
  await page.getByRole("button", { name: "Save draft" }).click();
  await expect(page.getByTestId("save-status")).toHaveText(
    "Draft saved to WordPress"
  );
};

/** Direct WordPress write that simulates another editor. */
export async function bumpRevisionElsewhere(
  request: APIRequestContext,
  id: number
) {
  const auth =
    "Basic " +
    Buffer.from(`${E2E.wpUser}:${E2E.wpPassword}`).toString("base64");
  const cur = await (
    await request.get(`${E2E.wp}/wp-json/rk/v1/builder/layout/${id}`, {
      headers: { Authorization: auth },
    })
  ).json();
  const res = await request.post(
    `${E2E.wp}/wp-json/rk/v1/builder/layout/${id}`,
    {
      headers: { Authorization: auth },
      data: {
        layout: {
          version: 1,
          blocks: [{ id: "other-1", type: "spacer", props: { h: 99 } }],
        },
        expectedRevision: cur.revision,
        status: "draft",
      },
    }
  );
  expect(res.ok()).toBe(true);
}

/** Add a block from the side panel (opens the Blocks tab first). */
export async function addBlock(page: Page, label: string) {
  await page.getByRole("tab", { name: "Blocks" }).click();
  await page.getByRole("button", { name: `Add ${label} block` }).click();
}
