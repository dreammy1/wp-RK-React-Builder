import react from "@vitejs/plugin-react";
import { copyFileSync, mkdirSync, readdirSync } from "node:fs";
import path from "node:path";
import { defineConfig } from "vitest/config";

/**
 * Stable-named copies of the bundle for the WordPress-embedded (nonce mode) screen, which cannot read
 * the hashed index.html. The single entry chunk has no imports, so a plain copy is a complete app.
 */
function embedCopies() {
  return {
    name: "rk-embed-copies",
    apply: "build" as const,
    closeBundle() {
      const out = path.resolve(import.meta.dirname, "dist/public");
      const assets = path.join(out, "assets");
      mkdirSync(path.join(out, "embed"), { recursive: true });
      for (const [ext, name] of [
        ["js", "rk-builder.js"],
        ["css", "rk-builder.css"],
      ] as const) {
        const file = readdirSync(assets).find(
          f => f.startsWith("index-") && f.endsWith(`.${ext}`)
        );
        if (!file) throw new Error(`embed: no index-*.${ext} in ${assets}`);
        copyFileSync(path.join(assets, file), path.join(out, "embed", name));
      }
    },
  };
}

const apiTarget = process.env.DEV_API_TARGET ?? "http://localhost:3001";

export default defineConfig({
  plugins: [react(), embedCopies()],
  resolve: {
    alias: { "@": path.resolve(import.meta.dirname, "client", "src") },
  },
  root: path.resolve(import.meta.dirname, "client"),
  build: {
    outDir: path.resolve(import.meta.dirname, "dist/public"),
    emptyOutDir: true,
    sourcemap: true,
  },
  server: {
    port: 3000,
    proxy: { "/api": apiTarget, "/site.css": apiTarget },
  },
  test: {
    root: path.resolve(import.meta.dirname),
    include: [
      "client/src/**/*.test.{ts,tsx}",
      "server/**/*.test.{ts,tsx}",
      "scripts/**/*.test.ts",
    ],
    environment: "node",
  },
});
