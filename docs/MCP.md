# RK Builder as an MCP server

RK Builder can be driven by an AI assistant (Claude, Cursor, and other MCP clients) through the
[Model Context Protocol](https://modelcontextprotocol.io). The assistant does what an administrator does in
the dashboard: build and edit pages, write SEO text and schema, manage content, templates, media, reusable
blocks, redirects and site settings.

It adds no new powers. Each tool is one existing RK Builder REST route, run as the user who signed in, so the
same permissions, strict validation, revisions and conflict checks apply.

## Turn it on

1. **Dashboard → AI & MCP**, switch on **Allow AI assistants to connect**. It is off by default; while off the
   endpoint answers `rk_mcp_disabled`.
2. Pick what it may do:
   - **Read only**: look at everything, change nothing.
   - **Read and write**: create and edit pages, content, SEO, templates, settings; publish; trash (recoverable).
   - **Full access**: also delete images, templates and reusable blocks for good, and change the code printed
     on every page. Use it for one task, then lower it.
3. Under **Connect an AI assistant**, make a **connection key**: give it a name (the assistant that will use it) and
   a level. The key is shown once, so copy it. Only a hash is stored. Revoke it on the same screen any time.
4. Add the server to your assistant. The screen fills your new key into ready-to-copy setup for Claude Code,
   Claude Desktop, Cursor and other clients, plus a terminal test. The address is
   `https://your-site/wp-json/rk/v1/builder-mcp`.

```
claude mcp add --transport http rk-builder https://your-site/wp-json/rk/v1/builder-mcp \
  --header "X-RK-API-Key: rkb_..."
```

The key travels in `X-RK-API-Key` because some hosts drop the standard `Authorization` header. A
`Authorization: Bearer rkb_...` header works too.

A key acts as the administrator who made it, and its level can only be equal to or lower than the global access
level. Up to 10 keys; each shows when it was last used.

**Prefer WordPress sign-in?** A WordPress **Application Password** (profile → Application Passwords, needs
HTTPS) works over HTTP Basic, and acts as that user with the global access level. The screen has the setup line
under "Use a WordPress Application Password instead".

## What it can do

48 tools in these groups: Overview (`rkb_overview`, `rkb_block_catalog`), Pages, SEO, Content, Templates,
Media, Reusable blocks and Site. Each tool carries MCP annotations (`readOnlyHint`, `destructiveHint`), and
`tools/list` only offers the tools the current level allows.

To build a page the assistant reads `rkb_block_catalog` (every block type with its props and limits), then
`rkb_get_layout`, `rkb_save_layout` (a draft, with `expectedRevision`) and, when you ask, `rkb_publish`. A
conflict means someone else changed the page; it reads again and redoes the edit.

## Safety

- Off until an administrator enables it; one switch turns it off again.
- The signed-in account's own capabilities apply to every call (an editor cannot reach admin settings).
- The access level removes tools it should not have, and refuses them even when named directly.
- Every call is written to **Recent activity** (last 50: time, account, tool, done or refused).
- Connection keys are random (160 bits) and stored only as a SHA-256 hash; a revoked key stops working at once.
- Recent activity also shows which key made each call.

## Add your own tool

```php
add_filter( 'rk_builder_mcp_tools', function ( $tools ) {
	$tools['rkb_my_report'] = array(
		'title' => 'My report', 'group' => 'Site', 'risk' => 'read', 'description' => 'What it returns.',
		'method' => 'LOCAL', 'path' => '', 'props' => array(), 'required' => array(),
		'callback' => function ( $args ) { return array( 'ok' => true ); },
	);
	return $tools;
} );
```

`risk` is `read`, `write` or `full`. A tool may instead point at a REST route: `'method' => 'POST', 'path' =>
'/builder/…'` (with `{id}` style placeholders).

## RK Suite

When RK Suite's RK API module is active it has its own MCP server at `/wp-json/rk/v1/mcp` that already exposes
a few builder tools (`wp_builder_*`). The two work side by side; this one covers the whole dashboard.
