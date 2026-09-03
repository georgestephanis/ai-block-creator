# Review — external changes since commit `e7cf291`

**Date:** 2026-09-03
**Scope:** 17 commits (`e7cf291..60c6677`, all authored by George Stephanis) made to the working tree between this session's prior review and this one — UI restructuring (native inserter card, LLM-connection-aware degradation), a real renderer bugfix, an AI-client method-call fix, and a new hard plugin dependency. Reviewed by running the actual test/lint suites, reproducing claims against the real WordPress install rather than trusting the diff, and reading every changed file.

---

## Critical: `POST /blocks` (Save to Library / Insert into Post) is broken

**Reproduced live** via `rest_do_request()` against the real REST dispatcher (not just read from the diff).

`AIBlockCreatorModal.js`'s `handleSaveToLibrary()` and `handleInsertIntoPost()` (added in this batch) send the block definition as the request body directly:

```js
apiFetch({ path: '/ai-block-creator/v1/blocks', method: 'POST', data: currentBlock })
```

`AI_Block_REST_Controller::save_block()` and its route's `args` schema still expect the older wrapped shape: `$request->get_param('block_definition')`, with `block_definition` declared `required: true`. WordPress validates required `args` in `WP_REST_Server::dispatch()` *before* the callback ever runs, so every save currently 400s:

```
rest_missing_callback_param — Missing parameter(s): block_definition
```

This is the plugin's primary user-facing action (create → insert), and it's fully broken as of `60c6677`. Neither PHPUnit nor Jest caught it because neither exercises the actual REST boundary — `BlockStoreTest.php` calls `AI_Block_Store` directly, and there was no test at the `WP_REST_Request` → dispatcher → controller level for `POST /blocks`.

**Root cause is a client/server contract drift, not a logic bug on either side alone.** The client-side convention was intentionally simplified in this batch (send the definition as the body, not nested under a key) without updating the server to match.

**Remediation direction:** update the server to match the newer, simpler client convention (read the JSON body as the definition directly) rather than reverting the client back to a wrapper object — the unwrapped shape is the more conventional REST design (the body *is* the resource) and matches the direction the other 16 commits in this batch were already taking the UI. See "What was fixed" below.

---

## Confirmed as legitimate, correct fixes (no action needed)

- **Multi-variable `style="..."`/`href="..."` interpolation** (`AI_Block_Renderer::render_template()`, `interpolateTemplate()`). Real bug in code from the prior review pass: the old single-pass regex could resolve at most one `{{var}}` per `style`/`href` attribute — a second variable in the same attribute (e.g. `style="color: {{color}}; background: {{bg}};"`) was left as a raw, unescaped, un-substituted `{{var}}` literal in the output. Rewritten as three sequential passes (style attributes → href/src attributes → everything else), each fully resolving all placeholders within its match before the next pass runs. New tests (`RendererTest::test_interpolates_multiple_variables_within_same_style_attribute`, the matching JS test, and a URL-attribute equivalent of each) cover it; all pass.
- **`$builder->generate_text()` instead of `$builder->generateTextResult()`** in `AI_Block_REST_Controller::generate_block()`. Also a real fix, verified against `wp-includes/ai-client/class-wp-ai-client-prompt-builder.php`: `WP_AI_Client_Prompt_Builder::__call()` keys its `$generating_methods`/`$support_check_methods` registries by the *exact* string PHP passes to the magic method — i.e. by the snake_case name as literally called, not a case-normalized one. The prior camelCase call (`generateTextResult()`) was never recognized as a "generating method" by that registry, so on a real provider error the wrapper's `catch` block would set `$this->error` internally but return `$this` (the wrapper object) instead of a `WP_Error` — the code would then call `->toText()` on that wrapper (which resolves to `$this` again via the same mechanism) and hand a wrapper object to `extract_json_from_response(string $raw)`'s strict string type hint, producing a `TypeError` instead of a clean, provider-aware error message. `generate_text()` (snake_case) is correctly registered in `$generating_methods` and also happens to be a real convenience method on the underlying `PromptBuilder` (`generateText(): string { return $this->generateTextResult()->toText(); }`), so the fix is correct on both ends of the call.
- **`Requires Plugins: ai`** — verified `ai` is a real, published WordPress.org plugin slug (confirmed via `api.wordpress.org/plugins/info/1.0/ai.json`: name "AI", version 1.3.0, matching the locally installed copy), correctly namespaced `WordPress\AI`, and `has_valid_ai_credentials()` is a real function in it. Declaring the hard dependency and installing it in `blueprint.json` are both consistent and correct.
  - **Minor, harmless inconsistency, not a bug:** `has_connected_llm()`/`supports_image_input()` still have `elseif` fallback branches for `wp_supports_ai()`/`wp_ai_client_prompt()` when the `ai` plugin's function doesn't exist — but since `Requires Plugins: ai` now blocks activation entirely without it, those branches can never execute in practice. Harmless dead code; not worth changing unless the dependency is ever loosened back to optional.
