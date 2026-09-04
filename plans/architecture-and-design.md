# AI Block Creator: Architecture & Design Plan

## 1. Vision & Overview
The AI Block Creator plugin enables WordPress creators to "speak it, type it, or screenshot it into existence" directly inside the Block Editor (Gutenberg) without context switching.

Users can:
1. **Type** a prompt describing the desired block (e.g. *Pricing table with 3 tiers*, *Interactive FAQ accordion*, *Author profile badge with social links*).
2. **Speak** their requirements using in-browser Web Speech voice recognition.
3. **Drop / Paste a screenshot** of any UI component or mockup.
4. **Conversational Refinement**: Iterate with AI inside the editor modal before committing.
5. **Live Preview**: Inspect and interact with the rendered block and its inspector controls in real time.
6. **Insert & Register**: Instantly registers the block as a native Gutenberg block type on the WordPress site and inserts it at the current editor cursor.

---

## 2. Technical Architecture

### 2.1 Backend (PHP / WordPress)
- **CPT `ai_block_def`** (`AI_Block_Store::POST_TYPE`): Stores custom AI block definitions (slug, title, icon, attributes schema, edit fields, render template, scoped CSS). Read/write access to these posts goes exclusively through `AI_Block_Store` — it's the only class permitted to call `get_posts()`/`get_post_meta()`/`update_post_meta()` against them, since it's also where validation and caching live.
- **Dynamic Registration (`register_block_type`)**:
  - Automatically loads all published `ai_block_def` posts on `init` via `AI_Block_Store::all()` (request-scoped cached).
  - Configures attributes and server-side render callback `AI_Block_Renderer::render()`.
  - Registers each block's CSS as its own `wp_register_style()`/`wp_add_inline_style()` handle, passed to `register_block_type()` as `style`/`editor_style` — WordPress then enqueues it only on pages/editor sessions where that specific block is present, rather than inlining every saved block's CSS into every page.
  - These registration/rendering conventions (apiVersion 3, `get_block_wrapper_attributes()`, `render_callback`, `save: () => null`, registering on `init`) follow WordPress's official [`wp-block-development` agent skill](https://github.com/WordPress/agent-skills/tree/trunk/skills/wp-block-development) — see `AGENTS.md` §4 for the specific citations and the one deliberate divergence (no `block.json`, since these blocks are generated at runtime rather than authored as files).
- **REST Controller (`/wp-json/ai-block-creator/v1/`)**:
  - `POST /generate`: Integrates with WordPress 7.0+ AI Client (`wp_ai_client_prompt()`) to generate structured block definitions, including conversation history (`withHistory()`) and a JSON response schema (`asJsonResponse()`). Handles vision/multimodal requests when screenshots are uploaded (MIME-allowlisted, capped at 4MB). Requires `edit_posts` — nothing is persisted by this endpoint.
  - `GET /blocks`: Returns all saved custom block definitions. Requires `edit_posts`.
  - `POST /blocks`: Saves/updates a custom block definition to the database. Requires `unfiltered_html` — the saved definition is served to every site visitor, so `edit_posts` alone is not sufficient here even though it is for generating a draft.
  - `DELETE /blocks/<id>`: Deletes a custom block definition, after verifying the target post is actually an `ai_block_def` (not an arbitrary post ID). Requires `unfiltered_html`.
- **Security & Permissions**:
  - REST endpoints are split across two capability levels (`edit_posts` for read/generate, `unfiltered_html` for save/delete) rather than one blanket check, since generating a draft and publishing something every visitor's browser will load are very different levels of risk. WordPress REST nonces are handled by the block editor's built-in `apiFetch` middleware.
  - Every block definition — whether it came from the AI or was POSTed directly to `/blocks` — passes through `AI_Block_Store::normalize_and_validate()` before being stored: unknown top-level keys are dropped, attribute/edit-field types are allowlisted, `render_html` is passed through `wp_kses_post()` for anyone without `unfiltered_html`, and `css` always has script/style-breakout constructs stripped regardless of capability.
  - The renderer (`AI_Block_Renderer::render_template()`) escapes each interpolated `{{var}}` according to the attribute context it appears in — `esc_url()` inside `href`/`src`, `safecss_filter_attr()` inside `style="..."`, `esc_html()` everywhere else — rather than one escaper for every context.

