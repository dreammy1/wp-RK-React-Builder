import { writeFileSync } from "node:fs";
import { join, resolve } from "node:path";
import { tmpdir } from "node:os";
// @ts-expect-error plain ESM helper without types
import { startPlayground } from "../../scripts/lib/playground.mjs";

export const STATE_FILE = join(tmpdir(), "rk-aio-e2e-state.json");

export default async function globalSetup() {
  // The staged plugin is exactly the ZIP contents: PHP + prebuilt assets, nothing from the source tree.
  const pg = await startPlayground({
    port: 9414,
    pluginDir: resolve(import.meta.dirname, "../../dist/plugin/rk-builder"),
  });
  writeFileSync(
    STATE_FILE,
    JSON.stringify({ pageId: pg.creds.pageId, mediaId: pg.creds.mediaId })
  );
  return async () => pg.stop();
}
