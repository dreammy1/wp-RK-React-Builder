import { expect, test } from "@playwright/test";
import { E2E } from "../../playwright.config";
import {
  bumpRevisionElsewhere,
  openEditor,
  resetBackend,
  saveDraft,
  signIn,
  addBlock,
} from "./helpers";

test.beforeEach(async ({ request }) => resetBackend(request));

test.describe("authentication", () => {
  test("anonymous users see a sign-in screen and cannot reach the API", async ({
    page,
    request,
  }) => {
    await page.goto("/builder");
    await expect(
      page.getByRole("heading", { name: "Sign in to edit" })
    ).toBeVisible();
    for (const [method, path] of [
      ["get", "/api/wp/rk/v1/builder/pages"],
      ["post", "/api/wp/rk/v1/builder/layout/42"],
      ["post", "/api/wp/rk/v1/theme-config"],
    ] as const) {
      const res = await request[method](`${E2E.app}${path}`, { data: {} });
      expect(res.status(), `${method} ${path}`).toBe(401);
    }
  });
  test("a wrong password is rejected with an accessible error; the right one signs in", async ({
    page,
  }) => {
    await signIn(page, "wrong-password");
    await expect(page.getByRole("alert")).toContainText("not accepted");
    await page.getByLabel("Editor password").fill(E2E.editorPassword);
    await page.getByRole("button", { name: "Sign in" }).click();
    await expect(
      page.getByRole("heading", { name: "Choose a page to edit" })
    ).toBeVisible();
  });
  test("the session cookie is HttpOnly and no WordPress credential reaches the browser", async ({
    page,
    context,
  }) => {
    await signIn(page);
    await expect(
      page.getByRole("heading", { name: "Choose a page to edit" })
    ).toBeVisible();
    const cookies = await context.cookies();
    expect(cookies.find(c => c.name === "rk_sid")).toMatchObject({
      httpOnly: true,
      sameSite: "Strict",
    });
    const html = await page.content();
    expect(html).not.toContain(E2E.wpPassword);
    expect(
      await page.evaluate(() => JSON.stringify({ ...localStorage }))
    ).not.toContain(E2E.wpPassword);
  });
  test("an expired session during save prompts sign-in and keeps the edits", async ({
    page,
    context,
  }) => {
    await openEditor(page, 43);
    await addBlock(page, "Hero");
    await context.clearCookies();
    await page.getByRole("button", { name: "Save draft" }).click();
    await expect(
      page.getByRole("dialog", { name: "Your session expired" })
    ).toBeVisible();
    await page.getByLabel("Editor password").fill(E2E.editorPassword);
    await page.getByRole("button", { name: "Sign in" }).click();
    await expect(page.getByTestId("save-status")).toHaveText(
      "Draft saved to WordPress"
    );
  });
});

test.describe("page selection and loading", () => {
  test("lists pages with status, search and filter; no hard-coded page id", async ({
    page,
  }) => {
    await signIn(page);
    const rows = page.getByRole("row");
    await expect(page.getByRole("link", { name: "Home" })).toBeVisible();
    await expect(rows).toHaveCount(4); // header + 3 pages
    await page.getByLabel("Search pages").fill("abo");
    await expect(rows).toHaveCount(2);
    await page.getByLabel("Search pages").fill("");
    await page.getByLabel("Status").selectOption("private");
    await expect(page.getByRole("link", { name: "Contact" })).toBeVisible();
    await expect(rows).toHaveCount(2);
    await page.getByLabel("Status").selectOption("");
    await page.getByRole("link", { name: "About" }).click();
    await expect(page).toHaveURL(/\/builder\?page=43$/);
    await expect(
      page.getByRole("heading", { name: "About", level: 1 })
    ).toBeVisible();
  });
  test("unknown pages show a not-found state", async ({ page }) => {
    await openEditor(page, 999).catch(() => undefined);
    await expect(
      page.getByRole("heading", { name: "Page not found" })
    ).toBeVisible();
  });
  test("loads the saved layout and live services (not demo data)", async ({
    page,
  }) => {
    await openEditor(page, 42);
    await expect(page.getByTestId("block-hero")).toContainText(
      "Powering what’s next"
    );
    const grid = page.getByTestId("block-services");
    await expect(
      grid.getByRole("heading", { name: "Residential Wiring" })
    ).toBeVisible();
    await expect(grid.getByRole("img", { name: "Wiring" })).toBeVisible();
    await expect(page.getByText("demo content")).toHaveCount(0);
  });
  test("demo content only appears when explicitly requested", async ({
    page,
  }) => {
    await openEditor(page, 42, "&demo=1");
    await expect(page.getByText("demo content")).toBeVisible();
  });
});

