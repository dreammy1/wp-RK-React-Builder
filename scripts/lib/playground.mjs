/**
 * Boots WordPress Playground (WASM, SQLite) with the RK Builder plugin mounted and seeded.
 * Shared by scripts/wp-smoke.mjs and scripts/wp-integration.test.ts. Needs network on first run.
 */
import { spawn } from "node:child_process";
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve, dirname } from "node:path";
import { fileURLToPath } from "node:url";

export const root = resolve(
  dirname(fileURLToPath(import.meta.url)),
  "..",
  ".."
);
const pluginDir = join(root, "wp-plugin", "rk-builder");

const SEED_PHP = `<?php
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
$creds = array();
$admin = get_user_by('login','admin');
$ed  = wp_insert_user(array('user_login'=>'smoke_editor','user_pass'=>wp_generate_password(24),'role'=>'editor','display_name'=>'Smoke Editor'));
$sub = wp_insert_user(array('user_login'=>'smoke_sub','user_pass'=>wp_generate_password(24),'role'=>'subscriber'));
foreach (array('admin'=>$admin->ID,'editor'=>$ed,'subscriber'=>$sub) as $k=>$uid) {
  $r = WP_Application_Passwords::create_new_application_password($uid, array('name'=>'smoke'));
  $creds[$k] = array('user'=>get_userdata($uid)->user_login,'pass'=>$r[0]);
}
$page = wp_insert_post(array('post_type'=>'page','post_status'=>'draft','post_title'=>'Smoke Page','post_name'=>'smoke','post_author'=>$ed));
$creds['pageId'] = $page;
// a real media attachment with alt text, used as a featured image
$upload = wp_upload_dir();
$file = $upload['path'] . '/seed.png';
file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
$att = wp_insert_attachment(array('post_mime_type'=>'image/png','post_title'=>'Seed image','post_status'=>'inherit'), $file, 0);
update_post_meta($att, '_wp_attachment_image_alt', 'Seed alt text');
wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $file));
$creds['mediaId'] = $att;
$cat = wp_insert_term('Energy', 'service_cat', array('slug'=>'energy'));
foreach (array('Wiring'=>false,'Solar'=>true) as $t=>$energy) {
  $id = wp_insert_post(array('post_type'=>'service','post_status'=>'publish','post_title'=>$t,'post_excerpt'=>"<b>$t</b> work"));
  set_post_thumbnail($id, $att);
  if ($energy && !is_wp_error($cat)) wp_set_object_terms($id, array((int)$cat['term_id']), 'service_cat');
}
wp_insert_post(array('post_type'=>'service','post_status'=>'draft','post_title'=>'Hidden'));
$p = wp_insert_post(array('post_type'=>'portfolio','post_status'=>'publish','post_title'=>'Beach House','post_excerpt'=>'Full rewire'));
set_post_thumbnail($p, $att);
wp_set_password('rk-e2e-admin-password', $admin->ID);
wp_set_password('rk-e2e-editor-password', $ed);
wp_set_password('rk-e2e-subscriber-password', $sub);
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules();
file_put_contents('/wordpress/rk-out/creds.json', json_encode($creds));
`;

export async function startPlayground({
  pluginDir: pluginDirOverride = pluginDir,
  port = 9411,
  allowedOrigins = "https://editor.example.com",
  revalidate = null,
  constants = {},
} = {}) {
  const extraDefines = Object.entries(constants)
    .map(([k, v]) => ` define(${JSON.stringify(k)}, ${JSON.stringify(v)});`)
    .join("");
  const base = `http://127.0.0.1:${port}`;
  const out = mkdtempSync(join(tmpdir(), "rk-wp-"));
  const blueprint = {
    $schema: "https://playground.wordpress.net/blueprint-schema.json",
    landingPage: "/",
    steps: [
      {
        step: "writeFile",
        path: "/wordpress/wp-content/mu-plugins/rk-smoke.php",
        data: `<?php add_filter('wp_is_application_passwords_available','__return_true'); add_filter('wp_is_application_passwords_available_for_user','__return_true'); define('RK_BUILDER_ALLOWED_ORIGINS','${allowedOrigins}'); define('RK_BUILDER_ALLOWED_IMAGE_HOSTS','cms.example.com');${extraDefines}${revalidate ? ` define('RK_BUILDER_REVALIDATE_URL','${revalidate.url}'); define('RK_BUILDER_REVALIDATE_SECRET','${revalidate.secret}');` : ""}`,
      },
      { step: "activatePlugin", pluginPath: "rk-builder/rk-builder.php" },
      { step: "runPHP", code: SEED_PHP },
    ],
  };
  const bpPath = join(out, "blueprint.json");
  writeFileSync(bpPath, JSON.stringify(blueprint));

  const logs = [];
  const server = spawn(
    "npx",
    [
      "--yes",
      "@wp-playground/cli@latest",
      "server",
      `--port=${port}`,
      `--blueprint=${bpPath}`,
      `--mount=${pluginDirOverride}:/wordpress/wp-content/plugins/rk-builder`,
      `--mount=${out}:/wordpress/rk-out`,
      "--wp=latest",
      "--php=8.3",
    ],
    { stdio: ["ignore", "pipe", "pipe"], shell: false, detached: true }
  );
  server.stdout.on("data", d => logs.push(String(d)));
  server.stderr.on("data", d => logs.push(String(d)));
  const stop = () => {
    try {
      process.kill(-server.pid, "SIGKILL"); // npx spawns a grandchild: kill the whole group
    } catch {
      /* best effort */
    }
    try {
      rmSync(out, { recursive: true, force: true });
    } catch {
      /* best effort */
    }
  };
  process.on("exit", stop);

  const started = Date.now();
  while (Date.now() - started < 240_000) {
    try {
      const creds = JSON.parse(readFileSync(join(out, "creds.json"), "utf8"));
      const r = await fetch(`${base}/`, { redirect: "manual" });
      if (r.status < 500) {
        const probe = await fetch(`${base}/wp-json/`);
        return {
          base,
          creds,
          stop,
          apiRoot:
            probe.status === 200 ? `${base}/wp-json/` : `${base}/?rest_route=/`,
        };
      }
    } catch {
      /* best effort */
    }
    await new Promise(r => setTimeout(r, 1500));
  }
  stop();
  throw new Error(
    "Playground did not become ready in time:\n" + logs.join("").slice(-3000)
  );
}
