import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";
import { E2E } from "../../playwright.config";
import { openEditor, resetBackend, signIn, addBlock } from "./helpers";

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
  await addBlock(page, "Image");
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
test("gallery: pick several photos from the media library, caption, reorder, set options", async ({
  page,
}) => {
  await openEditor(page, 43);
  await addBlock(page, "Photo gallery");
  await expect(page.getByText("No photos yet")).toBeVisible();
  await page.getByRole("button", { name: "Add photos" }).click();
  const dialog = page.getByRole("dialog", { name: "Choose photos" });
  await scan(page, "gallery picker");
  await dialog.getByRole("button", { name: "Select Switchboard" }).click();
  await dialog.getByRole("button", { name: "Select Crew on site" }).click();
  await expect(dialog.getByText("2 selected")).toBeVisible();
  await dialog.getByRole("button", { name: "Add 2 photos" }).click();
  await expect(dialog).toHaveCount(0);

  // they arrive in the order picked, with the alt text (else the title) as caption
  await expect(page.locator(".gi-row")).toHaveCount(2);
  await expect(page.getByLabel("Photo 1 caption and alt text")).toHaveValue(
    "Switchboard"
  );
  await expect(page.getByLabel("Photo 2 caption and alt text")).toHaveValue(
    "Crew installing panels"
  );
  await expect(page.locator(".canvas-block .pf-gallery img")).toHaveCount(2);

  // category, reorder
  await page.getByLabel("Photo 2 category").fill("Installation");
  await page.getByRole("button", { name: "Move photo 2 up" }).click();
  await expect(page.getByLabel("Photo 1 caption and alt text")).toHaveValue(
    "Crew installing panels"
  );
  await expect(page.getByLabel("Photo 1 category")).toHaveValue("Installation");

  // options change the canvas
  await page.getByLabel("Columns").selectOption("4");
  await page.getByLabel("Photo shape").selectOption("square");
  await page.getByLabel("Space between photos").selectOption("lg");
  await page.getByLabel("Captions").selectOption("below");
  await page.getByLabel("Open a photo large when clicked").check();
  await page.getByLabel("Show the first photo large").uncheck();
  const section = page.locator(".canvas-block .pf-gallery");
  await expect(section).toHaveClass(/cols-4/);
  await expect(section).toHaveClass(/shape-square/);
  await expect(section).toHaveClass(/gap-lg/);
  await expect(section).toHaveClass(/cap-below/);
  await expect(section).toHaveClass(/has-lightbox/);
  await expect(section).toHaveClass(/no-feature/);
  await expect(
    page.locator(".canvas-block .pf-gallery figure.big")
  ).toHaveCount(0);

  // remove one photo, then all
  await page.getByRole("button", { name: "Remove photo 2" }).click();
  await expect(page.locator(".gi-row")).toHaveCount(1);
  page.once("dialog", d => void d.accept());
  await page.getByRole("button", { name: "Remove all" }).click();
  await expect(page.locator(".gi-row")).toHaveCount(0);
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
