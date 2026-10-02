#!/usr/bin/env node
/**
 * Adds RK Builder to an RK Suite release as a module and connects it to the suite's MCP server.
 *
 *   pnpm build:plugin                                   (stages dist/plugin/rk-builder with the built editor)
 *   node scripts/build-suite.mjs <rk-suite.zip> [--version 1.17.0] [--out dist/rk-suite-with-builder.zip]
 *   node scripts/build-suite.mjs --stage-only <rk-suite.zip>   leave the patched tree in dist/suite/
 *
 * What it changes in the suite (every patch asserts its anchor, so a changed suite fails loudly):
 *   - modules/rk-builder/            the staged plugin + a module entry file
 *   - class-rk-suite-modules.php     registers the "rk-builder" module (off by default, like the others)
 *   - class-rk-api-mcp.php           lets modules add MCP tools (filters rk_api_mcp_catalog / rk_api_mcp_handlers)
 *   - rk-suite.php + readme.txt      version bump
 */
import { execFileSync } from "node:child_process";
import {
  cpSync,
  existsSync,
  mkdirSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { basename, dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const argv = process.argv.slice(2);
const flag = n => {
  const i = argv.indexOf(n);
  return i >= 0 ? argv[i + 1] : undefined;
};
const stageOnly = argv.includes("--stage-only");
const input = argv.find(
  (a, i) => !a.startsWith("--") && !["--version", "--out"].includes(argv[i - 1])
);
const fail = m => {
  console.error(`build-suite: ${m}`);
  process.exit(1);
};
if (!input || !existsSync(input))
  fail(
    "pass the path to the RK Suite zip, e.g. ~/Downloads/rk-suite-v1.16.9.zip"
  );
const plugin = join(root, "dist", "plugin", "rk-builder");
if (!existsSync(join(plugin, "assets", "builder.js")))
  fail("dist/plugin/rk-builder is missing — run `pnpm build:plugin` first");

const work = join(root, "dist", "suite");
rmSync(work, { recursive: true, force: true });
mkdirSync(work, { recursive: true });
execFileSync("unzip", ["-q", resolve(input), "-d", work]);
const suite = join(work, "rk-suite");
if (!existsSync(join(suite, "rk-suite.php")))
  fail("the zip must contain a top-level rk-suite/ folder");

const read = f => readFileSync(join(suite, f), "utf8");
const write = (f, s) => writeFileSync(join(suite, f), s);
const patch = (f, anchor, replacement, { once = true } = {}) => {
  const s = read(f);
  const n = s.split(anchor).length - 1;
  if (n === 0) fail(`${f}: anchor not found: ${anchor.slice(0, 60)}…`);
  if (once && n > 1)
    fail(`${f}: anchor is not unique: ${anchor.slice(0, 60)}…`);
  write(
    f,
    s.replace(anchor, () => replacement)
  );
};

const oldVersion = /Version:\s*([0-9.]+)/.exec(read("rk-suite.php"))?.[1];
const version = flag("--version") ?? bump(oldVersion);
function bump(v) {
  const p = v.split(".").map(Number);
  p[1] += 1;
  p[2] = 0;
  return p.join(".");
}

// ---- 1. the module ----
const mod = join(suite, "modules", "rk-builder");
cpSync(plugin, mod, { recursive: true });
const bundled = readFileSync(join(plugin, "rk-builder.php"), "utf8");
const body = bundled
  .replace(/^<\?php\s*\/\*\*[\s\S]*?\*\/\s*/, "")
  .replace(/register_activation_hook\([^)]*\);\s*/, "");
if (/register_activation_hook/.test(body))
  fail("could not strip the activation hook");
writeFileSync(
  join(mod, "rk-builder.php"),
  `<?php
/**
 * RK Builder — RK Suite module. Visual page builder: React editor in wp-admin, strict layout validation,
 * draft/publish with revisions, preview links, PHP public rendering, and MCP tools for assistants.
 * Generated from the standalone plugin by scripts/build-suite.mjs; loaded on demand by RK Suite.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// The standalone RK Builder plugin is active: it already owns these functions.
if ( defined( 'RK_BUILDER_VERSION' ) ) { return; }
define( 'RK_BUILDER_IN_SUITE', true );
define( 'RK_BUILDER_URL', plugin_dir_url( __FILE__ ) );

${body.replace(/if \( ! defined\( 'ABSPATH' \) \) \{ exit; \}\s*/, "")}`
);

// ---- 2. register it ----
patch(
  "includes/class-rk-suite-modules.php",
  "\t\t\t'rk-api' => array(",
  `\t\t\t'rk-builder' => array(
\t\t\t\t'name'      => 'RK Builder',
\t\t\t\t'tier'      => 'free',
\t\t\t\t'desc'      => 'Visual page builder: a React editor in wp-admin, strict validation, draft / publish with revisions, preview links and PHP public rendering. Exposes MCP tools through RK API so assistants can build and edit pages.',
\t\t\t\t'class'     => '',
\t\t\t\t'dir'       => 'rk-builder',
\t\t\t\t'main'      => 'rk-builder.php',
\t\t\t\t'boot'      => '',
\t\t\t\t'activate'  => 'rk_builder_activate',
\t\t\t\t'menu_slug' => 'rk-builder',
\t\t\t\t'depends'   => array(),
\t\t\t),
\t\t\t'rk-api' => array(`
);

// ---- 3. MCP: let modules contribute tools ----
const mcp = "modules/rk-api/includes/class-rk-api-mcp.php";
patch(
  mcp,
  "\t\t$map      = self::routes();\n\t\t$handlers = self::handlers();\n",
  `\t\t$map      = self::routes();
\t\t$handlers = self::handlers();
\t\t// Tools contributed by other modules (e.g. RK Builder): name => callable( array $args ).
\t\t$extra = apply_filters( 'rk_api_mcp_handlers', array() );
\t\tif ( isset( $extra[ $name ] ) && is_callable( $extra[ $name ] ) ) {
\t\t\t$resp = call_user_func( $extra[ $name ], $args );
\t\t\tif ( is_wp_error( $resp ) ) {
\t\t\t\t$data   = array( 'code' => $resp->get_error_code(), 'message' => $resp->get_error_message(), 'data' => $resp->get_error_data() );
\t\t\t\t$is_err = true;
\t\t\t} else {
\t\t\t\t$data   = ( $resp instanceof \\WP_REST_Response ) ? $resp->get_data() : $resp;
\t\t\t\t$is_err = false;
\t\t\t}
\t\t\treturn $this->rpc_result( $id, array(
\t\t\t\t'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $data ) ) ),
\t\t\t\t'isError' => $is_err,
\t\t\t) );
\t\t}
`
);
patch(
  mcp,
  "\t\treturn $c;\n\t}\n\n\tpublic static function routes()",
  "\t\treturn apply_filters( 'rk_api_mcp_catalog', $c );\n\t}\n\n\tpublic static function routes()"
);

// ---- 4. version ----
patch("rk-suite.php", `Version: ${oldVersion}`, `Version: ${version}`);
patch(
  "rk-suite.php",
  `define( 'RK_SUITE_VERSION', '${oldVersion}' );`,
  `define( 'RK_SUITE_VERSION', '${version}' );`
);
if (/Stable tag:/.test(read("readme.txt")))
  write(
    "readme.txt",
    read("readme.txt").replace(/(Stable tag:\s*)[0-9.]+/, `$1${version}`)
  );

// ---- 5. validate + zip ----
const lint = f => {
  try {
    execFileSync("php", ["-l", f], { stdio: "pipe" });
  } catch (e) {
    fail(`PHP syntax error in ${f}: ${String(e.stdout ?? e.message).trim()}`);
  }
};
for (const f of [
  join(mod, "rk-builder.php"),
  join(suite, "includes", "class-rk-suite-modules.php"),
  join(suite, mcp),
  join(suite, "rk-suite.php"),
])
  lint(f);

if (stageOnly) {
  console.log(`staged ${suite} (v${version})`);
  process.exit(0);
}
const out = resolve(
  flag("--out") ?? join(root, "dist", `rk-suite-v${version}.zip`)
);
rmSync(out, { force: true });
execFileSync("zip", ["-q", "-r", "-X", out, "rk-suite", "-x", "*.DS_Store"], {
  cwd: work,
});
console.log(
  `wrote ${out} (RK Suite ${oldVersion} → ${version}, +RK Builder module)`
);
console.log(`input: ${basename(input)}`);
