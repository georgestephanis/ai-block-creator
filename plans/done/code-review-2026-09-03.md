# Code Review — AI Block Creator 1.0.0

**Date:** 2026-09-03
**Reviewed at commit:** `d61990f` (trunk)
**Environment checked against:** Studio site `~/Studio/test` — WordPress 7.1, PHP 8.5.7, SQLite DB, `ai-provider-for-openai-compatible-servers` pointed at a local vLLM server.

Each finding has an ID (`SEC-`, `BUG-`, `ARCH-`, `UX-`, `DX-`) so the checklist in [TODO.md](TODO.md) can reference it. "Confirmed" means I reproduced it (by running the code or reading the core source it depends on); "By inspection" means I am confident from the code but did not execute it.

---

## Summary

The plugin works end-to-end for the happy path (a `testimonial-card` definition exists in the local DB and round-trips), and the overall shape — CPT for definitions, mustache-lite renderer, client-side dynamic registration — is sound. The problems cluster in four areas:

1. **Authorization and stored-XSS.** Any `edit_posts` user (Contributors) can delete *any* post on the site through the REST API, and can store arbitrary HTML/CSS that renders unfiltered on the public front end.
2. **The renderer has real correctness bugs.** Triple-brace raw output is broken, nested conditionals break, and the block wrapper (align / anchor / className) is silently dropped on the front end.
3. **The AI integration bypasses WordPress's wrapper.** Using `AiClient` directly means the timeout filter the code sets has no effect, `wp_supports_ai()` is ignored, and the conversation history the UI collects is never sent.
4. **Editor integration is fragile.** DOM-polling header button, deprecated `edit-post` slot, CSS injected into the wrong document when the canvas is iframed, voice input that aborts itself, and a preview panel whose state goes stale after refinement.

---

## Critical / Security

