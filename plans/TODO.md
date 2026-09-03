# TODO — AI Block Creator

Task list derived from [code-review-2026-09-03.md](code-review-2026-09-03.md). IDs in brackets point at the finding with the details. Items are grouped so each group is a reasonable single work session / PR. Check items off as they land; add a commit hash next to the item when done.

---

## P0 — Security (ship before anyone else uses this)

- [ ] **Fix `DELETE /blocks/{id}` IDOR** — verify post type is a block definition and check `delete_post` cap. [SEC-1]
- [ ] **Split capabilities per route** — generate: `edit_posts` + `wp_supports_ai()`; list: `edit_posts`; save/delete: `unfiltered_html`. [SEC-3]
- [ ] **Normalize + validate on save** — reuse one strict allowlisting normalizer for both `/generate` and `POST /blocks`; drop unknown keys; validate `attributes[].type`, `edit_fields[].type`, `icon`, `name`. [SEC-2, BUG-8, BUG-11]
- [ ] **kses `render_html`** at save and at render for non-`unfiltered_html` contexts; decide on allowing `style="--var: …"` via `safe_style_css`. [SEC-2]
- [ ] **Sanitize `css`** — strip `</style`, `<`, `expression(`, `@import`, non-http/data `url()`; never output it inside `<body>`. [SEC-2, ARCH-2]
- [ ] **Context-aware escaping in the renderer** — `esc_url()` for `href`/`src` targets, `safecss_filter_attr()` inside `style`, `esc_attr()` inside other attributes. Mirror the rules in JS. [SEC-2, BUG-4]
- [ ] **Image upload limits** — MIME allowlist (png/jpeg/webp/gif), decoded-size cap, strict `base64_decode`; client-side downscale to ~1280px JPEG. [SEC-4]

## P1 — Renderer correctness

- [ ] Run the `{{{raw}}}` pass before `{{var}}` in PHP and JS (or lookaround). [BUG-1]
- [ ] Decide on nested `{{#if}}`: implement a stack-based parser shared by PHP/JS, or forbid nesting in the system prompt. [BUG-2]
- [ ] Always wrap front-end output in `<div {wrapper_attributes}>` so align/anchor/className and the scoping class work; remove or document `{{wrapper_attributes}}`. [BUG-3]
- [ ] Escape interpolated values in `interpolateTemplate()` with `@wordpress/escape-html`. [BUG-4]
- [ ] Add PHPUnit tests for `render_template()` using the cases in the review (triple-brace, nested if, list + outer var, attribute context, booleans, arrays). [DX-5]
- [ ] Add Jest tests for `interpolateTemplate()` asserting parity with the PHP fixtures. [DX-5]

## P1 — AI integration

- [ ] Switch to `wp_ai_client_prompt()` (WP wrapper) so `wp_supports_ai()`, `wp_ai_client_prevent_prompt`, and the timeout filter actually apply; remove the closure filter added inside the request. [BUG-12, DX-9]
- [ ] Set the request timeout explicitly via `usingRequestOptions()` or the filter at plugin bootstrap, not per-request. [BUG-12]
- [ ] Send conversation `history` with `withHistory()`; trim block JSON out of the history payload client-side (send only the latest `current_block`). [BUG-12]
- [ ] Use `asJsonResponse( $schema )` with a block-definition JSON schema; keep the `{…}` heuristic as fallback; delete the dead fenced-regex branch. [BUG-13]
- [ ] Stop `sanitize_textarea_field()`-ing the prompt; `trim` + length cap + `wp_check_invalid_utf8`. [BUG-14]
- [ ] Catch `\Throwable`, return a structured `WP_Error` with a short message and `raw_response` in `data`. [BUG-17, UX-6]
- [ ] Make `enable_thinking` a filtered `ModelConfig` (`ai_block_creator_model_config`), default off unless provider is vLLM-like. [ARCH-6]
- [ ] Allow screenshot-only generation by defaulting the prompt server-side when an image is present. [BUG-7]
- [ ] Update the system prompt: mention `{{{raw}}}`, wrapper behaviour, nesting rules, allowed attribute types, allowed `edit_fields` types, CSS scoping under `.ai-block-{slug}`. [BUG-2, BUG-3, BUG-11]

## P1 — Editor integration

