# RK React Builder

RK React Builder is a focused React visual workbench for composing headless WordPress pages from reusable blocks. It keeps the editor canvas and public renderer on the same block model so that the document being arranged is the document that ships.

## Included now

The frontend includes an asymmetric three-zone editor with a block palette, live canvas, drag-to-reorder, insertion indicators, duplicate/delete controls, a contextual inspector, global theme-token editing, preview mode, JSON export, local fallback persistence, and a configurable WordPress REST write path. The demo content models the `service` and `portfolio` CPT sources from the supplied architecture.

The original architecture plan, clickable HTML proof-of-concept, and WordPress plugin source are preserved in `ARCHITECTURE.md`, `POC_REFERENCE.html`, and `wp-plugin/rk-builder/rk-builder.php`.

## Run locally

```bash
pnpm install
pnpm dev
```

Use `pnpm run check` for TypeScript validation and `pnpm run build` for a production build.

## WordPress connection

Open the **Theme** tab in the editor and enter the WordPress site origin in **REST base URL**, for example `https://cms.example.com`. The Save action then attempts the supplied plugin endpoints:

- `POST /wp-json/rk/v1/builder/layout/42`
- `POST /wp-json/rk/v1/theme-config`

If no base URL is configured, Save stores the current layout in browser local storage as a safe demo fallback. Authentication, CORS, and capability checks remain WordPress responsibilities and should be configured before production use.

## Structure

- `client/src/lib/builder.ts` contains the shared layout types, block defaults, demo content, registry metadata, and REST client.
- `client/src/App.tsx` contains the editor shell, inspector, public renderer, preview route state, and export modal.
- `client/src/index.css` contains the Print Studio design system and responsive rules.
- `wp-plugin/` contains the supplied backend stub for the WordPress data layer.

## Design direction

The interface follows a Print Studio language: warm paper surfaces, graphite production rails, IBM Plex Mono metadata, Space Grotesk hierarchy, visible alignment cues, and Registration Lime (`#C7F36B`) as the owned interaction color. It intentionally avoids generic centered dashboard patterns and keeps the content model visible to the editor.

## Scope note

This repository is a frontend-first foundation. The supplied WordPress PHP is retained as an integration asset, but server logic in the managed frontend remains untouched. Production hardening should add authenticated load/save flows, WordPress nonce/application-password handling, revision history, media picking, and server-side rendering when the frontend is moved to a Next.js deployment.
