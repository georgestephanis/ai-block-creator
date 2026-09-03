# TODO — AI Block Creator

Task list derived from [code-review-2026-09-03.md](code-review-2026-09-03.md). IDs in brackets point at the finding with the details. Items are grouped so each group is a reasonable single work session / PR. Check items off as they land; add a commit hash next to the item when done.

---

## P0 — Security (ship before anyone else uses this)

- [x] **Fix `DELETE /blocks/{id}` IDOR** — verify post type is a block definition and check a real capability. [SEC-1] `518e433`
- [x] **Split capabilities per route** — generate: `edit_posts`; list: `edit_posts`; save/delete: `unfiltered_html`. [SEC-3] `518e433`
- [x] **Normalize + validate on save** — one strict allowlisting normalizer (`AI_Block_Store::normalize_and_validate()`) shared by `/generate` and `POST /blocks`; drops unknown keys; validates `attributes[].type`, `edit_fields[].type`, `icon`, `name`. [SEC-2, BUG-8, BUG-11] `518e433`
- [x] **kses `render_html`** at save (and via `register_post_meta()`'s `sanitize_callback` for any other write path) for non-`unfiltered_html` contexts; `style`/`class` attributes explicitly allowed. [SEC-2] `518e433`
- [x] **Sanitize `css`** — strips `</style`, `<script`, `@import`, `expression()`, `javascript:`, non-http/data `url()`; delivered via a registered style handle, never inlined into `<body>`. [SEC-2, ARCH-2] `518e433`
- [x] **Context-aware escaping in the renderer** — `esc_url()` for `href`/`src` targets, `safecss_filter_attr()` inside `style`, `esc_html()` elsewhere; mirrored in JS via `@wordpress/escape-html`. [SEC-2, BUG-4] `518e433` (PHP), `39351af` (JS)
- [x] **Image upload limits** — MIME allowlist (png/jpeg/webp/gif), 4MB decoded-size cap, strict `base64_decode`, client-side pre-validation. [SEC-4] `518e433` (server), `39351af` (client) — client-side downscaling to ~1280px before upload is still open, see P2 below.

## P1 — Renderer correctness

- [x] Run the `{{{raw}}}` pass before `{{var}}` in PHP and JS. [BUG-1] `518e433`, `39351af`
- [x] Nested `{{#if}}`: stack-based parser shared in intent (parallel implementations) by PHP and JS. [BUG-2] `518e433`, `39351af`
- [x] Always wrap front-end output in `<div {wrapper_attributes}>`. [BUG-3] `518e433`
- [x] Escape interpolated values in `interpolateTemplate()` with `@wordpress/escape-html`. [BUG-4] `39351af`
- [x] Added PHPUnit tests for `render_template()` (`tests/php/RendererTest.php`, 15 tests) covering every case from the review: triple-brace, nested if, list + outer-var interaction, attribute-context escaping (href/src/style), booleans, arrays, missing keys. Bootstraps against a real WordPress install (`tests/php/bootstrap.php`) rather than the synthetic WP core test harness. [DX-5]
- [x] Added Jest tests for `interpolateTemplate()` (`src/runtime/test/dynamic-block-factory.test.js`, 11 tests) mirroring the PHP fixtures 1:1. Wired up `npm run test-unit-js` via `@wordpress/scripts`; `@wordpress/blocks`/`block-editor`/`components`/`element` are stubbed for Jest (see `jest-unit.config.js`, `tests/js/mocks/`) since they ship as TS/ESM-first source Jest can't parse and webpack never resolves them from disk anyway. One real (safe) divergence found and documented: `@wordpress/escape-html`'s `escapeHTML()` doesn't escape `>` in text content per the WHATWG spec (only `<` and ambiguous `&` matter there), while PHP's `esc_html()` does as extra defense-in-depth — both are safe, it's cosmetic. [DX-5]
- [x] **Running these tests for real caught two genuine bugs the earlier ad hoc verification missed** (fixed in the same pass): `AI_Block_Store::sanitize_attributes()`/`sanitize_edit_fields()` used `sanitize_key()` (which lowercases) on attribute/field names, silently renaming every camelCase attribute the AI generates (`accentColor` → `accentcolor`) and breaking any template that referenced the original name; and the `register_post_meta()` `sanitize_callback` in `ai-block-creator.php` was double-unslashing — `update_metadata()` already runs `wp_unslash()` once before invoking a registered meta's sanitize callback, so unslashing again there corrupted any JSON containing an escaped quote (i.e. any block whose `render_html` has an HTML attribute, which is nearly all of them), silently reducing the stored definition to an empty string. Both fixed in `includes/class-ai-block-store.php` / `ai-block-creator.php`.

## P1 — AI integration

- [x] Switch to `wp_ai_client_prompt()` so `wp_supports_ai()`, `wp_ai_client_prevent_prompt`, and the timeout filter actually apply. [BUG-12, DX-9] `518e433`
- [x] Send conversation `history` via `withHistory()`. [BUG-12] `518e433`
- [x] Use `asJsonResponse()`; keep the `{…}` heuristic as fallback and fixed its non-matching fenced-code regex. [BUG-13] `518e433`
- [x] Stop `sanitize_textarea_field()`-ing the prompt; `trim` + length cap + `wp_check_invalid_utf8`. [BUG-14] `518e433`
- [x] Catch `\Throwable`. [BUG-17] `518e433`
- [x] Make `enable_thinking` a filtered option (`ai_block_creator_model_config_options`). [ARCH-6] `518e433` — the "default off unless provider is vLLM-like" auto-detection is not implemented; it's just filterable for now. Revisit if this bites a non-vLLM provider in practice.
- [x] Allow screenshot-only generation by defaulting the prompt server-side when an image is present. [BUG-7] `518e433`
- [x] Update the system prompt: `{{{raw}}}`, wrapper behaviour is no longer prompt-visible (always wrapped now, so nothing to document), nesting is supported, allowed attribute/edit_field types are listed. [BUG-2, BUG-3, BUG-11] `518e433`
- [ ] `UX-6`: `raw_response` is now returned in the `WP_Error` `data` on a parse failure, but the modal doesn't yet show a "show raw output" disclosure — it just displays the error message. Low priority.

## P1 — Editor integration

- [x] Fix `VoiceInput`: callback in a ref, recognizer created once, commit only final results, permission-denied Notice. [BUG-5] `39351af`
- [x] Reset `BlockPreview` state when the definition changes; hooks moved above the early return. [BUG-6] `39351af`
- [x] Register/insert from the server's returned `block`; deduped insert/save into one `persistBlock()` helper. [BUG-16] `39351af`
- [x] Keep the `/ai-block` placeholder's `clientId` and `replaceBlocks()` it on insert; `removeBlock()` it on Cancel instead. [BUG-15] `39351af`
- [x] Replace `PluginMoreMenuItem` import with `@wordpress/editor`. [ARCH-5] `39351af`
- [x] Replace the polling header button with a `MutationObserver`. [ARCH-4] `39351af` — still injects raw DOM (no portal); revisit only if it causes real problems, the inserter + More Menu entry are the reliable paths regardless.
- [x] Per-block style handles via `wp_register_style`/`wp_add_inline_style`, passed as `style`/`editor_style`; frontend `enqueue_frontend_styles()` and the renderer's inline `<style>` tag removed. [ARCH-2] `518e433` (PHP). JS-side CSS injection now also targets the editor canvas iframe. `39351af`
- [ ] Sandbox the modal preview in an `<iframe srcdoc>`; sanitize block `edit` HTML with DOMPurify. [ARCH-9] — **not done**. The JS-side escaping fix (BUG-4) closes the actual XSS vector (values are now escaped before reaching `RawHTML`/`dangerouslySetInnerHTML`), so the risk this item was chasing is substantially mitigated; full iframe sandboxing is a larger UX change (loses live WP admin styling context, needs its own asset-loading story) and is deferred as a nice-to-have rather than a security requirement.
- [x] Give `ColorPalette` the theme palette via `useSettings( 'color.palette' )`. [UX-3] `39351af`
- [x] Share one `renderEditField()` between the inspector and the preview tab. [UX-4] `39351af`
- [x] Show a Notice when no AI provider is available/supported, and a separate one when the user can generate but lacks permission to save/insert. [UX-1] `39351af`
- [x] Reset modal state on Cancel and after a successful insert. [UX-2] `39351af`

## P2 — Architecture & performance

- [x] `AI_Block_Store`: `all()`, `get()`, `save()`, `delete()`, `normalize_and_validate()`, with request-scoped `wp_cache_*` caching invalidated on write. Replaced the four duplicate `get_posts()` loops. [ARCH-1, ARCH-3] `518e433`
- [x] Register the definition meta via `register_post_meta()` with a `sanitize_callback`. [ARCH-7] `518e433`
- [x] Renamed CPT `wp_block_def` → `ai_block_def`. No migration needed — no installs existed yet. [ARCH-7] `518e433`
- [x] Moved the `block_categories_all` filter registration to file scope. [ARCH-8] `518e433`
- [x] Stricter slug/attribute-type/edit-field validation. [BUG-10, BUG-11] `518e433`
- [x] On save: force `post_status => publish`. [BUG-9] `518e433` — the "different author" slug-collision handling (`wp_unique_post_slug()` for a genuinely different block vs. the same author updating theirs) is still just "last write wins by slug"; low priority since `unfiltered_html` is now required to save at all, which shrinks the pool of people who can collide.
- [ ] Client-side screenshot downscaling to ~1280px JPEG before upload (mentioned as a stretch goal under SEC-4). Server-side size/MIME validation is the actual security boundary and is done; this is a UX/bandwidth nicety.

## P2 — Product

- [x] Block library management UI: new `BlockLibrarySidebar` component, registered as a `PluginSidebar` ("AI Block Library") reachable from the More menu. List, Insert, Refine (reopens the creator modal preloaded via a new `initialBlock` prop), Delete (confirm-gated, `canManageLibrary`-gated). Sidebar and modal stay in sync via a shared `ai-block-creator-library-updated` window event rather than polling. [UX-5] `de1f0a6`
- [x] i18n: all JS strings wrapped in `__()`/`sprintf()`, `@wordpress/i18n` added as a dependency, `wp_set_script_translations()` called. Emoji labels intentionally kept (product decision, not a bug) but are now alongside translated text rather than replacing it. [UX-7] `518e433` (PHP `wp_set_script_translations`), `39351af` (JS strings)
- [x] Replaced the hardcoded indigo/purple accent with WordPress's admin theme-color custom properties (`--wp-admin-theme-color` and friends — the only five WP actually exposes), original hex kept as `var()` fallbacks. Two-stop purple→violet gradients became single-hue accent→accent-darker gradients, since WP doesn't expose a second swappable brand color. `!important` overrides were **not** reduced — they're there to override `@wordpress/components`' own specificity (e.g. `.components-modal__content`), which isn't optional; left as a non-issue, not deferred. [UX-8] `de1f0a6`

## P3 — Packaging, docs, DX

- [x] `Requires at least: 7.0` in the plugin header [DX-1] `518e433`, and `readme.txt` (which still said 6.7) fixed to match `1f9f3a3`. README.md was already correct. `Requires Plugins:` / a no-connector admin notice is still open — see the blueprint.json fix below, which added the equivalent for the Playground demo but not for a real install.
- [x] Add `LICENSE` (GPL-2.0-or-later). [DX-2] `1f9f3a3`
- [x] Fix `blueprint.json`: the plugin's own `installPlugin` step used `"resource": "git:directory", "directory": "."`, which isn't a valid resource shape (`git:directory` needs `url`/`ref`/`refType`, not a bare `directory`). Now points at the real `github.com/georgestephanis/ai-block-creator` repo on `trunk`. No safe default AI-provider endpoint exists to wire up via `setSiteOptions` (any hardcoded public endpoint would be either unauthenticated, uselessly rate-limited, or someone's real paid key), so instead of pretending it works out of the box, `landingPage` now points at Settings → Connectors and a small mu-plugin shows an admin notice linking there until a provider is connected. Validated by running `npx @wp-playground/cli run-blueprint` twice (once with an appended `wp plugin list` check) — both exited 0. [DX-3]
- [ ] CI: `npm ci && npm run build` and fail on `git diff --exit-code build/`; run `composer lint` (phpcs), `composer test` (phpunit — needs a WordPress install available in the runner, see `WP_ROOT` in `tests/php/bootstrap.php`), `npm run lint-js`, `npm run lint-css`, `npm run test-unit-js`. [DX-4]
- [x] **Set up WPCS/PHPCS via Composer** — `composer.json` (WPCS + PHPCompatibilityWP + `dealerdirect/phpcodesniffer-composer-installer`) and `phpcs.xml.dist` (WordPress-Extra + WordPress-Docs, `testVersion=7.4-`). Run with `composer lint` / auto-fix with `composer format`. All plugin PHP passes clean. [DX-5] `e8240c6`
- [x] **Set up JS/CSS lint + format via `@wordpress/scripts`** — `npm run lint-js[:fix]`, `npm run lint-css[:fix]`, `npm run format`, `npm test` (runs lint-js + lint-css + test-unit-js). All `src/` files pass clean. Also had to pin `typescript` to `5.6.3` via npm `overrides` — the resolved `typescript@7` broke `@typescript-eslint@6`'s `ts-api-utils`, crashing ESLint outright; worth revisiting once `@wordpress/eslint-plugin` bumps its typescript-eslint dependency. [DX-5] `e8240c6`
- [x] **Built the PHPUnit and Jest test suites** (`tests/php/`, `phpunit.xml.dist`, `src/runtime/test/`, `jest-unit.config.js`) — see the DX-5 entries above under "Renderer correctness" for what they cover and the two real bugs they caught (attribute-name lowercasing, double-unslash meta corruption). [DX-5]
- [ ] Add `uninstall.php` that removes `ai_block_def` posts. [DX-6]
- [x] Replaced `wp_localize_script()` with `wp_add_inline_script()` + `wp_json_encode()`; dropped manual `X-WP-Nonce` headers from every `apiFetch` call (the editor's built-in nonce middleware already sends it). [DX-7] `518e433`, `39351af`
- [x] Fleshed out REST arg schemas for `/generate` (`history[].items`, `current_block.properties`) and `POST /blocks` (`block_definition.properties`). [DX-8] `518e433` — schemas use `additionalProperties: true` rather than a fully-specified object shape, since the block-definition shape is intentionally flexible pre-normalization; the real validation happens in `AI_Block_Store::normalize_and_validate()`, not the REST schema layer.
- [x] Added `conversation-log.jsonl` — turns out this is a session artifact from working in this repo, not a repo convention; add it and `.serena/` to `.gitignore` if either starts showing up as untracked cruft again. Not currently tracked, so no action was needed beyond confirming that.
- [x] Updated `AGENTS.md` to describe `AI_Block_Store`, the capability split, the renamed CPT, per-block style handles, and the linting commands; rewrote `architecture-and-design.md` §2.1 to match, and fixed its sample `render_html` (it used a `{{cond ? a : b}}` ternary syntax that was never actually implemented — the real grammar is `{{#if}}`/`{{^if}}`/`{{#list}}`, now documented inline). [DX-9]
- [x] `README.md`/`readme.txt` updated: fixed the stray `wp_block_def` mention, documented the Block Library panel and the `unfiltered_html` permission requirement (both new FAQ/Usage entries), added a lint-commands line to the Development section, and expanded the 1.0.0 changelog entry rather than bumping the version (nothing has shipped publicly yet). Validated `readme.txt` with the WordPress.org readme validator — no errors or warnings. [DX-9]

---

## Suggested PR sequencing (historical — superseded by what actually landed)

Work landed directly on `trunk` in three commits rather than the originally-sketched six-PR split, since the security/renderer/AI-client/architecture fixes turned out to be too interdependent (all routing through the new `AI_Block_Store`) to cleanly separate:

1. `e8240c6` — Lint tooling (PHPCS/WPCS + `@wordpress/scripts` JS/CSS linting).
2. `518e433` — All PHP: security (P0), renderer correctness (P1), AI client integration (P1), and the architecture items that P0/P1 depended on (`AI_Block_Store`, per-block styles, CPT rename).
3. `39351af` — All JS: renderer parity, voice input, preview panel, modal/insert flow, editor integration (ARCH-4/5), UX notices.

What's left is tracked above, roughly in priority order: test suites (P1), the block-library management UI and remaining polish (P2), then docs/packaging (P3).
