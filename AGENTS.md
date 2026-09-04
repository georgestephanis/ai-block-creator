# AGENTS.md — Development Guidelines for AI Block Creator

This guide provides context, conventions, and architectural rules for AI agents and developers working on the `ai-block-creator` WordPress plugin repository.

---

## Repository Overview

`ai-block-creator` is a WordPress plugin that allows users to create, refine, and insert custom Gutenberg blocks in real time using WordPress core's `AiClient` (`WordPress\AiClient\AiClient`).

### Directory Structure
```
ai-block-creator/
├── ai-block-creator.php          # Main plugin bootstrap and hook loader
├── uninstall.php                 # Removes saved block definitions on uninstall
├── includes/
│   ├── class-ai-block-store.php          # Single source of truth: read/validate/save/delete block defs
│   ├── class-ai-block-renderer.php       # Dynamic PHP render callback & template parser
│   └── class-ai-block-rest-controller.php # REST API controller (/generate, /blocks)
├── plans/
│   ├── architecture-and-design.md        # Architecture specification document (kept current)
│   └── done/                             # Dated, point-in-time records — not living docs
│       ├── code-review-2026-09-03.md     # Security/correctness review findings
│       └── TODO-2026-09-03.md            # That review's task list (what got fixed, what's still open)
├── src/
│   ├── index.js                          # Gutenberg editor entrypoint & plugin registration
│   ├── components/
│   │   ├── AIBlockCreatorModal.js       # Main creation modal & conversational thread
│   │   ├── BlockLibrarySidebar.js       # PluginSidebar: list/insert/refine/delete saved blocks
│   │   ├── BlockPreview.js              # Interactive live preview & attribute tester
│   │   ├── ImageDropzone.js             # Drag-and-drop & clipboard paste listener
│   │   ├── StylePreview.js              # Preview for block styles/variations (no render template)
│   │   ├── kind-labels.js               # Shared human labels for the three definition kinds
│   │   └── VoiceInput.js                # Web Speech API voice dictation
│   ├── runtime/
│   │   ├── dynamic-block-factory.js     # Client-side dynamic wp.blocks.registerBlockType
│   │   └── test/                        # Jest tests for dynamic-block-factory.js
│   └── styles.scss                      # Scoped editor & modal UI styling
├── tests/
│   ├── php/                              # PHPUnit suite (bootstrap.php, RendererTest.php, BlockStoreTest.php,
│   │                                     #   RestControllerTest.php, DefinitionKindsTest.php, KindRegistrationTest.php)
│   └── js/mocks/                         # Jest module stubs — see jest-unit.config.js
├── build/                                # Webpack compiled output (committed — see below)
├── blueprint.json                        # WordPress Playground blueprint
├── composer.json / phpcs.xml.dist        # PHPCS/WPCS lint config
├── phpunit.xml.dist / jest-unit.config.js # Test suite configs
├── readme.txt                            # WordPress.org standard plugin readme
└── README.md                             # Project overview
```

---

## Architectural Principles & Rules

### 1. WordPress Core AI Client Integration
- The plugin declares `Requires Plugins: ai` (WordPress 6.5+ Plugin Dependencies) and relies on WordPress core's AI client APIs.
- Use `wp_ai_client_prompt()` (the WordPress core wrapper), not `\WordPress\AiClient\AiClient::prompt()`/`::generateTextResult()` directly. The wrapper is what applies the `wp_ai_client_default_request_timeout` filter, `wp_supports_ai()`, `wp_ai_client_prevent_prompt`, and converts every exception to a consistent `WP_Error` — calling the raw builder skips all of that silently.
- When generating structured text/JSON with thinking models (e.g. Qwen 3.6, DeepSeek), configure `ModelConfig` with a custom option:
  ```php
  $model_config = new \WordPress\AiClient\Providers\Models\DTO\ModelConfig();
  $model_config->setCustomOption( 'chat_template_kwargs', [ 'enable_thinking' => false ] );
  ```
  This prevents thinking delay timeouts and ensures fast, deterministic token generation. This option is provider-specific (vLLM/Qwen-style servers) — it's applied through the `ai_block_creator_model_config_options` filter in `AI_Block_REST_Controller::generate_block()` rather than hardcoded, since it would be meaningless (or rejected as an unknown parameter) for other providers.