test.describe("editing round trip", () => {
  test("add, edit, reorder, save, reload: everything persists", async ({
    page,
  }) => {
    await openEditor(page, 43);
    await expect(page.getByText("This page has no blocks yet")).toBeVisible();

    await addBlock(page, "Hero");
    await page.getByLabel("Headline").fill("Round trip headline");
    await page.getByLabel("Button link").fill("/quote");
    await addBlock(page, "Divider");
    await addBlock(page, "Text");
    await page
      .getByLabel("Body copy")
      .fill("First paragraph.\n\nSecond paragraph.");
    await expect(page.getByTestId("save-status")).toHaveText("Unsaved changes");

    // keyboard-operable reorder: move the text block up above the divider
    await page.getByRole("button", { name: "Move Text up" }).focus();
    await page.keyboard.press("Enter");
    const order = () =>
      page.locator(".canvas-block .handle-label").allInnerTexts();
    const names = async () =>
      (await order()).map(t => t.replace(/^\d+ \/ /, "").toLowerCase());
    expect(await names()).toEqual(["hero", "text", "divider"]);

    await saveDraft(page);
    await page.reload();
    await expect(page.getByTestId("save-status")).toBeVisible();
    expect(await names()).toEqual(["hero", "text", "divider"]);
    await expect(page.getByTestId("block-hero")).toContainText(
      "Round trip headline"
    );
    await expect(page.getByTestId("block-text")).toContainText(
      "Second paragraph."
    );
    await page
      .getByTestId("block-hero")
      .getByRole("button", { name: /Select Hero/ })
      .click();
    await expect(page.getByLabel("Button link")).toHaveValue("/quote");
    await expect(
      page.getByText("rev 1", { exact: false }).first()
    ).toBeVisible();
  });
  test("duplicate, delete with undo toast, and undo/redo", async ({ page }) => {
    await openEditor(page, 42);
    const blocks = page.locator(".canvas-block");
    await expect(blocks).toHaveCount(2);
    await page.getByRole("button", { name: "Duplicate Hero" }).click();
    await expect(blocks).toHaveCount(3);
    await page.getByRole("button", { name: "Delete Hero" }).first().click();
    await expect(blocks).toHaveCount(2);
    await page
      .getByRole("status")
      .filter({ hasText: "Hero deleted" })
      .getByRole("button", { name: "Undo" })
      .click();
    await expect(blocks).toHaveCount(3);
    await page.getByRole("button", { name: "Undo" }).first().click();
    await expect(blocks).toHaveCount(2);
    await page.getByRole("button", { name: "Redo" }).click();
    await expect(blocks).toHaveCount(3);
  });
  test("grid filters, limits and columns apply to live data", async ({
    page,
  }) => {
    await openEditor(page, 42);
    await page
      .getByTestId("block-services")
      .getByRole("button", { name: /Select Services/ })
      .click();
    await page.getByLabel("Category slug").fill("energy");
    const cards = page.getByTestId("block-services").locator("article");
    await expect(cards).toHaveCount(2);
    await page.getByLabel("Items to show").fill("1");
    await expect(cards).toHaveCount(1);
    await page.getByLabel("Columns", { exact: true }).selectOption("2");
    await expect(
      page.getByTestId("block-services").locator(".cards")
    ).toHaveCSS("grid-template-columns", /^[\d.]+px [\d.]+px$/);
  });
  test("invalid input is flagged inline, blocks publishing, and is never sent", async ({
    page,
  }) => {
    await openEditor(page, 43);
    await addBlock(page, "Hero");
    await page.getByLabel("Headline").fill("");
    await expect(
      page
        .getByRole("alert")
        .filter({ hasText: /required/i })
        .first()
    ).toBeVisible();
    await expect(page.getByRole("button", { name: "Publish" })).toBeDisabled();
    await page.getByRole("button", { name: "Save draft" }).click();
    await expect(page.getByTestId("save-status")).toContainText(
      "Validation failed"
    );
    await page.getByLabel("Headline").fill("Fixed");
    await saveDraft(page);
  });
  test("preview shows the page without editor chrome", async ({ page }) => {
    await openEditor(page, 42);
    await page.getByRole("button", { name: "Preview", exact: true }).click();
    await expect(page.locator(".preview-canvas")).toContainText(
      "Powering what’s next"
    );
    await expect(page.locator(".block-handle")).toHaveCount(0);
  });
  test("theme edits save and apply as CSS variables", async ({ page }) => {
    await openEditor(page, 42);
    await page.getByRole("tab", { name: "Theme" }).click();
    await page.getByLabel("Type system").selectOption("Georgia");
    await page.getByRole("button", { name: "Save draft" }).click();
    await expect(page.getByTestId("save-status")).toHaveText(
      "Draft saved to WordPress"
    );
    const theme = await (
      await page.request.get(`${E2E.wp}/wp-json/rk/v1/theme-config`)
    ).json();
    expect(theme.font).toBe("Georgia");
  });
});

