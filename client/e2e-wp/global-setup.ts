import { writeFileSync } from "node:fs";
import { join } from "node:path";
import { tmpdir } from "node:os";
// @ts-expect-error plain ESM helper without types
import { startPlayground } from "../../scripts/lib/playground.mjs";
import { WPE2E } from "../../playwright.wp.config";

export const STATE_FILE = join(tmpdir(), "rk-wp-e2e-state.json");

export default async function globalSetup() {
  const pg = await startPlayground({
    port: 9413,
    revalidate: { url: `${WPE2E.app}/api/revalidate`, secret: WPE2E.secret },
    constants: {
      RK_BUILDER_APP_URL: `${WPE2E.app}/embed/rk-builder.js`,
      RK_BUILDER_FRONTEND_URL: WPE2E.app,
    },
  });
  (globalThis as Record<string, unknown>).__rkPlayground = pg;
  writeFileSync(
    STATE_FILE,
    JSON.stringify({ pageId: pg.creds.pageId, editor: pg.creds.editor })
  );
  return async () => pg.stop();
}