### SEC-1 — `DELETE /blocks/{id}` force-deletes any post on the site (Confirmed by inspection)
[class-ai-block-rest-controller.php:434-447](../includes/class-ai-block-rest-controller.php#L434-L447)

`delete_block()` calls `wp_delete_post( $id, true )` with no check that the post is a `wp_block_def`, and no `current_user_can( 'delete_post', $id )`. The route's permission callback is only `edit_posts`. A Contributor can therefore permanently delete pages, posts, attachments, menus, etc. by ID.

**Fix:** load the post, verify `post_type === 'wp_block_def'`, and gate on a real capability (see SEC-3).

### SEC-2 — Stored XSS: unfiltered `render_html` / `css` from low-privilege users reaches the front end (Confirmed)
- [class-ai-block-rest-controller.php:376-426](../includes/class-ai-block-rest-controller.php#L376-L426) — `save_block()` stores the client-supplied definition verbatim. Only `title` and `name` are sanitized. `render_html`, `css`, `attributes`, `edit_fields`, `icon`, and any extra keys are stored as-is. The body is whatever the browser sends; it does not have to come from the AI.
- [class-ai-block-renderer.php:60](../includes/class-ai-block-renderer.php#L60) — the template is output on the front end with no kses pass. Only *interpolated values* are escaped; the template itself is trusted.
- [ai-block-creator.php:228-236](../ai-block-creator.php#L228-L236) — CSS is added via `wp_add_inline_style()` with **zero** sanitization (not even `wp_strip_all_tags`), so `</style><script>…` in the `css` field breaks out on every front-end page.
- [class-ai-block-renderer.php:66](../includes/class-ai-block-renderer.php#L66) — the second CSS output path uses `wp_strip_all_tags()`, which is not a CSS sanitizer.

Test I ran against `render_template()`:
```
template: <a href="{{url}}" style="color:{{accentColor}}">x</a>
attrs:    url=javascript:alert(1)   accentColor=red;background:url(x)
output:   <a href="javascript:alert(1)" style="color:red;background:url(x)">x</a>
```
`esc_html()` is the wrong escaper for attribute and URL contexts; the renderer cannot know the context a `{{var}}` lands in.

**Fix (layered):**
1. Require `unfiltered_html` (or at minimum `manage_options` / `edit_theme_options`) to *save* definitions. Generating can stay at `edit_posts`.
2. Server-side normalize + validate on save (same routine as generate — see BUG-8), and run `render_html` through `wp_kses_post()` at save time and again at render time for users without `unfiltered_html`. Note kses will strip `style="--accent: {{x}}"` custom properties unless you extend `safe_style_css`; decide whether to allow CSS custom properties explicitly.
3. Sanitize CSS: at minimum strip `</style`, `<`, `expression(`, `javascript:`, `@import`, and `url(` with non-data/http schemes; better, use `safecss_filter_attr`-style allowlisting or a small CSS tokenizer. Emit it via a registered style handle (ARCH-2) so it never lands inside `<body>`.
4. Context-aware interpolation: `{{url}}`-style values used in `href`/`src` need `esc_url()`; values inside `style=""` need `safecss_filter_attr()`. Simplest approach that keeps the mustache surface small: detect `href="{{x}}"` / `src="{{x}}"` / `style="…{{x}}…"` patterns in the template and escape by context, or introduce typed attributes (`type: "url"`, `type: "color"`) and escape by attribute type.

### SEC-3 — Capability model is too loose overall (By inspection)
[class-ai-block-rest-controller.php:113-116](../includes/class-ai-block-rest-controller.php#L113-L116)

One `check_permissions()` (`edit_posts`) guards generate, list, save and delete. Generating burns the site owner's AI budget; saving publishes site-wide code; deleting is destructive. These need different capabilities. Suggested: `generate` → `edit_posts` (maybe `publish_posts`); `GET /blocks` → `edit_posts`; `POST /blocks` and `DELETE` → `unfiltered_html` (falls back correctly on multisite where only super admins have it). Also add `wp_supports_ai()` to the generate check.

### SEC-4 — Image payload has no limits or MIME allowlist (By inspection)
[class-ai-block-rest-controller.php:157-168](../includes/class-ai-block-rest-controller.php#L157-L168), [ImageDropzone.js:40-52](../src/components/ImageDropzone.js#L40-L52)

Any `image/*` of any size is base64'd into the JSON body. A retina screenshot is easily 5–10 MB of base64 → `post_max_size` errors surface as opaque failures, and it costs a lot of model context. `base64_decode()` is non-strict. Allowlist `png|jpeg|webp|gif`, cap decoded size (~4 MB), and downscale client-side to ~1280px JPEG before upload.

---

## Bugs (Correctness)

### BUG-1 — `{{{raw}}}` triple-brace output is broken in both PHP and JS (Confirmed in PHP; identical ordering in JS)
[class-ai-block-renderer.php:137-170](../includes/class-ai-block-renderer.php#L137-L170), [dynamic-block-factory.js:68-84](../src/runtime/dynamic-block-factory.js#L68-L84)

The double-brace regex runs first and consumes `{{html}}` inside `{{{html}}}`, leaving the outer braces. Actual output:
```
template: <div>{{{html}}}</div>   attrs: html=<em>rich</em>
output:   <div>{&lt;em&gt;rich&lt;/em&gt;}</div>
```
Run the triple-brace pass **before** the double-brace pass (or use a negative-lookbehind/lookahead in the double-brace regex).

### BUG-2 — Nested `{{#if}}` blocks break (Confirmed)
```
template: {{#if a}}A{{#if b}}B{{/if}}C{{/if}}   attrs: a=true b=true
output:   A{{#if b}}BC{{/if}}
```
Non-greedy `.*?` pairs the first `{{#if}}` with the first `{{/if}}`. Either document that nesting is unsupported (and tell the model so in the system prompt), or implement a small recursive/stack-based parser. A proper tokenizer would also let PHP and JS share one grammar spec and fix BUG-1 structurally.

### BUG-3 — Block wrapper is never applied on the front end; `align`, `anchor`, `className` are lost (Confirmed by inspection)
[class-ai-block-renderer.php:56-60, 86-89](../includes/class-ai-block-renderer.php#L56-L89)

`get_block_wrapper_attributes()` is computed, but only substituted if the template contains `{{wrapper_attributes}}`. The system prompt never mentions that placeholder, so no generated template contains it (the saved `testimonial-card` doesn't), and the comment "or wrap if not" is not implemented. Result: `supports.align/anchor` declared in [ai-block-creator.php:121-125](../ai-block-creator.php#L121-L125) do nothing on the front end, and the `.ai-block-{slug} .ai-custom-block` scoping class the editor adds is absent on the front end, so CSS scoped to it will not match.

**Fix:** always wrap: `sprintf( '<div %s>%s</div>', $wrapper_attributes, $rendered )` unless the placeholder is present. Remove the placeholder feature or document it in the prompt.

### BUG-4 — Editor preview does not escape interpolated values; PHP does (By inspection)
[dynamic-block-factory.js:63, 77, 83](../src/runtime/dynamic-block-factory.js#L63)

`interpolateTemplate()` inserts attribute values raw into HTML that is then rendered with `RawHTML` / `dangerouslySetInnerHTML`. Two consequences: (a) preview and front end diverge for any value containing `<`, `&`, quotes; (b) an attribute value like `<img src=x onerror=…>` saved into post content by one author executes in every other editor's session when they open the post. Escape in JS exactly as PHP does (`escapeHTML` from `@wordpress/escape-html`), and share the escaping rules with `{{{raw}}}` (which should be kses'd server-side and *not* offered in the editor preview, or run through DOMPurify).

### BUG-5 — Voice input aborts itself and duplicates interim results (By inspection)
[VoiceInput.js:15-57](../src/components/VoiceInput.js#L15-L57), [AIBlockCreatorModal.js:47-49](../src/components/AIBlockCreatorModal.js#L47-L49)

- The `useEffect` depends on `onTranscript`. The modal passes a new inline function on every render, so every state change (including the `setPrompt` triggered by a transcript) tears down and rebuilds the recognizer — calling `.abort()` mid-dictation.
- `interimResults = true` and the handler *appends* each result to the prompt, so the prompt becomes "hello hello world hello world foo".

**Fix:** store the callback in a ref; create the recognizer once; keep interim text in local state and only commit `event.results[i].isFinal` transcripts. Also handle `not-allowed` (mic permission) with a visible message instead of `console.warn`.

### BUG-6 — Preview attribute state goes stale after refinement (By inspection)
[BlockPreview.js:16-24](../src/components/BlockPreview.js#L16-L24)

`useState(() => defaults)` runs once. After a "Refine" turn returns a new definition with new/renamed attributes, `previewAttrs` still holds the old keys, so new attributes render empty in the Live Preview tab. Reset when `blockDef` changes (a `useEffect` on `blockDef.name`/`blockDef.attributes`, or `key={blockDef.name + turnIndex}` on the component). Also: hooks are called after an early `return null` (rules-of-hooks violation; `wp-scripts lint-js` would flag it).

### BUG-7 — Generate button enabled with screenshot-only input, but the server rejects it (Confirmed by inspection)
[AIBlockCreatorModal.js:53, 284](../src/components/AIBlockCreatorModal.js#L53), [class-ai-block-rest-controller.php:131-133](../includes/class-ai-block-rest-controller.php#L131-L133)

Client allows `!prompt && screenshot`; server returns 400 `Prompt cannot be empty`. Either default the prompt server-side ("Recreate this UI as a block") when an image is present, or require text client-side.

### BUG-8 — `save_block()` does not normalize; the stored definition in the DB is already missing `edit_fields` (Confirmed)
[class-ai-block-rest-controller.php:376-426](../includes/class-ai-block-rest-controller.php#L376-L426)

`normalize_block_definition()` only runs in generate. The saved `testimonial-card` row (post 63) has no `edit_fields` key, which means the JS fallback silently synthesized controls. Save must re-run a strict normalizer/validator (see ARCH-1) rather than trusting the client.

### BUG-9 — Slug collisions silently overwrite other blocks; trashed blocks are "updated" but never registered (By inspection)
[class-ai-block-rest-controller.php:390-411](../includes/class-ai-block-rest-controller.php#L390-L411)

Lookup by `post_name` with `post_status => 'any'`: a second user generating a block with the same slug overwrites the first user's definition with no ownership check; if the existing post is in the trash, meta is updated but status stays `trash`, so registration (`post_status => 'publish'`) never sees it. Force `post_status => 'publish'` on update and consider `wp_unique_post_slug()` for new blocks when the slug is taken by a different author.

### BUG-10 — Block names can be invalid for `register_block_type()` (By inspection)
[class-ai-block-rest-controller.php:302](../includes/class-ai-block-rest-controller.php#L302), [ai-block-creator.php:99-114](../ai-block-creator.php#L99-L114)

`sanitize_title()` percent-encodes non-ASCII (e.g. a title in Japanese → `%e3%83%96…`), which fails the `^[a-z0-9-]+/[a-z0-9-]+$` block-name rule and triggers `_doing_it_wrong` on every `init`. Use `sanitize_key()`-style filtering with a fallback slug.

### BUG-11 — Attribute `type` and `default` are not validated (By inspection)
[ai-block-creator.php:104-112](../ai-block-creator.php#L104-L112), [dynamic-block-factory.js:139-148](../src/runtime/dynamic-block-factory.js#L139-L148)

`type` is passed straight through; a model returning `"type": "text"` or `"color"` yields an invalid block attribute schema and console errors in the editor. `default` falls back to `''` even for `boolean`/`number`. Allowlist `string|boolean|number|integer|array|object`, coerce defaults to the type, and default `edit_fields[].type` from the attribute type.

### BUG-12 — `history` is accepted but never used; `wp_ai_client_default_request_timeout` filter has no effect (Confirmed)
[class-ai-block-rest-controller.php:128, 146-176](../includes/class-ai-block-rest-controller.php#L128)

- `$history` is read and discarded. The UI sends the whole conversation (including full block JSON per turn) every time for nothing. `PromptBuilder::withHistory( Message ...$messages )` exists in core.
- The timeout filter lives in `WP_AI_Client_Prompt_Builder::__construct()` ([wp-includes/ai-client/class-wp-ai-client-prompt-builder.php:202](file:///Users/georgestephanis/Studio/test/wp-includes/ai-client/class-wp-ai-client-prompt-builder.php#L202)). The plugin calls `\WordPress\AiClient\AiClient::prompt()` / `::generateTextResult()` directly, so the wrapper is never constructed and the filter is never applied. The library default is used. Same bypass skips `wp_supports_ai()` and the `wp_ai_client_prevent_prompt` filter. Contradicts AGENTS.md rule #1.

**Fix:** use `wp_ai_client_prompt()` (or `->usingRequestOptions( RequestOptions::fromArray( [ 'timeout' => 300 ] ) )` if staying on the raw builder), and remove the closure filter that is added inside a request handler and never removed.

### BUG-13 — Fenced-JSON extraction regex never matches real output (Confirmed by inspection)
[class-ai-block-rest-controller.php:215](../includes/class-ai-block-rest-controller.php#L215)

`\{.*?\}` with `/s` stops at the first `}` inside the object, so the fenced path always fails and falls through to the first-`{`/last-`}` heuristic (which works). Dead code, but a sign the parsing is fragile. Core's builder offers `asJsonResponse( ?array $schema )` — pass the block JSON schema and let the provider enforce structure; keep the heuristic as fallback.

### BUG-14 — `sanitize_textarea_field()` mangles legitimate prompts (By inspection)
[class-ai-block-rest-controller.php:126](../includes/class-ai-block-rest-controller.php#L126)

It strips tags and encoded octets, so a prompt like "use an `<h2>` for the title" or "put the price in `<strong>`" loses the tags before the model sees it. The prompt is never rendered as HTML; `trim()` + a length cap (and `wp_check_invalid_utf8`) is enough.

### BUG-15 — Slash-command placeholder loses insertion point (By inspection)
[index.js:112-116](../src/index.js#L112-L116), [AIBlockCreatorModal.js:140-141](../src/components/AIBlockCreatorModal.js#L140-L141)

The `ai-block/generator` placeholder removes itself when opening the modal, so the eventual `insertBlocks()` lands after whatever is selected (or at the end), not where the user typed `/ai-block`. Keep the placeholder, pass its `clientId` through the open event, and `replaceBlocks( clientId, newBlock )` on insert; remove it on cancel.

### BUG-16 — Insert/registration uses the client's definition, not the server's normalized one (By inspection)
[AIBlockCreatorModal.js:116-141, 163-175](../src/components/AIBlockCreatorModal.js#L116-L141)

`registerDynamicAiBlock( currentBlock )` runs *before* the save; the server may rename the slug (`sanitize_title`) and attach `id`. Register and `createBlock()` from `response.block` instead, and only after a successful save. Also `handleInsertIntoPost` and `handleSaveToLibrary` duplicate ~25 lines.

### BUG-17 — `catch (\Exception)` misses `\Error`/`TypeError` from the AI client (By inspection)
[class-ai-block-rest-controller.php:196](../includes/class-ai-block-rest-controller.php#L196)

A `TypeError` inside the library becomes a WSOD/500 with no JSON body. Catch `\Throwable`.

---

## Architecture / Performance

### ARCH-1 — No single source of truth for a definition's schema
Four places each re-implement "load all definitions" (`register_dynamic_blocks`, `enqueue_editor_assets`, `enqueue_frontend_styles`, `get_blocks`) and two re-implement "normalize". Introduce a `Block_Definition` value object (or at least `Block_Store::all()`, `::get( $slug )`, `::save( array )`, `::delete( $id )`) that owns: strict schema validation/allowlisting, sanitization, the meta key, and caching. Everything else consumes it.

### ARCH-2 — CSS delivery is duplicated, global, and unscoped to block presence
- Front end: **all** blocks' CSS is inlined into `<head>` on **every** page ([ai-block-creator.php:214-239](../ai-block-creator.php#L214-L239)) *and* again as an inline `<style>` in the body when the block renders ([class-ai-block-renderer.php:62-67](../includes/class-ai-block-renderer.php#L62-L67)).
- Editor: `injectBlockStyles()` appends to the top-level `document.head` ([dynamic-block-factory.js:95-107](../src/runtime/dynamic-block-factory.js#L95-L107)). Blocks are `apiVersion: 3`; when the canvas is iframed (Site Editor always, Post Editor when every registered block is v3) those styles never reach the canvas and the block renders unstyled in the editor.

**Fix:** per block, `wp_register_style( "ai-block-{slug}", false )` + `wp_add_inline_style()` and pass `'style' => $handle, 'editor_style' => $handle` to `register_block_type()`. WordPress then enqueues only when the block is present and mirrors it into the editor iframe. For not-yet-reloaded blocks created in the current session, inject into `useBlockProps()`'s `ref.current.ownerDocument` or use `useStyleOverride` from `@wordpress/block-editor`. Drop `enqueue_frontend_styles()` and the renderer's `<style>` tag.

### ARCH-3 — `get_block_definition()` queries per render, ignoring what `init` already loaded
[class-ai-block-renderer.php:181-207](../includes/class-ai-block-renderer.php#L181-L207)

Every block instance on a page runs `get_posts()`. Pass the definition (or slug → post ID) into the registered block type at `init` and read it from a static map, or cache `Block_Store::all()` in a transient/object cache invalidated on save/delete.

### ARCH-4 — Header toolbar button is DOM-polled and non-React
[index.js:39-66](../src/index.js#L39-L66)

`setInterval` + `innerHTML` + hard-coded English + `.interface-interface-skeleton__header` fallback. It disappears when the header re-renders (fullscreen toggle, template mode) and never re-appears after the 5 s window. Options: render it via a React portal that observes the header with `MutationObserver`; or drop it and rely on the `/ai-block` inserter item + More Menu (already present) + a `PluginSidebar`. Whichever you choose, use `__()`.

### ARCH-5 — `@wordpress/edit-post` `PluginMoreMenuItem` is deprecated and ties the plugin to the Post Editor
[index.js:6](../src/index.js#L6)

Since WP 6.6 the slot lives in `@wordpress/editor`. Using the `edit-post` version logs a deprecation and loads `wp-edit-post` in the Site Editor. Import from `@wordpress/editor`.

### ARCH-6 — Provider-specific option hard-coded for every provider
[class-ai-block-rest-controller.php:155](../includes/class-ai-block-rest-controller.php#L155)

`chat_template_kwargs.enable_thinking=false` is a vLLM/Qwen detail. The installed connector already has its own `disable_thinking` setting (`connectors_ai_openai_compatible_servers_disable_thinking = 1`). Make the custom option filterable (`ai_block_creator_model_config`) and default it off unless the detected provider is vLLM-like; otherwise it is sent as an unknown parameter to OpenAI/Anthropic/Google.

### ARCH-7 — CPT slug `wp_block_def` uses the reserved `wp_` prefix
[ai-block-creator.php:41](../ai-block-creator.php#L41)

Core reserves `wp_*` post types (`wp_block`, `wp_template`, …). Rename to `ai_block_def` with a one-time migration, or accept the risk consciously. Also register the meta via `register_post_meta()` with a `sanitize_callback` so any write path is sanitized.

### ARCH-8 — Rendering happens on `init` for every request type
[ai-block-creator.php:130](../ai-block-creator.php#L130)

`register_dynamic_blocks()` runs the CPT query on cron, REST, XML-RPC, admin, etc. Acceptable with caching (ARCH-3), but the category filter should be registered once at file scope rather than inside the `init` callback.

### ARCH-9 — Preview HTML is executed in the admin document
[BlockPreview.js:80-83](../src/components/BlockPreview.js#L80-L83), [dynamic-block-factory.js:262](../src/runtime/dynamic-block-factory.js#L262)

AI-authored HTML goes straight into the wp-admin DOM, and AI-authored CSS is injected globally (a `body { … }` rule from the model restyles the whole admin). Render the modal preview in a sandboxed `<iframe srcdoc>` (also gives accurate theme isolation and lets you load the theme's stylesheet), and sanitize with DOMPurify or `wp.dom.safeHTML` before `RawHTML` in the block `edit`.

---

## UX / Product

- **UX-1** No feedback when AI isn't available. `hasAiClient` is localized ([ai-block-creator.php:206](../ai-block-creator.php#L206)) but never read in JS; also does not check `wp_supports_ai()` or whether a provider is configured/has credentials. Show a Notice in the modal with a link to Settings → Connectors.
- **UX-2** Modal state persists across open/close (conversation, block, screenshot). "Cancel" should reset, or offer "Start over".
- **UX-3** `ColorPalette` is rendered with no `colors` prop ([dynamic-block-factory.js:214](../src/runtime/dynamic-block-factory.js#L214)) so it shows only the custom picker. Use `useSettings( 'color.palette' )` or `PanelColorSettings`.
- **UX-4** Preview "Attributes & Controls" tab falls back to `TextControl` for `color`, `number`, `select` ([BlockPreview.js:95-132](../src/components/BlockPreview.js#L95-L132)), inconsistent with the real inspector. Share one `renderField()` between the factory and the preview.
- **UX-5** Nothing manages saved blocks. No list/delete UI exists even though `DELETE` is implemented. A small `PluginSidebar` or Settings page listing blocks (with delete + "reuse in creator") would close the loop and also expose `show_ui` safely.
- **UX-6** Error surface: raw model output is appended to the error string ([class-ai-block-rest-controller.php:182](../includes/class-ai-block-rest-controller.php#L182)) and shown verbatim in the Notice. Show a friendly message with a "show raw" disclosure.
- **UX-7** No i18n anywhere in JS: every string is a bare literal, `wp-i18n` isn't a dependency, and `wp_set_script_translations()` is never called despite `Text Domain: ai-block-creator`. Emoji-in-labels (`✨ Generate Block`, `🚀 Insert into Post`) also reads as unpolished in a WP admin context; consider `@wordpress/icons` instead.
- **UX-8** Hard-coded light palette in `styles.scss` (`#f8fafc`, `#1e293b`, …) with heavy `!important` overrides of `.components-modal__*`. Won't follow admin color schemes and is brittle against `@wordpress/components` updates.

---

## Docs, Packaging, DX

- **DX-1** Version requirements are inconsistent: plugin header and `readme.txt` say `Requires at least: 6.7`; README says 7.0+; the code hard-requires the 7.0 AI Client. Set `Requires at least: 7.0` everywhere. Consider a `Requires Plugins:` header pointing at a connector, or an admin notice when none is registered.
- **DX-2** `README.md` links to a `LICENSE` file that does not exist. Add GPL-2.0-or-later text.
- **DX-3** `blueprint.json` uses `"resource": "git:directory", "directory": "."`, which is not a valid Playground resource shape (`git:directory` takes `url`/`ref`/`path`). It also installs the connector but never configures a base URL/API key, so the demo cannot generate anything. Either switch to a `url` resource pointing at a GitHub zip and add `setSiteOptions`/`wp-cli` steps to configure a provider, or document that manual setup is needed. The `blueprint` skill can validate this.
- **DX-4** `build/` is committed (fine for git installs and Playground) but there is no guard that it matches `src/`. Add a CI job that runs `npm ci && npm run build` and fails on diff, or stop committing it and produce release zips.
- **DX-5** No lint or test tooling: no `phpcs.xml` / `composer.json`, `lint-js`/`lint-style` scripts, or PHPUnit/Jest. `wp-scripts lint-js` would already have caught the rules-of-hooks issue (BUG-6). The renderer is pure and trivially unit-testable — the edge cases in this review are ready-made test fixtures.
- **DX-6** No `uninstall.php`; definitions remain in the DB after uninstall. Also no activation `flush_rewrite_rules()` is needed (rewrite is false) — good — but a version option for future migrations would help (ARCH-7).
- **DX-7** `wp_localize_script()` is used for structured data; `wp_add_inline_script( …, 'var aiBlockCreatorSettings = ' . wp_json_encode() )` is the modern equivalent and avoids string-casting of nested values. Nonce header is also set manually in every `apiFetch` call even though the editor's `apiFetch` middleware already sends it.
- **DX-8** REST `args` are under-specified: `history` is `type: array` with no `items`, `current_block`/`block_definition` are `type: object` with no `properties`/`additionalProperties`. With a real schema WordPress validates and sanitizes for you and the fields document themselves in `OPTIONS`.
- **DX-9** `AGENTS.md` rule #1 ("always use `wp_ai_client_prompt()`") is violated by the current controller; update whichever side is wrong after BUG-12 is fixed.
- **DX-10** Stray local files (`conversation-log.jsonl`, `.serena/`) are only ignored by the user's global gitignore; add them to the repo `.gitignore` so other contributors don't commit them.

---

## Things that are fine (no action)

- `wp_slash( wp_json_encode() )` before `update_post_meta()` — correct, and the AGENTS.md note is accurate.
- `save: () => null` + PHP `render_callback` — correct dynamic-block pattern.
- `_ai_block_definition` as a protected (underscore) meta key.
- PHP files pass `php -l` on PHP 8.5; `declare(strict_types=1)` is used consistently and no type errors surfaced in the render tests.
- `str_starts_with()` is polyfilled by core since 5.9, so the 7.4 floor is honest.
