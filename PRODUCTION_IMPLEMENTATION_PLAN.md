# Production implementation plan — status

Source brief: `COMPLETE_IMPLEMENTATION_PROMPT.md`. This document records what each phase delivered, the evidence, and
what remains outside the repository (environment work) or is deliberately deferred.

## Baseline (Phase 0)

Starting point: Vite SPA with a monolithic `App.tsx`, flat blocks, hard-coded page `42`, hard-coded demo content, no
auth, a plugin with a different schema, an unused Next.js/Manus scaffold (~60 UI components, ~50 dependencies, a runtime
plugin, an unapplied pnpm patch). `pnpm check` and `pnpm build` passed but the editor/plugin contract was broken
(see the 10 issues in the brief). Prototype leftovers were deleted rather than adapted.

## Phase 1 — Schema and contract ✅

`client/src/lib/schema/*`, `client/src/blocks/*/schema.ts`, migrations (v0 flat blocks → v1, versionless theme),
`contracts/{valid,invalid,legacy}` fixtures, PHP validator with identical rules. **One** schema: `{id, type, props}`.

## Phase 2 — WordPress backend ✅

Strict validation (rejects, never drops), page list, permission checks with stable `rk_*` errors, revisions with
retention, expected-revision conflicts + save lock, publish/unpublish snapshot, preview tokens, media endpoint,
normalized CPT endpoints, exact-match CORS, revalidation webhook, standalone admin screen.
Evidence: `php wp-plugin/tests/run.php` (103 cases) and `pnpm smoke:wp` (14 checks on real WordPress).

## Phase 3 — Builder integration ✅

Page selector, load-on-open with explicit states, live CPT grids (cache + de-dup), correct save envelope, local draft
recovery, undo/redo, keyboard reorder, conflict/auth/offline flows.

## Phase 4 — Public renderer ✅ (equivalent server-rendered route, per ADR-1)

SSR in the Node server using the shared registry; metadata, canonical, OG/Twitter, 404/503 pages, draft/private
protection, image dimensions/alt, cache + webhook revalidation.

## Phase 5 — Media, publishing, polish ✅

Media picker, draft/preview/publish workflow, revision browser + restore, theme editor aligned with the backend,
accessibility pass (axe + keyboard + focus trap + live regions + reduced motion), telemetry and error reporting.

## Phase 6 — Release hardening 🟡 partly outside the repo

Done in-repo: all automated suites, clean build, env validation, CI, docs, backup/rollback runbooks, dependency audit
gate (prod: clean; dev: 2 moderate, 0 high at the time of writing).
**Needs your infrastructure:** staging deployment, the staging checklist in `DEPLOYMENT.md`, the backup/restore drill on
real data, production deploy, monitoring dashboards/alerts wiring, CI run on GitHub (the workflow has not been executed
on GitHub from this environment; PHP 7.4 in particular is only exercised there).

## Definition of done — evidence

| Item                                                                                           | Evidence                                                                                                    |
| ---------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| A real WordPress page can be selected                                                          | `pnpm test:wp` (`listPages`), wp-admin E2E (`Smoke Page` link)                                              |
| Layout + theme load                                                                            | `wp-integration.test.ts` "loads … revision 0", Playwright "loads the saved layout"                          |
| Add/edit/delete/duplicate/reorder                                                              | Playwright `builder.spec.ts` (mouse + keyboard), reducer unit tests                                         |
| Saves canonical schema; reloads intact                                                         | integration "round-trips every block type"; PHP + smoke deep-equal on `layout-full`; Playwright reload test |
| Invalid payloads rejected safely                                                               | `contracts/invalid/*` in TS **and** PHP; integration 400 with issue paths                                   |
| Services/portfolio from WordPress; filters, limits, columns                                    | integration (category, order, limit), Playwright "grid filters…"                                            |
| Demo fixtures not used in production rendering                                                 | `?demo=1` only; Playwright asserts no demo badge by default                                                 |
| Anonymous cannot save; page + theme permissions; nonces; CORS; unsafe URLs; oversize           | PHP tests, smoke, `app.test.tsx`, wp-admin E2E (no/wrong nonce)                                             |
| Server-rendered pages, metadata, draft protection, revalidation, image dims/alt                | `app.test.tsx`, Playwright `publish.spec.ts`, integration (real webhook purge)                              |
| Undo/redo, local drafts, revisions preview/restore, conflicts, observability, backups/rollback | reducer/drafts tests, Playwright recovery suite, `OPERATIONS.md`, `DEPLOYMENT.md`                           |
| Typecheck, lint, format, unit, contract, E2E, a11y, security, build                            | `pnpm verify` + `pnpm test:e2e` + `pnpm test:wp*` (see README)                                              |
| Backups and rollback "documented **and tested**"                                               | Documented. **Not drilled** — a restore drill on real data is a launch task for you                         |

## Deliberately deferred

Nested columns/containers, per-block responsive controls, header/footer builder, custom CSS/HTML blocks, realtime
collaboration, advanced animation, a large widget library, AI layout generation (see ARCHITECTURE §8). Also: per-editor
identity in proxy mode, shared session/cache store for multi-instance deployments, webhook on Service/Portfolio edits,
import of prototype `_rk_layout` meta, self-hosted fonts.