- [ ] Fix `VoiceInput`: callback in a ref, recognizer created once, commit only `isFinal` results, show a permission-denied message. [BUG-5]
- [ ] Reset `BlockPreview` state when the definition changes; move hooks above the early return. [BUG-6]
- [ ] Register/insert from the server's returned `block`, only after a successful save; dedupe insert/save handlers. [BUG-16]
- [ ] Keep the `/ai-block` placeholder and `replaceBlocks()` it on insert; remove it on cancel. [BUG-15]
- [ ] Replace `PluginMoreMenuItem` import with `@wordpress/editor`. [ARCH-5]
- [ ] Replace the polled header button with a portal + `MutationObserver`, or drop it in favour of inserter + More Menu + `PluginSidebar`. [ARCH-4]
- [ ] Per-block style handles: `wp_register_style` + `wp_add_inline_style`, passed as `style`/`editor_style` to `register_block_type()`; remove `enqueue_frontend_styles()` and the renderer's inline `<style>`. For same-session blocks, inject into `useBlockProps` ref's `ownerDocument`. [ARCH-2]
- [ ] Sandbox the modal preview in an `<iframe srcdoc>`; sanitize block `edit` HTML with DOMPurify / `wp.dom.safeHTML`. [ARCH-9]
- [ ] Give `ColorPalette` the theme palette via `useSettings( 'color.palette' )`. [UX-3]
- [ ] Share one `renderField()` between the inspector and the preview tab so color/number/select match. [UX-4]
- [ ] Show a Notice when no AI provider is available (`hasAiClient` + a `providerConfigured` flag from PHP). [UX-1]
- [ ] Reset modal state on Cancel or add "Start over". [UX-2]

## P2 — Architecture & performance

- [ ] Introduce `Block_Store` (or `Block_Definition` value object): `all()`, `get( $slug )`, `save()`, `delete()`, with schema validation and object-cache/transient caching invalidated on write. Replace the four duplicate `get_posts()` loops. [ARCH-1, ARCH-3]
- [ ] Pass the definition into `register_block_type()` at `init` so `render()` never queries. [ARCH-3]
- [ ] Register `_ai_block_definition` via `register_post_meta()` with a `sanitize_callback`. [ARCH-7]
- [ ] Rename CPT `wp_block_def` → `ai_block_def` with a one-time migration keyed on a version option. [ARCH-7]
- [ ] Move the `block_categories_all` filter registration to file scope. [ARCH-8]
- [ ] Stricter slug generation (`[a-z0-9-]` only, fallback when empty) so block names are always valid. [BUG-10]
- [ ] On save: force `post_status => publish`, and handle slug collisions with a different author via `wp_unique_post_slug()`. [BUG-9]

## P2 — Product

- [ ] Block library management UI (list, delete, "open in creator") as a `PluginSidebar` or Settings page. [UX-5]
- [ ] i18n: wrap all JS strings in `__()`, add `wp-i18n` dependency, call `wp_set_script_translations()`. Replace emoji labels with `@wordpress/icons`. [UX-7]
- [ ] Use admin colour-scheme variables instead of the hard-coded Tailwind palette; reduce `!important` overrides. [UX-8]

## P3 — Packaging, docs, DX

- [ ] `Requires at least: 7.0` in plugin header, `readme.txt`, README. Consider `Requires Plugins:` or an admin notice when no connector is registered. [DX-1]
- [ ] Add `LICENSE` (GPL-2.0-or-later). [DX-2]
- [ ] Fix `blueprint.json`: valid plugin resource (`url` zip or corrected `git:directory`), and either configure a provider via `setSiteOptions` or document that it is required. Validate with the `blueprint` skill. [DX-3]
- [ ] CI: `npm ci && npm run build` and fail on `git diff --exit-code build/`; run `wp-scripts lint-js`, `lint-style`, PHPCS (WordPress-Extra), PHPUnit, Jest. [DX-4, DX-5]
- [ ] Add `phpcs.xml` + `composer.json`, `lint-js`/`lint-style`/`test` npm scripts. [DX-5]
- [ ] Add `uninstall.php` that removes `ai_block_def` posts and options. [DX-6]
- [ ] Replace `wp_localize_script()` with `wp_add_inline_script()` + `wp_json_encode()`; drop manual `X-WP-Nonce` headers. [DX-7]
- [ ] Full REST arg schemas (`items`, `properties`, `additionalProperties: false`). [DX-8]
- [ ] Add `conversation-log.jsonl` and `.serena/` to the repo `.gitignore`. [DX-10]
- [ ] Update `AGENTS.md` after the AI-client refactor so rule #1 matches the code; update `architecture-and-design.md` §2.1 security bullet to reflect the real capability model. [DX-9]

---

## Suggested PR sequencing

1. **PR 1 — Security hardening** (all P0). Small, reviewable, no UI change.
2. **PR 2 — Renderer fixes + tests** (P1 renderer). Introduces the test harness everything else can lean on.
3. **PR 3 — AI client refactor** (P1 AI). Touches only the controller.
4. **PR 4 — Style delivery + Block_Store** (ARCH-1/2/3). Largest structural change; do after 1–3 so it has tests behind it.
5. **PR 5 — Editor UX** (P1 editor, P2 product).
6. **PR 6 — Packaging/CI/docs** (P3). Can run in parallel with 4–5.