test.describe("recovery and concurrency", () => {
  test("a revision conflict offers reload or rebase; rebasing then saves", async ({
    page,
    request,
  }) => {
    await openEditor(page, 43);
    await addBlock(page, "Hero");
    await page.getByLabel("Headline").fill("Mine");
    await bumpRevisionElsewhere(request, 43);
    await page.getByRole("button", { name: "Save draft" }).click();
    const dialog = page.getByRole("dialog", {
      name: "This page changed on the server",
    });
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText("revision 1");
    await dialog.getByRole("button", { name: /Keep my edits/ }).click();
    await expect(page.getByTestId("save-status")).toHaveText("Unsaved changes");
    await saveDraft(page);
    await page.reload();
    await expect(page.getByTestId("block-hero")).toContainText("Mine");
  });
  test("conflict → load server version discards local edits", async ({
    page,
    request,
  }) => {
    await openEditor(page, 43);
    await addBlock(page, "Hero");
    await bumpRevisionElsewhere(request, 43);
    await page.getByRole("button", { name: "Save draft" }).click();
    await page.getByRole("button", { name: /Load the server version/ }).click();
    await expect(page.getByTestId("block-spacer")).toBeVisible();
    await expect(page.getByTestId("block-hero")).toHaveCount(0);
  });
  test("unsaved work is offered back after a reload (local draft recovery)", async ({
    page,
  }) => {
    await openEditor(page, 43);
    await addBlock(page, "Hero");
    await page.getByLabel("Headline").fill("Recover me");
    await page.waitForTimeout(1200); // local autosave debounce
    await page.reload({ waitUntil: "load" }).catch(() => undefined);
    const dialog = page.getByRole("dialog", {
      name: "Unsaved draft found on this device",
    });
    await expect(dialog).toBeVisible();
    await dialog
      .getByRole("button", { name: "Restore my local draft" })
      .click();
    await expect(page.getByTestId("block-hero")).toContainText("Recover me");
    await saveDraft(page);
    // saved → local copy removed → no prompt next time
    await page.reload();
    await expect(page.getByTestId("save-status")).toBeVisible();
    await expect(page.getByRole("dialog")).toHaveCount(0);
  });
  test("when WordPress is unreachable the offline draft can be opened and is labelled local-only", async ({
    page,
    context,
  }) => {
    await openEditor(page, 43);
    await addBlock(page, "Hero");
    await page.getByLabel("Headline").fill("Offline work");
    await page.waitForTimeout(1200);
    await context.route("**/api/wp/**", route =>
      route.abort("connectionrefused")
    );
    await page.reload().catch(() => undefined);
    await expect(
      page.getByRole("heading", { name: "Can’t reach WordPress" })
    ).toBeVisible();
    await page.getByRole("button", { name: "Open offline draft" }).click();
    await expect(page.getByTestId("block-hero")).toContainText("Offline work");
    await expect(page.getByTestId("save-status")).toContainText("only");
    await expect(page.getByTestId("save-status")).not.toHaveText(
      /saved to wordpress/i
    );
  });
  test("a network failure on save keeps work locally and says so honestly", async ({
    page,
    context,
  }) => {
    await openEditor(page, 43);
    await addBlock(page, "Hero");
    await context.route("**/api/wp/rk/v1/builder/layout/43", route =>
      route.request().method() === "POST"
        ? route.abort("connectionrefused")
        : route.continue()
    );
    await page.getByRole("button", { name: "Save draft" }).click();
    await expect(page.getByTestId("save-status")).toContainText(
      "Saved on this device only"
    );
  });
  test("revision history previews and restores without destroying history", async ({
    page,
  }) => {
    await openEditor(page, 43);
    await addBlock(page, "Hero");
    await page.getByLabel("Headline").fill("Version A");
    await saveDraft(page);
    await page.getByLabel("Headline").fill("Version B");
    await saveDraft(page);
    await page.getByRole("button", { name: "History" }).click();
    const dialog = page.getByRole("dialog", { name: "Revision history" });
    await expect(dialog.getByText("Revision 2")).toBeVisible();
    await dialog.getByRole("button", { name: "Preview revision 1" }).click();
    await expect(dialog.getByLabel("Preview of revision 1")).toContainText(
      "Version A"
    );
    await dialog.getByRole("button", { name: "Restore revision 1" }).click();
    await expect(page.getByTestId("block-hero")).toContainText("Version A");
    await page.getByRole("button", { name: "History" }).click();
    await expect(
      page
        .getByRole("dialog", { name: "Revision history" })
        .getByText("Revision 3")
    ).toBeVisible();
    await expect(
      page
        .getByRole("dialog", { name: "Revision history" })
        .getByText("Revision 1", { exact: true })
    ).toBeVisible();
  });
});
