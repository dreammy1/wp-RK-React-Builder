import { build } from "esbuild";
import { copyFileSync, mkdirSync } from "node:fs";

await build({
  entryPoints: ["server/index.ts"],
  platform: "node",
  packages: "external",
  bundle: true,
  format: "esm",
  outdir: "dist",
  jsx: "automatic",
  tsconfig: "tsconfig.json",
  sourcemap: true,
});
mkdirSync("dist/assets", { recursive: true });
copyFileSync("client/src/styles/site.css", "dist/assets/site.css");
console.log("server bundle + site.css written to dist/");