- Ask the model for structured output directly with `->asJsonResponse()` rather than relying solely on regex extraction from free text; keep the regex fallback for providers/models that don't honor the schema request.

### 2. Block Registration & Persistence Lifecycle
- Custom blocks are persisted as custom post type `ai_block_def` (defined as `AI_Block_Store::POST_TYPE` — never hardcode the string).
- **`AI_Block_Store` is the only class that reads or writes block definitions.** Don't call `get_posts()`/`get_post_meta()`/`update_post_meta()` on `ai_block_def` posts directly — go through `AI_Block_Store::all()` / `::get()` / `::save()` / `::delete()`. This is where validation, sanitization, and caching live; bypassing it reopens the security holes this class was built to close.
- **Every block definition is untrusted input until it passes `AI_Block_Store::normalize_and_validate()`.** `render_html` is only trusted verbatim for a user with `unfiltered_html`; otherwise it's passed through `wp_kses_post()` (extended to allow `style`/`class`). `css` always has `<script>`/`<style>`/`@import`/`expression()`/`javascript:` stripped, unconditionally. Saving/deleting a block definition requires `unfiltered_html` — it publishes to every site visitor, so `edit_posts` (which any Contributor has) is not enough. Generating a *draft* (the `/generate` endpoint) stays at `edit_posts` since nothing is persisted.
- **Database Gotcha**: When saving JSON strings into post meta (`_ai_block_definition`), always use `wp_slash( wp_json_encode( $def ) )` so WordPress's internal `stripslashes_deep` in `update_post_meta` does not corrupt JSON quotes. `AI_Block_Store::save()` already does this — don't reimplement it.
- On `init`, all published `ai_block_def` posts are registered server-side with `register_block_type()` using `AI_Block_Renderer::render`, and each block's CSS is registered as its own `wp_register_style()`/`wp_add_inline_style()` handle (passed as `style`/`editor_style`) so WordPress only enqueues it on pages/editor sessions where the block is actually present — never inline every saved block's CSS on every page.
- The renderer template language (`{{var}}`, `{{{raw}}}`, `{{#if}}`/`{{^if}}`, `{{#list}}`) is implemented twice — `AI_Block_Renderer::render_template()` in PHP (front end) and `interpolateTemplate()` in `dynamic-block-factory.js` (editor preview). **Keep them behaviorally identical**, including escaping rules (context-aware: `href`/`src` → URL-escaped, `style="..."` → CSS-filtered, everything else → HTML-escaped) — a divergence here is exactly the kind of bug that shipped before this was reviewed (see `plans/done/code-review-2026-09-03.md` BUG-1 through BUG-4).
- In the Block Editor, `registerDynamicAiBlock( blockDef )` registers the block client-side immediately so that freshly created blocks can be inserted without requiring a page reload. Always register from the **server's** returned definition (e.g. the response to `POST /blocks`), not the pre-save client-side draft — the server is authoritative and may have altered the slug.

### 2b. Definition Kinds & The Two-Stage Pipeline

A `/generate` request no longer always produces a custom block. It runs two model
calls: **stage one** classifies the request (`custom_block` / `block_style` /
`block_variation` and a target block, exposed on its own as `POST /plan`), and
**stage two** builds that kind with a kind-specific system prompt. See
`plans/architecture-and-design.md` §3 for the full rationale and §4 for each
kind's schema. The rules that matter when editing this code:

- **`kind` is the discriminator, and its absence means `custom_block`.** Every
  definition stored before kinds existed has no `kind` field, and they must keep
  validating and registering unchanged. `AI_Block_Store::sanitize_kind()` and
  `kindOf()` in `dynamic-block-factory.js` both default that way; keep them in sync.
- **The generator never gets to choose the kind.** `generate_block()` stamps the
  plan's `kind`/`target_block` onto the model's output *after* the call. A model
  asked to write a style declaring itself something else would route its output
  through the wrong normalizer and the wrong registration API.
