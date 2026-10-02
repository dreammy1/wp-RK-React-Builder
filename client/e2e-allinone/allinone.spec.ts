import AxeBuilder from "@axe-core/playwright";
import { readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { expect, test, type Browser, type Page } from "@playwright/test";
import { AIO } from "../../playwright.allinone.config";
import { STATE_FILE } from "./global-setup";

/** Add a block from the side panel (opens the Blocks tab first). */
async function addBlock(page: Page, label: string) {
  await page.getByRole("tab", { name: "Blocks" }).click();
  await page.getByRole("button", { name: `Add ${label} block` }).click();
}

const state = () =>
  JSON.parse(readFileSync(STATE_FILE, "utf8")) as { pageId: number };
test.skip(
  !process.env.RK_E2E_ALLINONE && !process.env.CI,
  "needs network + Playground + a built plugin; set RK_E2E_ALLINONE=1"
);
test.describe.configure({ mode: "serial" });

async function login(page: Page, user: string, pass: string) {
  await page.goto("/wp-login.php");
  await page.locator("#user_login").fill(user);
  await page.locator("#user_pass").fill(pass);
  await page.locator("#wp-submit").click();
  await page.waitForURL(/wp-admin/);
}
const asRole = async (browser: Browser, user: string, pass: string) => {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await login(page, user, pass);
  return { ctx, page };
};
const builderUrl = (id?: number) =>
  `/wp-admin/admin.php?page=rk-builder${id ? `&page_id=${id}` : ""}`;
const names = (page: Page) =>
  page
    .locator(".canvas-block .handle-label")
    .allInnerTexts()
    .then(t => t.map(x => x.replace(/^\d+ \/ /, "").toLowerCase()));

test("1 · permissions: anonymous and subscriber are kept out; editor and admin get in", async ({
  browser,
  request,
}) => {
  expect(
    (await request.get(`${AIO.wp}/wp-json/rk/v1/builder/pages`)).status()
  ).toBe(401);
  const anon = await browser.newPage();
  await anon.goto(builderUrl());
  await expect(anon).toHaveURL(/wp-login\.php/);
  await anon.close();

  const sub = await asRole(browser, AIO.subscriber, AIO.subscriberPass);
  const res = await sub.page.goto(builderUrl());
  expect(res?.status()).toBeGreaterThanOrEqual(400); // WordPress refuses the screen (403)
  await expect(
    sub.page.locator("#rk-builder-root, #root").getByText("Choose a page")
  ).toHaveCount(0);
  expect(
    await sub.page.evaluate(
      () => (window as unknown as { RK_BUILDER_BOOT?: unknown }).RK_BUILDER_BOOT
    )
  ).toBeUndefined();
  await sub.ctx.close();

  const ed = await asRole(browser, AIO.editor, AIO.editorPass);
  await ed.page.goto(builderUrl());
  await expect(
    ed.page.getByRole("heading", { name: "Choose a page to edit" })
  ).toBeVisible();
  await ed.ctx.close();
});

test("2 · open RK Builder from wp-admin, select a page, load, edit, reorder (mouse + keyboard), save, reload", async ({
  page,
}) => {
  await login(page, AIO.admin, AIO.adminPass);
  await page.goto(builderUrl());
  const boot = await page.evaluate(
    () =>
      (
        window as unknown as {
          RK_BUILDER_BOOT: {
            mode: string;
            currentUser: { capabilities: { manageTheme: boolean } };
          };
        }
      ).RK_BUILDER_BOOT
  );
  expect(boot.mode).toBe("nonce");
  expect(boot.currentUser.capabilities.manageTheme).toBe(true);
  // assets come from the plugin itself, not from any other server
  const scripts = await page
    .locator("script[src]")
    .evaluateAll(els => els.map(e => (e as HTMLScriptElement).src));
  expect(
    scripts.some(
      s =>
        s.startsWith(AIO.wp) &&
        s.includes("/wp-content/plugins/rk-builder/assets/builder.js")
    )
  ).toBe(true);

  await page.getByLabel("Search pages").fill("smoke");
  await page.getByRole("link", { name: "Smoke Page" }).click();
  await expect(page).toHaveURL(new RegExp(`page_id=${state().pageId}$`));
  await expect(page.getByText("This page has no blocks yet")).toBeVisible();

  await addBlock(page, "Hero");
  await page.getByLabel("Headline").fill("All-in-one headline");
  await addBlock(page, "Divider");
  await addBlock(page, "Text");
  await page.getByLabel("Body copy").fill("Alpha <b>not bold</b>\n\nBeta");
  // keyboard reorder: text above divider
  await page.getByRole("button", { name: "Move Text up" }).focus();
  await page.keyboard.press("Enter");
  expect(await names(page)).toEqual(["hero", "text", "divider"]);
  // mouse drag reorder: drag divider to the top (tall viewport so the drag never needs to scroll)
  await page.setViewportSize({ width: 1280, height: 1100 });
  const handle = page.getByTestId("block-divider").locator(".block-handle");
  const firstZone = page.locator(".drop-zone").first();
  await handle.dragTo(firstZone);
  expect(await names(page)).toEqual(["divider", "hero", "text"]);
  await page.getByRole("button", { name: "Undo" }).first().click();
  expect(await names(page)).toEqual(["hero", "text", "divider"]);

  await page.getByRole("button", { name: "Save draft" }).click();
  await expect(page.getByTestId("save-status")).toHaveText(
    "Draft saved to WordPress"
  );
  await page.reload();
  await expect(page.getByTestId("block-hero")).toBeVisible();
  expect(await names(page)).toEqual(["hero", "text", "divider"]);
  await expect(page.getByTestId("block-hero")).toContainText(
    "All-in-one headline"
  );
});

test("3 · accessibility of the embedded builder", async ({ page }) => {
  await login(page, AIO.admin, AIO.adminPass);
  await page.goto(builderUrl(state().pageId));
  await expect(page.getByTestId("block-hero")).toBeVisible();
  const r = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"])
    .analyze();
  expect(
    r.violations.map(v => `${v.id}: ${v.nodes[0]?.target.join(" ")}`)
  ).toEqual([]);
});

test("4 · media picker selects from the real media library and stores the attachment", async ({
  page,
}) => {
  await login(page, AIO.admin, AIO.adminPass);
  await page.goto(builderUrl(state().pageId));
  await addBlock(page, "Image");
  await page.getByRole("button", { name: "Choose from media library" }).click();
  const dialog = page.getByRole("dialog", { name: "Choose an image" });
  await dialog.getByRole("button", { name: /Select Seed image/ }).click();
  await expect(page.getByRole("textbox", { name: "Alt text" })).toHaveValue(
    "Seed alt text"
  );
  await page.getByRole("button", { name: "Save draft" }).click();
  await expect(page.getByTestId("save-status")).toHaveText(
    "Draft saved to WordPress"
  );
});

test("5 · theme: administrators can save it, editors cannot", async ({
  page,
  browser,
}) => {
  await login(page, AIO.admin, AIO.adminPass);
  await page.goto(builderUrl(state().pageId));
  await page.getByRole("tab", { name: "Theme" }).click();
  await page.getByLabel("Type system").selectOption("Georgia");
  await page.getByRole("button", { name: "Save draft" }).click();
  await expect(page.getByTestId("save-status")).toHaveText(
    "Draft saved to WordPress"
  );

  const ed = await asRole(browser, AIO.editor, AIO.editorPass);
  await ed.page.goto(builderUrl(state().pageId));
  await ed.page.getByRole("tab", { name: "Theme" }).click();
  await expect(
    ed.page.getByText("Only administrators can change the global theme.")
  ).toBeVisible();
  await expect(ed.page.getByLabel("Type system")).toBeDisabled();
  await ed.ctx.close();
});

test("6 · draft saves never reach the public site; publish does; the page renders from PHP with SEO + no JS", async ({
  page,
  request,
}) => {
  await login(page, AIO.admin, AIO.adminPass);
  await page.goto(builderUrl(state().pageId));
  const link = `${AIO.wp}/smoke/`;
  expect((await request.get(link)).status(), "draft page is not public").toBe(
    404
  );

  await page.getByRole("button", { name: "Publish", exact: true }).click();
  await page.getByRole("button", { name: "Publish now" }).click();
  await expect(page.getByText(/Published revision/)).toBeVisible();

  const res = await request.get(link);
  expect(res.status()).toBe(200);
  const html = await res.text();
  expect(html).toContain('data-rk-block="hero"');
  expect(html).toContain("All-in-one headline");
  expect(html).toContain("Alpha &lt;b&gt;not bold&lt;/b&gt;"); // text is escaped, never HTML
  expect(html).toMatch(
    /<link rel=["']canonical["'] href=["']http:\/\/127\.0\.0\.1:9414\/smoke\/["']/
  );
  expect(html).toContain('property="og:title"');
  expect(html).not.toMatch(/rk-builder\/assets\/builder\.js/); // editor bundle never ships to visitors
  expect(html).toMatch(
    /<img[^>]+alt="Seed alt text"[^>]+width="\d+"[^>]+height="\d+"/
  );

  // later draft edits do not change the live page
  await page
    .getByTestId("block-hero")
    .getByRole("button", { name: /Select Hero/ })
    .click();
  await page.getByLabel("Headline").fill("Draft-only edit");
  await page.getByRole("button", { name: "Save draft" }).click();
  await expect(page.getByTestId("save-status")).toHaveText(
    "Draft saved to WordPress"
  );
  expect(await (await request.get(link)).text()).toContain(
    "All-in-one headline"
  );
  expect(await (await request.get(link)).text()).not.toContain(
    "Draft-only edit"
  );
});

test("7 · preview link: draft visible with a valid token, noindex + no-store, tampered token is a 404", async ({
  page,
  context,
  request,
}) => {
  await login(page, AIO.admin, AIO.adminPass);
  await page.goto(builderUrl(state().pageId));
  const popup = context.waitForEvent("page");
  await page.getByRole("button", { name: "Preview link" }).click();
  const preview = await popup;
  await preview.waitForLoadState();
  await expect(preview.getByText("Draft-only edit")).toBeVisible();
  expect(
    await preview.locator('meta[name="robots"]').getAttribute("content")
  ).toMatch(/noindex/);
  const url = preview.url();
  expect(url).toContain("rk_preview=1");
  const ok = await request.get(url);
  expect(ok.status()).toBe(200);
  expect(ok.headers()["cache-control"]).toMatch(/no-store/);
  expect(ok.headers()["x-robots-tag"]).toMatch(/noindex/);
  const tampered = await request.get(
    url.replace(/token=([^&]+)/, (_m, t) => `token=${t.slice(0, -2)}xx`)
  );
  expect(tampered.status()).toBe(404);
  expect(await tampered.text()).not.toContain("All-in-one headline");
});

test("8 · revisions: preview and restore create a new revision; conflicts are detected", async ({
  page,
  browser,
}) => {
  await login(page, AIO.admin, AIO.adminPass);
  await page.goto(builderUrl(state().pageId));
  await page.getByRole("button", { name: "History" }).click();
  const dialog = page.getByRole("dialog", { name: "Revision history" });
  await dialog.getByRole("button", { name: /Preview revision 1$/ }).click();
  await expect(dialog.getByLabel("Preview of revision 1")).toBeVisible();
  await dialog.getByRole("button", { name: /Restore revision 1$/ }).click();
  await expect(page.getByTestId("block-hero")).toBeVisible();

  // a second editor session saves first → the first session's save is a conflict
  const other = await asRole(browser, AIO.editor, AIO.editorPass);
  await other.page.goto(builderUrl(state().pageId));
  await addBlock(other.page, "Spacer");
  await other.page.getByRole("button", { name: "Save draft" }).click();
  await expect(other.page.getByTestId("save-status")).toHaveText(
    "Draft saved to WordPress"
  );
  await other.ctx.close();

  await addBlock(page, "Divider");
  await page.getByRole("button", { name: "Save draft" }).click();
  await expect(
    page.getByRole("dialog", { name: "This page changed on the server" })
  ).toBeVisible();
});

test("9 · unpublish returns the public page to 404", async ({
  page,
  request,
}) => {
  await login(page, AIO.admin, AIO.adminPass);
  await page.goto(builderUrl(state().pageId));
  await page.getByRole("button", { name: "Update live page" }).click();
  await page.getByRole("button", { name: "Unpublish" }).click();
  await expect
    .poll(async () => (await request.get(`${AIO.wp}/smoke/`)).status(), {
      timeout: 15_000,
    })
    .toBe(404);
});

const PNG_1X1 = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==",
  "base64"
);

/** fetch() inside the page so the WordPress cookie and the builder's REST nonce are used. */
const wpFetch = (
  page: Page,
  path: string,
  init?: {
    method?: string;
    json?: unknown;
    file?: { name: string; type: string; text?: string };
  }
) =>
  page.evaluate(
    async ({ path, init }) => {
      const boot = (
        window as unknown as {
          RK_BUILDER_BOOT: { apiBase: string; nonce: string };
        }
      ).RK_BUILDER_BOOT;
      const headers: Record<string, string> = { "X-WP-Nonce": boot.nonce };
      let body: BodyInit | undefined;
      if (init?.file) {
        const f = new FormData();
        f.append(
          "file",
          new Blob([init.file.text ?? ""], { type: init.file.type }),
          init.file.name
        );
        body = f;
      } else if (init?.json !== undefined) {
        headers["Content-Type"] = "application/json";
        body = JSON.stringify(init.json);
      }
      const r = await fetch(boot.apiBase + path, {
        method: init?.method ?? "GET",
        headers,
        body,
        credentials: "include",
      });
      return { status: r.status, json: await r.json().catch(() => null) };
    },
    { path, init }
  );

test("10 · media upload: add an image from the editor; non-images and SVG are refused", async ({
  page,
}) => {
  await login(page, AIO.admin, AIO.adminPass);
  await page.goto(builderUrl(state().pageId));
  await addBlock(page, "Image");
  await page.getByRole("button", { name: "Choose from media library" }).click();
  await page.getByLabel("Alt text for a new upload").fill("A tiny upload");
  await page.locator('.media-upload input[type="file"]').setInputFiles({
    name: "Tiny Upload.png",
    mimeType: "image/png",
    buffer: PNG_1X1,
  });
  // the picker closes and the block now shows the uploaded attachment
  await expect(
    page.getByRole("heading", { name: "Choose an image" })
  ).toBeHidden({ timeout: 20_000 });
  await expect(
    page.locator('.canvas-block img[src*="/wp-content/uploads/"]').first()
  ).toBeVisible();
  await expect(page.getByLabel("Alt text", { exact: true })).toHaveValue(
    "A tiny upload"
  );

  // The server decides by content, not by name or declared type.
  const svg = await wpFetch(page, "builder/media", {
    method: "POST",
    file: {
      name: "x.svg",
      type: "image/svg+xml",
      text: '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    },
  });
  expect(svg.status).toBe(415);
  expect((svg.json as { code: string }).code).toBe("rk_invalid_media");
  const fake = await wpFetch(page, "builder/media", {
    method: "POST",
    file: { name: "evil.png", type: "image/png", text: "<?php echo 1;" },
  });
  expect(fake.status).toBe(415);
  const none = await wpFetch(page, "builder/media", {
    method: "POST",
    json: {},
  });
  expect(none.status).toBe(400);
});

test("11 · site export, then import: drafts only, media re-used, stale attachment ids dropped", async ({
  page,
}, testInfo) => {
  await login(page, AIO.admin, AIO.adminPass);
  await page.goto(builderUrl());
  const [download] = await Promise.all([
    page.waitForEvent("download"),
    page.getByRole("button", { name: "Export site" }).click(),
  ]);
  const bundle = JSON.parse(readFileSync(await download.path(), "utf8"));
  expect(bundle.format).toBe("rk-builder-site");
  expect(bundle.version).toBe(1);
  expect(bundle.pages.some((p: { slug: string }) => p.slug === "smoke")).toBe(
    true
  );
  expect(Array.isArray(bundle.media)).toBe(true);

  // Make it look like it came from another site: one page, one image already known here by its source URL.
  const seed = state() as unknown as { mediaId: number };
  const imported = {
    ...bundle,
    source: { url: "https://old.example.com/" },
    theme: null,
    content: [],
    media: [
      {
        id: 4242,
        url: "https://assets.example.com/seed.png",
        alt: "Seed alt text",
        title: "Seed image",
      },
    ],
    pages: [
      {
        slug: "imported-copy",
        title: "Imported Copy",
        wasPublished: true,
        layout: {
          version: 1,
          blocks: [
            {
              id: "hero-1",
              type: "hero",
              props: {
                heading: "From another site",
                sub: "",
                cta: "",
                ctaHref: "",
                bgMediaId: 4242,
                bgUrl: "https://assets.example.com/seed.png",
              },
            },
            {
              id: "img-1",
              type: "image",
              props: {
                mediaId: 4242,
                url: "https://assets.example.com/seed.png",
                alt: "Seed",
                decorative: false,
              },
            },
            {
              id: "img-2",
              type: "image",
              props: {
                mediaId: 999999,
                url: "/placeholder.svg",
                alt: "Gone",
                decorative: false,
              },
            },
          ],
        },
      },
      {
        slug: "broken",
        title: "Broken",
        layout: { version: 1, blocks: [{ id: "x", type: "nope", props: {} }] },
      },
    ],
  };
  const file = join(tmpdir(), `rk-import-${testInfo.workerIndex}.json`);
  writeFileSync(file, JSON.stringify(imported));

  await page.getByRole("button", { name: "Import site" }).click();
  await page.getByLabel("Export file (.json)").setInputFiles(file);
  await page.getByRole("button", { name: "Check file" }).click();
  const dialog = page.getByRole("dialog");
  await expect(dialog.getByText("This is what will happen")).toBeVisible({
    timeout: 20_000,
  });
  await expect(dialog.getByText(/skipped/)).toBeVisible(); // the invalid page is reported, not imported
  // a dry run writes nothing
  const before = await wpFetch(page, "builder/pages?search=imported");
  expect((before.json as { total: number }).total).toBe(0);

  await dialog.getByRole("button", { name: "Import now" }).click();
  await expect(dialog.getByText("Import finished")).toBeVisible({
    timeout: 60_000,
  });

  const list = await wpFetch(page, "builder/pages?search=imported");
  const pages = (list.json as { pages: { id: number; status: string }[] })
    .pages;
  expect(pages).toHaveLength(1);
  expect(pages[0]!.status).toBe("draft"); // never published, even though the source page was
  const loaded = await wpFetch(page, `builder/layout/${pages[0]!.id}`);
  const blocks = (
    loaded.json as { layout: { blocks: { props: Record<string, unknown> }[] } }
  ).layout.blocks;
  expect(blocks[0]!.props.bgMediaId).toBe(seed.mediaId); // re-mapped to this site's attachment
  expect(String(blocks[0]!.props.bgUrl)).toContain("/wp-content/uploads/");
  expect(blocks[1]!.props.mediaId).toBe(seed.mediaId);
  // an attachment id from the other site that has no copy here is dropped, never trusted
  expect(blocks[2]!.props.mediaId).toBeUndefined();
  expect(blocks[2]!.props.url).toBe("/placeholder.svg");
});

test("12 · re-importing updates by slug (no duplicates); editors cannot import or export", async ({
  page,
  browser,
}) => {
  await login(page, AIO.admin, AIO.adminPass);
  await page.goto(builderUrl());
  const [download] = await Promise.all([
    page.waitForEvent("download"),
    page.getByRole("button", { name: "Export site" }).click(),
  ]);
  const bundle = JSON.parse(readFileSync(await download.path(), "utf8"));
  const slugs = bundle.pages.map((p: { slug: string }) => p.slug);
  expect(slugs).toContain("imported-copy");
  const again = await wpFetch(page, "builder/site-import", {
    method: "POST",
    json: {
      bundle,
      options: {
        dryRun: false,
        theme: false,
        content: false,
        contentStatus: "draft",
      },
    },
  });
  expect(again.status).toBe(200);
  const report = again.json as { pages: { create: number; update: number } };
  expect(report.pages.create).toBe(0);
  expect(report.pages.update).toBeGreaterThanOrEqual(1);
  const all = await wpFetch(page, "builder/pages?search=imported");
  expect((all.json as { total: number }).total).toBe(1);

  const ed = await asRole(browser, AIO.editor, AIO.editorPass);
  await ed.page.goto(builderUrl());
  await expect(
    ed.page.getByRole("heading", { name: "Choose a page to edit" })
  ).toBeVisible();
  await expect(
    ed.page.getByRole("button", { name: "Export site" })
  ).toHaveCount(0);
  expect((await wpFetch(ed.page, "builder/site-export")).status).toBe(403);
  expect(
    (
      await wpFetch(ed.page, "builder/site-import", {
        method: "POST",
        json: { bundle },
      })
    ).status
  ).toBe(403);
  await ed.ctx.close();
});
