import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";
import { E2E } from "../../playwright.config";
import { openEditor, resetBackend, signIn } from "./helpers";

test.beforeEach(async ({ request }) => resetBackend(request));

const scan = async (page: Page, label: string) => {
  const results = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"])
    .analyze();
  const summary = results.violations.map(
    v =>
      `${v.id} (${v.impact}): ${v.nodes
        .slice(0, 3)
        .map(n => n.target.join(" "))
        .join(" | ")}`
  );
  expect(summary, `${label} axe violations`).toEqual([]);
};

test("sign-in screen", async ({ page }) => {
  await page.goto("/builder");
  await scan(page, "login");
});
test("page selector", async ({ page }) => {
  await signIn(page);
  await expect(page.getByRole("link", { name: "Home" })).toBeVisible();
  await scan(page, "selector");
});
test("editor, inspector and theme panel", async ({ page }) => {
  await openEditor(page, 42);
  await expect(
    page
      .getByTestId("block-services")
      .getByRole("heading", { name: "Residential Wiring" })
  ).toBeVisible();
  await scan(page, "editor");
  await page.getByRole("tab", { name: "Theme" }).click();
  await scan(page, "theme panel");
});
test("dialogs trap focus, label themselves, close on Escape and restore focus", async ({
  page,
}) => {
  await openEditor(page, 42);
  const opener = page.getByRole("button", { name: "Export" });
  await opener.focus();
  await page.keyboard.press("Enter");
  const dialog = page.getByRole("dialog", { name: "Document payload" });
  await expect(dialog).toBeVisible();
  await expect(dialog).toHaveAttribute("aria-modal", "true");
  await scan(page, "export dialog");
  for (let i = 0; i < 6; i++) {
    await page.keyboard.press("Tab");
    expect(await dialog.evaluate(d => d.contains(document.activeElement))).toBe(
      true
    );
  }
  await page.keyboard.press("Shift+Tab");
  expect(await dialog.evaluate(d => d.contains(document.activeElement))).toBe(
    true
  );
  await page.keyboard.press("Escape");
  await expect(dialog).toHaveCount(0);
  await expect(opener).toBeFocused();
});
test("media picker is searchable and keyboard-operable", async ({ page }) => {
  await openEditor(page, 43);
  await page.getByRole("button", { name: "Add Image block" }).click();
  await page.getByRole("button", { name: "Choose from media library" }).click();
  const dialog = page.getByRole("dialog", { name: "Choose an image" });
  await scan(page, "media picker");
  await dialog.getByLabel("Search media library").fill("crew");
  await expect(dialog.getByRole("button", { name: /Select/ })).toHaveCount(1);
  await dialog.getByRole("button", { name: "Select Crew on site" }).focus();
  await page.keyboard.press("Enter");
  await expect(page.getByRole("textbox", { name: "Alt text" })).toHaveValue(
    "Crew installing panels"
  );
  await expect(page.locator(".canvas-block img")).toHaveAttribute(
    "src",
    /\/media\/501\.svg$/
  );
});
test("save status is announced through a polite live region", async ({
  page,
}) => {
  await openEditor(page, 43);
  const live = page.getByTestId("save-status");
  await expect(live).toHaveAttribute("aria-live", "polite");
  await expect(live).toHaveAttribute("role", "status");
});
test("focus is always visible on interactive controls", async ({ page }) => {
  await openEditor(page, 42);
  await page.getByRole("button", { name: "Add Spacer block" }).focus();
  const outline = await page
    .getByRole("button", { name: "Add Spacer block" })
    .evaluate(el => getComputedStyle(el).outlineStyle);
  expect(outline).not.toBe("none");
});
test("reduced motion disables transitions and animations", async ({
  page,
  request,
}) => {
  await page.emulateMedia({ reducedMotion: "reduce" });
  await page.goto(`${E2E.app}/`);
  const dur = await page
    .locator(".site-btn")
    .first()
    .evaluate(el => getComputedStyle(el).transitionDuration);
  expect(["0s", ""]).toContain(dur);
  expect((await request.get(`${E2E.app}/`)).status()).toBe(200);
});
test("public page passes automated accessibility checks", async ({ page }) => {
  await page.goto(`${E2E.app}/`);
  await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
  await scan(page, "public page");
});
