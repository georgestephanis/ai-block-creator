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
│   ├── architecture-and-design.md        # Architecture specification document
│   ├── code-review-2026-09-03.md         # Security/correctness review findings
│   └── TODO.md                           # Tracked task list (what's fixed, what's open)
├── src/
│   ├── index.js                          # Gutenberg editor entrypoint & plugin registration
│   ├── components/
│   │   ├── AIBlockCreatorModal.js       # Main creation modal & conversational thread
│   │   ├── BlockPreview.js              # Interactive live preview & attribute tester
│   │   ├── ImageDropzone.js             # Drag-and-drop & clipboard paste listener
│   │   └── VoiceInput.js                # Web Speech API voice dictation
│   ├── runtime/
│   │   └── dynamic-block-factory.js     # Client-side dynamic wp.blocks.registerBlockType
│   └── styles.scss                      # Scoped editor & modal UI styling
├── build/                                # Webpack compiled output (committed — see below)
├── blueprint.json                        # WordPress Playground blueprint
├── composer.json / phpcs.xml.dist        # PHPCS/WPCS lint config
├── readme.txt                            # WordPress.org standard plugin readme
└── README.md                             # Project overview
```

---

## Architectural Principles & Rules

### 1. WordPress Core AI Client Integration
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
- The renderer template language (`{{var}}`, `{{{raw}}}`, `{{#if}}`/`{{^if}}`, `{{#list}}`) is implemented twice — `AI_Block_Renderer::render_template()` in PHP (front end) and `interpolateTemplate()` in `dynamic-block-factory.js` (editor preview). **Keep them behaviorally identical**, including escaping rules (context-aware: `href`/`src` → URL-escaped, `style="..."` → CSS-filtered, everything else → HTML-escaped) — a divergence here is exactly the kind of bug that shipped before this was reviewed (see `plans/code-review-2026-09-03.md` BUG-1 through BUG-4).
- In the Block Editor, `registerDynamicAiBlock( blockDef )` registers the block client-side immediately so that freshly created blocks can be inserted without requiring a page reload. Always register from the **server's** returned definition (e.g. the response to `POST /blocks`), not the pre-save client-side draft — the server is authoritative and may have altered the slug.

### 3. Frontend & Build Conventions
- Use `@wordpress/scripts` (`npm run build` / `npm run start`). `build/` is committed to this repo (it's installed via `git:directory` — e.g. by the Playground blueprint — which doesn't run a build step), so a PR touching `src/` must include a rebuilt `build/`.
- Standard WordPress packages: `@wordpress/element`, `@wordpress/components`, `@wordpress/block-editor`, `@wordpress/blocks`, `@wordpress/data`, `@wordpress/plugins`, `@wordpress/editor` (not the deprecated `@wordpress/edit-post`), `@wordpress/api-fetch`, `@wordpress/i18n`, `@wordpress/escape-html`.
- Scoped CSS: Always prefix block styling classes with `.ai-block-{slug}` or `.ai-custom-block` to avoid style leakage across the editor or theme.
- All user-facing strings go through `__()`/`sprintf()` with the `ai-block-creator` text domain (JS) or `__()`/`esc_html__()` (PHP).
- `apiFetch()` calls don't need a manual `X-WP-Nonce` header — the block editor's built-in `apiFetch` middleware already attaches one.

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

`tests/php/` (PHPUnit) and `src/**/test/*.test.js` (Jest) are the automated test suites — see `composer test` / `npm run test-unit-js` above. Both were built by writing tests for the exact bugs listed in `plans/code-review-2026-09-03.md` and running them for real; in the process, `tests/php/BlockStoreTest.php` caught a real bug this way — `AI_Block_Store::sanitize_attributes()`/`sanitize_edit_fields()` were using `sanitize_key()` (which lowercases) on attribute names, silently renaming every camelCase attribute (`accentColor` → `accentcolor`) — and the `register_post_meta()` sanitize_callback in `ai-block-creator.php` was double-unslashing, which corrupted and dropped any saved block whose `render_html` contained an escaped quote (i.e. nearly all of them). Both are fixed; keep writing tests that exercise real WordPress functions against the real bootstrap rather than hand-rolled stubs — that's exactly how both bugs were caught.