- **The three kinds use three different registration APIs**, and a definition sent
  to the wrong one fails silently: `register_block_type()` for custom blocks,
  `register_block_style()` for styles, and the `get_block_type_variations` filter
  for variations (*not* the `variations` argument to `register_block_type()` — the
  blocks being varied are already registered by the time this plugin loads).
  `tests/php/KindRegistrationTest.php` guards each of those landings.
- **Client-side, styles and variations must wait for `domReady`.** They attach to a
  block someone else registered, and `getBlockType()` has to already know about it;
  core's blocks come from `wp-block-library`, whose execution order relative to this
  plugin's bundle isn't guaranteed. Custom blocks stand alone and register immediately.
- **A variation's `attributes` are values, a custom block's are a schema.** They
  need `sanitize_attribute_values()`, not `sanitize_attributes()`; the schema
  sanitizer silently discards concrete values (none is an array with a `type`).
- **A style's `name` is a published contract.** It becomes the `.is-style-{name}`
  class written into post content, so refinement turns pin the stored name instead
  of letting the model rename it. (Custom blocks still allow a rename on refinement
  — a pre-existing behavior this didn't change.)
- **Kinds share one post type and are namespaced in `post_name`** (`{slug}`,
  `style-{slug}`, `variation-{slug}`) so a style can't overwrite the custom block
  that happens to share its slug. Custom blocks keep their bare slug.

### 3. Frontend & Build Conventions
- Use `@wordpress/scripts` (`npm run build` / `npm run start`). `build/` is committed to this repo (it's installed via `git:directory` — e.g. by the Playground blueprint — which doesn't run a build step), so a PR touching `src/` must include a rebuilt `build/`.
- Standard WordPress packages: `@wordpress/element`, `@wordpress/components`, `@wordpress/block-editor`, `@wordpress/blocks`, `@wordpress/data`, `@wordpress/plugins`, `@wordpress/editor` (not the deprecated `@wordpress/edit-post`), `@wordpress/api-fetch`, `@wordpress/i18n`, `@wordpress/escape-html`.
- Scoped CSS: Always prefix block styling classes with `.ai-block-{slug}` or `.ai-custom-block` to avoid style leakage across the editor or theme.
- All user-facing strings go through `__()`/`sprintf()` with the `ai-block-creator` text domain (JS) or `__()`/`esc_html__()` (PHP).
- `apiFetch()` calls don't need a manual `X-WP-Nonce` header — the block editor's built-in `apiFetch` middleware already attaches one.

### 4. Block-Building Instructions: Sources & Provenance

This plugin follows two *separate* sets of "block-building instructions" that come from different places and serve different purposes — don't conflate them.

**a) How WE register and render blocks** (the PHP/JS in `ai-block-creator.php`, `includes/class-ai-block-renderer.php`, `src/runtime/dynamic-block-factory.js`) follows WordPress's own official agent skill for block development:

> **Source**: [`wordpress/agent-skills` — `skills/wp-block-development`](https://github.com/WordPress/agent-skills/tree/trunk/skills/wp-block-development) (mirrored locally at `~/.claude/skills/wp-block-development/` when installed)

Specifically, this codebase follows that skill's guidance on:
- `apiVersion: 3` on every registered block, in both the PHP `register_block_type()` args and the JS `registerBlockType()` config — required by WP 6.9+ (`references/block-json.md`, "API version + schema").
- `get_block_wrapper_attributes()` for all dynamic-render wrapper output (`references/dynamic-rendering.md`, `references/supports-and-wrappers.md`) — getting this wrong was BUG-3 in `plans/done/code-review-2026-09-03.md` (`align`/`anchor`/`className` supports silently doing nothing on the front end).
- `useBlockProps()` in the editor's `edit()` function (`references/supports-and-wrappers.md`).
- `save: () => null` for every block, since every block is server-rendered (`references/dynamic-rendering.md`, "Keep `save()` empty or `null` for fully dynamic output").
- Registering on `init`, unconditionally — not gated to admin-only requests (`references/registration.md`, "Where to register").
- `render_callback` (rather than `block.json`'s `render` field) as the dynamic-rendering mechanism — the skill names this explicitly as the accepted alternative (`references/dynamic-rendering.md`: "Alternative: pass `render_callback` when registering the block in PHP").

**Deliberate divergence from that skill**: its primary recommendation is registering via `block.json` + `register_block_type_from_metadata()` ("Prefer metadata registration... keeps metadata authoritative", `references/registration.md`). We don't do this, because `block.json` is inherently a *filesystem* artifact — one file per block, authored ahead of time — and this plugin's entire premise is blocks that never exist as files: they're generated by an AI at request time and persisted in the `ai_block_def` custom post type (`AI_Block_Store`). There's no file for a `block.json` to describe. We pass the equivalent metadata as a plain array to `register_block_type()` directly, built from the stored definition, instead. (If this plugin ever grows an "export this AI block to a real `block.json` for hand-editing" feature, *that* exported code should follow the skill's metadata-registration guidance — at that point it genuinely would be a normal, file-based block.)

**b) How the AI itself is instructed to *generate* a definition** is a completely separate, plugin-specific concern, and it is now four prompts rather than one. `AI_Block_REST_Controller::build_planner_system_prompt()` describes the three kinds and the decision criteria between them for stage one; `build_system_prompt()` then dispatches to `build_custom_block_prompt()`, `build_block_style_prompt()`, or `build_block_variation_prompt()` for stage two. The custom-block prompt is the original one: a JSON schema (`name`, `title`, `icon`, `attributes`, `edit_fields`, `render_html`, `css`) and a small mustache-style template grammar (`{{var}}`, `{{{raw}}}`, `{{#if}}`/`{{^if}}`, `{{#list}}`) for the `render_html` field.

Each stage-two prompt must stay honest about what its kind can actually deliver — a style prompt that talked about editable fields, or a variation prompt that talked about custom markup, would be describing a pipeline that doesn't exist. The planner prompt's decision criteria and the candidate target-block list are equally live surface: `candidate_target_blocks()` is what bounds the planner's `target_block`, and `AI_Block_Store::sanitize_target_block()` rejects anything outside the site's registry regardless.

That grammar is **not** sourced from WordPress/Gutenberg documentation or the skill above — it's an original micro-templating language invented for this plugin, because Gutenberg's normal attribute-interpolation mechanisms (`block.json` `source`/`selector`, PHP string concatenation) aren't expressible in a single JSON field an LLM can reliably emit in one pass. It's implemented independently in `AI_Block_Renderer::render_template()` (PHP) and `interpolateTemplate()` (`dynamic-block-factory.js`); see §2 above ("Block Registration & Persistence Lifecycle") for the requirement to keep those two implementations behaviorally identical. If you change the grammar, the system prompt text describing it to the model must change too, and vice versa.

The prompt is a live piece of product surface, not "documentation" — audit it against what the pipeline can actually deliver whenever either changes:
- It used to ask the model for a `"category"` field that `register_dynamic_blocks()` never read (every AI block registers under the fixed `"ai-blocks"` inserter category regardless) — removed from the schema; asking an LLM to produce data you discard is wasted tokens and, worse, implies a choice matters when it doesn't.
- It now explicitly states that no JavaScript ever runs for these blocks (no `<script>`, no `viewScript`, no Interactivity API — see §2's "Instant Dynamic Registration") and that inline event-handler attributes like `onclick` are silently stripped for non-`unfiltered_html` savers, directing the model to `<details>`/`<summary>` and CSS-only techniques (`:hover`/`:focus`) for anything that needs to look interactive instead. Before this, a stock suggestion in the UI ("FAQ Accordion... with expandable question panels") had no way to actually be delivered — the model's only tools were markup and CSS, with nothing telling it that. `tests/php/BlockStoreTest.php::test_details_and_summary_survive_kses_for_non_unfiltered_html_users` locks in that this guidance is actually deliverable through the sanitization pipeline, not just asserted.
- It now also forbids referencing external images/fonts (`<img src="https://...">`, remote font/icon URLs) in `render_html`, matching the CSS-side `@import`/external-URL restriction that already existed — there's no upload mechanism, so a model-authored external reference is always either broken or an unreviewed third-party dependency.

---

## Verification Commands

- **Lint PHP** (WordPress-Extra + PHPCompatibilityWP, `testVersion=7.4-`):
  ```bash
  composer install   # first time only
  composer lint       # phpcs
  composer format     # phpcbf, auto-fixes what it can
  ```
- **Lint JS/CSS**:
  ```bash
  npm run lint-js       # or lint-js:fix
  npm run lint-css      # or lint-css:fix
  npm run test          # lint-js + lint-css + test-unit-js
  ```
- **Run the PHP test suite** (real WordPress install, not the synthetic WP core test harness — see `tests/php/bootstrap.php` for why):
  ```bash
  composer install   # first time only, pulls in phpunit + yoast/phpunit-polyfills
  composer test       # == vendor/bin/phpunit
  ```
  Tests that touch the database (`AI_Block_Store::save()`/`delete()`) clean up everything they create in `tearDown()` — there's no per-test transaction rollback, so a new test that writes to the DB must clean up after itself the same way.

  If this plugin isn't checked out at the conventional `wp-content/plugins/<slug>/` depth (e.g. a CI checkout of just the plugin repo), point the bootstrap at a WordPress install: `WP_ROOT=/path/to/wordpress composer test`.
- **Run the JS test suite** (Jest via `@wordpress/scripts`):
  ```bash
  npm run test-unit-js
  ```
  `@wordpress/blocks`, `@wordpress/block-editor`, `@wordpress/components`, and `@wordpress/element` are stubbed for Jest (see `jest-unit.config.js` and `tests/js/mocks/wp-package-stub.js`) rather than npm-installed as real packages — those ship as TypeScript/ESM-first source that Jest's default config can't parse, and webpack never actually resolves them from disk anyway (they're mapped to `wp.*` globals at build time). Only stub packages that a test file doesn't need real behavior from; `@wordpress/i18n` and `@wordpress/escape-html` are real, since their actual output matters for the escaping assertions.
- **Build Assets**:
  ```bash
  npm run build
  ```
- **Activate Plugin**:
  ```bash
  wp plugin activate ai-block-creator
  ```
- **Test Generation via WP-CLI**:
  ```bash
  wp eval '$req = new WP_REST_Request("POST", "/ai-block-creator/v1/generate"); $req->set_param("prompt", "Create an author badge block"); $ctrl = new \AI_Block_Creator\AI_Block_REST_Controller(); $res = $ctrl->generate_block($req); var_dump($res->get_data()["block"]["name"]);'
  ```
- **Test the renderer directly** (useful for template-language changes — no HTTP round trip needed):
  ```bash
  wp eval-file /path/to/a/script/that/calls/AI_Block_Creator\\AI_Block_Renderer::render_template()
  ```
  When changing escaping or template-language behavior, verify against a real `wp-load.php` bootstrap (not stubbed `esc_*`/`wp_kses_*` functions) — the stubs used during initial development hid a real bug where a stubbed `esc_url()` didn't actually strip `javascript:` the way core's does. `tests/php/RendererTest.php` formalizes exactly this verification; extend it rather than re-deriving ad hoc checks by hand.

`tests/php/` (PHPUnit) and `src/**/test/*.test.js` (Jest) are the automated test suites — see `composer test` / `npm run test-unit-js` above. Both were built by writing tests for the exact bugs listed in `plans/done/code-review-2026-09-03.md` and running them for real; in the process, `tests/php/BlockStoreTest.php` caught a real bug this way — `AI_Block_Store::sanitize_attributes()`/`sanitize_edit_fields()` were using `sanitize_key()` (which lowercases) on attribute names, silently renaming every camelCase attribute (`accentColor` → `accentcolor`) — and the `register_post_meta()` sanitize_callback in `ai-block-creator.php` was double-unslashing, which corrupted and dropped any saved block whose `render_html` contained an escaped quote (i.e. nearly all of them). Both are fixed; keep writing tests that exercise real WordPress functions against the real bootstrap rather than hand-rolled stubs — that's exactly how both bugs were caught.