### 2.2 Frontend (React / Gutenberg)
- **Editor Entrypoints**:
  - Header Toolbar Action: Sparkle AI icon button in the top document bar.
  - Block Inserter: "Create Custom Block with AI" item.
  - Slash Command: `/ai-block` or `/create-block`.
- **Creation Modal & Chat Drawer**:
  - Prompt text area with suggestion pills.
  - Web Speech API microphone dictation button.
  - Drag-and-drop & clipboard paste listener (`Cmd+V`) for UI screenshot images.
  - Multi-turn conversation log with prompt history & AI responses.
  - Live interactive block preview canvas.
- **Dynamic Block Runtime (`dynamic-block-factory.js`)**:
  - Client-side `wp.blocks.registerBlockType()` executes immediately upon block creation so the block can be inserted into the post right away without a page refresh.
  - Generates interactive Gutenberg `edit` component with `useBlockProps`, `InspectorControls` (for configuring attributes like colors, text, toggles, layout), and `RichText` elements.

---

## 3. Two-Stage Generation: Planning, Then Building

Not every "make me a block for X" request should become a block. A large class of
them is really "make an existing block look or behave like X" — and answering
those with a bespoke `ai-block/*` block produces something less discoverable and
more fragile than the thing WordPress already has for the job. So generation runs
in two stages.

**Stage one — planning** (`POST /ai-block-creator/v1/plan`, or implicitly inside
`/generate`). A small, fast model call classifies the request into one of three
kinds and names a target block. It writes no code. It returns:

```json
{ "kind": "block_style", "target_block": "core/quote", "rationale": "One sentence for the author." }
```

| kind | Registered via | What the author gets |
| --- | --- | --- |
| `custom_block` | `register_block_type()` + `AI_Block_Renderer` | A brand-new block with its own fields, in the AI Custom Blocks category |
| `block_style` | `register_block_style()` | A new option in an existing block's Styles panel; CSS only |
| `block_variation` | the `get_block_type_variations` filter | A preset of an existing block in the inserter |

`target_block` is validated against a curated candidate list intersected with the
site's block registry (`AI_Block_REST_Controller::candidate_target_blocks()`,
filterable via `ai_block_creator_target_block_candidates`). The whole registry is
neither useful nor safe to put in a prompt: a site with a dozen plugins has
hundreds of registered blocks, most of which nobody wants an AI-authored style on.

If the planner fails for any reason, the request falls back to `custom_block` —
what this plugin did for every request before planning existed — rather than
failing outright. The returned `plan.source` (`planner`, `explicit`, `refinement`,
or `fallback`) says which path produced the decision, so the UI can tell the
author when the model wasn't actually consulted.

**Stage two — building.** The planned kind selects the system prompt
(`build_block_style_prompt()`, `build_block_variation_prompt()`, or
`build_custom_block_prompt()`), and the plan's `kind`/`target_block` are stamped
onto the result afterwards. The generator does not get to re-decide what it is
building: a model asked to write a style has no reason to be trusted to declare
itself something else, and letting it contradict the plan would route the output
through the wrong normalizer and the wrong registration API.

The author can override the decision from the modal ("Build this as: …"), which
sends an explicit `kind` and skips stage one. A *refinement* turn keeps the kind
of the definition being refined, so a follow-up can't silently turn a saved style
into a new block.

### Kind precedence and storage

All three kinds share the `ai_block_def` post type, discriminated by a `kind`
field. A definition with no `kind` is a `custom_block` — that's every definition
saved before this existed, and they keep validating and registering exactly as
they always did.

Because `post_name` is the uniqueness key that decides create-vs-update, kinds are
namespaced against each other (`{slug}`, `style-{slug}`, `variation-{slug}`);
without that, saving a style named "callout" would overwrite the custom block
named "callout". Custom blocks keep their bare slug for backward compatibility.

A style's `name` is also its `.is-style-{name}` class, already written into every
post that uses it, so refinements pin the stored name rather than letting the
model rename it and orphan that content.

---

## 4. Data Schema for AI Block Definition

### 4.1 `custom_block` (the default kind)