- **No-LLM-connected / no-vision-support graceful degradation** (`has_connected_llm()`, `supports_image_input()`, the inserter-card/menu-item hiding, vision-aware copy in the `/ai-block` placeholder). Good UX: hides dead-end entry points and shows a "connect a provider" card instead of a broken flow.
- **The "native AI tab" approach was tried and correctly abandoned.** Intermediate commits in this batch (`2ac76b5` through `36ba72d`) attempted to make the AI entry point impersonate a real Gutenberg inserter tab, including CSS fighting Gutenberg's own tab-underline indicator (`src/inserter-tab-controller.js`, `src/components/AIInserterTab.js`). This is exactly the kind of fragile, undocumented-internal-CSS-class dependency that breaks across Gutenberg versions with no deprecation notice. It was superseded a few commits later (`93d9fe6`) by a safer design — a real React root (`createRoot`/`render`, not raw `innerHTML`) mounted via `MutationObserver` at the top of the *existing* Blocks tab, not impersonating a tab of its own. Confirmed no dead CSS or orphaned files were left behind from the abandoned attempt.
- `AI-DISCLOSURES.md` split-out from `README.md` is clean, and its added Gemini/Sonnet entries accurately describe the corresponding commits.

## Verified clean as of `60c6677`

PHPCS (2 minor whitespace-alignment *warnings* only, auto-fixable, zero errors), PHPUnit (36/36), ESLint, Stylelint, Jest (13/13), `npm run build`.

---

## What was fixed in this pass

- [x] `POST /ai-block-creator/v1/blocks`: `save_block()` now reads the definition from the raw JSON body (`$request->get_json_params()`), matching what the client actually sends. A `{ block_definition: {...} }` wrapper is still accepted too (checked first and explicitly, since a body that merely *contains* a `block_definition` key is not itself empty — an early version of this fix got that check wrong and silently produced a bogus `ai-block/ai-block` slug instead of failing loudly; caught by the new test below before it shipped). Removed the route's `args` schema requiring a `block_definition` param, since WordPress validates required `args` in the dispatcher *before* the callback runs — that validation, not the handler logic, is what was actually producing the 400 for every save. Verified live via `rest_do_request()` for both the current and legacy body shapes.
- [x] Added a REST-boundary integration test (`tests/php/RestControllerTest.php`, 6 tests) that dispatches real `WP_REST_Request` objects through `rest_do_request()` for save (both body shapes, and a rejection case), get, and delete — including a REST-layer regression guard for SEC-1 (delete refuses to touch a non-block-definition post) alongside `BlockStoreTest`'s existing store-layer version of the same guard. This is the gap that let the original bug ship unnoticed by the existing suites, which only exercised `AI_Block_Store` directly, never the actual route registration / args validation / JSON body parsing a live request goes through.
- [x] Fixed the 2 PHPCS whitespace-alignment warnings in `ai-block-creator.php` via `phpcbf`.

All 42 PHPUnit tests, PHPCS, ESLint, Stylelint, all 13 Jest tests, and `npm run build` pass as of the commit following this document. No leftover rows in the database after a full test run (verified via `wp post list --post_type=ai_block_def --post_status=any` returning empty).