```json
{
  "name": "ai-block/pricing-table",
  "title": "Modern Pricing Table",
  "description": "A 3-tier responsive pricing comparison card grid",
  "icon": "money-alt",
  "category": "widgets",
  "attributes": {
    "title": { "type": "string", "default": "Pro Plan" },
    "price": { "type": "string", "default": "$29/mo" },
    "features": { "type": "string", "default": "Unlimited projects\n24/7 Support\nCustom domain" },
    "buttonText": { "type": "string", "default": "Get Started" },
    "buttonUrl": { "type": "string", "default": "#" },
    "accentColor": { "type": "string", "default": "#3b82f6" },
    "isFeatured": { "type": "boolean", "default": true }
  },
  "edit_fields": [
    { "name": "title", "label": "Plan Title", "type": "text" },
    { "name": "price", "label": "Price", "type": "text" },
    { "name": "features", "label": "Features (one per line)", "type": "textarea" },
    { "name": "buttonText", "label": "Button Label", "type": "text" },
    { "name": "buttonUrl", "label": "Button URL", "type": "url" },
    { "name": "accentColor", "label": "Accent Color", "type": "color" },
    { "name": "isFeatured", "label": "Highlight as Featured", "type": "toggle" }
  ],
  "render_html": "<div class=\"ai-block-pricing-card{{#if isFeatured}} featured{{/if}}\" style=\"--accent: {{accentColor}};\">...</div>",
  "css": ".ai-block-pricing-card { border-radius: 12px; padding: 24px; ... }"
}
```

The `render_html` mini-template language supports:
- `{{attributeName}}` — interpolates a value, escaped for the attribute context it appears in (URL inside `href`/`src`, CSS inside `style="..."`, HTML text otherwise).
- `{{{attributeName}}}` — raw/rich HTML output (triple braces), passed through `wp_kses_post()` for anyone without `unfiltered_html`. Only use this when the attribute is genuinely meant to hold markup.
- `{{#if attributeName}}...{{/if}}` / `{{^if attributeName}}...{{/if}}` — boolean conditionals, which may be nested.
- `{{#list attributeName}}<li>{{item}}</li>{{/list}}` — repeats the inner template once per line (or per array item), with `{{item}}` bound to each one. `{{item}}` is only available inside a `{{#list}}` block, not other attribute names.

This grammar is implemented twice and must stay in sync: `AI_Block_Renderer::render_template()` (PHP, front end) and `interpolateTemplate()` in `src/runtime/dynamic-block-factory.js` (editor preview).

### 4.2 `block_style`

CSS and nothing else. No `render_html`, no `attributes`, no `edit_fields` — the
target block renders itself, with one extra class. `AI_Block_Renderer` is never
involved, so there is no template for a caller to smuggle markup through.

```json
{
  "kind": "block_style",
  "name": "ai-gold-pullquote",
  "label": "Gold Pull-Quote",
  "description": "Gold accents and a prominent serif quotation mark.",
  "target_block": "core/pullquote",
  "css": ".is-style-ai-gold-pullquote { … }"
}
```

Every selector in `css` must be scoped to `.is-style-{name}`. That's enforced by
the prompt, not by a parser — unscoped selectors are a style-bleed bug, not a
security one, and the same `sanitize_css()` pass that protects custom blocks
(stripping `<script>`/`<style>`/`@import`/`expression()`/`javascript:`) applies here.

### 4.3 `block_variation`

A named preset of an existing block.

```json
{
  "kind": "block_variation",
  "name": "ai-two-col-feature",
  "title": "Two Column Feature",
  "icon": "columns",
  "target_block": "core/media-text",
  "attributes": { "align": "wide", "mediaPosition": "left" },
  "inner_block_names": [ "core/column", "core/column" ],
  "css": ""
}
```

Note that a variation's `attributes` are concrete **values** to preset on the
target block, not the `{ type, default }` **schema** a custom block declares.
They go through `sanitize_attribute_values()`, not `sanitize_attributes()`;
running the schema sanitizer over them would discard every one.

`inner_block_names` is a flat, length-capped list of block names, not a recursive
`innerBlocks` template. One level covers the cases this is for (a two-column
layout, a heading-plus-paragraph pairing) without inviting an AI to emit an
arbitrarily deep tree that has to be validated node by node. Names that aren't
registered on the site are dropped rather than substituted.

---

## 5. Verification & Testing Strategy
- Verify PHP syntax and plugin activation via WP-CLI.
- Verify REST endpoint responses using `wp_ai_client_prompt()`.
- Test voice dictation fallback and screenshot handling.
- Verify live insertion into post content, serialization to post HTML, and frontend dynamic rendering.
