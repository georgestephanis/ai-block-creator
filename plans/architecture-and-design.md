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
- **CPT `ai_block_def`** (`AI_Block_Store::POST_TYPE`): Stores AI definitions of all four kinds (see §3), discriminated by a `kind` field — a custom block's slug/title/icon/attributes/edit fields/render template/CSS, or a style's target and CSS, or a variation's attribute preset, or a pattern's block markup. Read/write access to these posts goes exclusively through `AI_Block_Store` — it's the only class permitted to call `get_posts()`/`get_post_meta()`/`update_post_meta()` against them, since it's also where validation and caching live.
- **Registration**: each kind registers through the API that WordPress provides for it, on `init` (custom blocks at priority 10; the other three at 20, after core's own blocks exist so target/inner block names can be validated against a populated registry):
  - `custom_block` → `register_block_type()` with `AI_Block_Renderer::render()`.
  - `block_style` → `register_block_style()`, whose `inline_style` WordPress enqueues only where the target block appears.
  - `block_variation` → the `get_block_type_variations` filter. Not the `variations` argument to `register_block_type()`: that only takes effect when a block type is *created*, and the blocks being varied are registered long before this plugin loads.
  - `block_pattern` → `register_block_pattern()`, under an `ai-block-creator` pattern category.
- **Dynamic Registration (`register_block_type`)**:
  - Automatically loads all published `ai_block_def` posts on `init` via `AI_Block_Store::all()` (request-scoped cached).
  - Configures attributes and server-side render callback `AI_Block_Renderer::render()`.
  - Registers each block's CSS as its own `wp_register_style()`/`wp_add_inline_style()` handle, passed to `register_block_type()` as `style`/`editor_style` — WordPress then enqueues it only on pages/editor sessions where that specific block is present, rather than inlining every saved block's CSS into every page.
  - These registration/rendering conventions (apiVersion 3, `get_block_wrapper_attributes()`, `render_callback`, `save: () => null`, registering on `init`) follow WordPress's official [`wp-block-development` agent skill](https://github.com/WordPress/agent-skills/tree/trunk/skills/wp-block-development) — see `AGENTS.md` §4 for the specific citations and the one deliberate divergence (no `block.json`, since these blocks are generated at runtime rather than authored as files).
- **REST Controller (`/wp-json/ai-block-creator/v1/`)**:
  - `POST /plan`: Stage one. Classifies a request into a kind and a target block without generating anything (§3). Requires `edit_posts`.
  - `POST /generate`: Runs both stages. Integrates with WordPress 7.0+ AI Client (`wp_ai_client_prompt()`), including conversation history (`with_history()`) and a per-kind JSON response schema (`as_json_response( $schema )`, §3.1). Accepts an optional `kind`/`target_block` to skip stage one. Handles vision/multimodal requests when screenshots are uploaded (MIME-allowlisted, capped at 4MB). Requires `edit_posts` — nothing is persisted by this endpoint.
  - `GET /blocks`: Returns all saved custom block definitions. Requires `edit_posts`.
  - `POST /blocks`: Saves/updates a custom block definition to the database. Requires `unfiltered_html` — the saved definition is served to every site visitor, so `edit_posts` alone is not sufficient here even though it is for generating a draft.
  - `DELETE /blocks/<id>`: Deletes a custom block definition, after verifying the target post is actually an `ai_block_def` (not an arbitrary post ID). Requires `unfiltered_html`.
- **Security & Permissions**:
  - REST endpoints are split across two capability levels (`edit_posts` for read/generate, `unfiltered_html` for save/delete) rather than one blanket check, since generating a draft and publishing something every visitor's browser will load are very different levels of risk. WordPress REST nonces are handled by the block editor's built-in `apiFetch` middleware.
  - Every definition — whether it came from the AI or was POSTed directly to `/blocks` — passes through `AI_Block_Store::normalize_and_validate()` before being stored, which dispatches on `kind` and allowlists that kind's fields specifically: unknown top-level keys are dropped, attribute/edit-field types are allowlisted, `render_html` is passed through `wp_kses_post()` for anyone without `unfiltered_html`, `css` always has script/style-breakout constructs stripped regardless of capability, and a pattern's `content` is parsed, node-validated and re-serialized (§4.4). A response schema (§3.1) reduces how often the model produces the wrong shape; it never replaces this pass, since not every provider honors one.
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
  - `registerAiDefinition()` dispatches on kind: `registerBlockType()` for custom blocks, `registerBlockStyle()` for styles, `registerBlockVariation()` for variations, and nothing at all for patterns (they have no client-side registry entry; `createBlocksFromDefinition()` parses their markup on insert instead).
  - Styles and variations are registered on `domReady`, not at module scope: they attach to a block someone else registered, and `wp-block-library`'s execution order relative to this bundle isn't guaranteed. Custom blocks stand alone and register immediately.
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
| `block_pattern` | `register_block_pattern()` | A ready-made arrangement of ordinary blocks, inserted and then edited as normal content |

The two easiest to confuse are the middle pair, and the distinction is simply
arity: **one block, preset** is a variation; **several blocks, arranged** is a
pattern. The planner prompt says exactly that, in those words.

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
(`build_block_style_prompt()`, `build_block_variation_prompt()`,
`build_block_pattern_prompt()`, or `build_custom_block_prompt()`) *and* the JSON
Schema sent with the request (§3.1), and the plan's `kind`/`target_block` are
stamped onto the result afterwards. The generator does not get to re-decide what it is
building: a model asked to write a style has no reason to be trusted to declare
itself something else, and letting it contradict the plan would route the output
through the wrong normalizer and the wrong registration API.

The author can override the decision from the modal ("Build this as: …"), which
sends an explicit `kind` and skips stage one. An override to a style or variation
still needs a target block: the client sends the one it already knows, and if it
can't, the server runs stage one purely to obtain one. (Without that, an override
carrying only a `kind` falls through to normalization's `core/group` default and
produces a style on Group — nothing anyone asked for, and indistinguishable from
a broken feature.) A *refinement* turn keeps the kind of the definition being
refined, so a follow-up can't silently turn a saved style into a new block.

### 3.1 Response schemas, and one repair turn

Every call passes a JSON Schema to `as_json_response( $schema )`, so providers
that support structured output are constrained to the right shape rather than
merely asked for it. The planner's schema is the clearest case: its whole job is
to return one of four labels, and the schema makes that an enum rather than a
hope.

A schema is a request, not a guarantee — not every provider honors one — so
`AI_Block_Store`'s normalizers still allowlist every field independently.
`ai_block_creator_use_response_schema` turns schema sending off for a provider
that rejects ours.

Responses are then validated locally with `rest_validate_value_from_schema()`
against a *relaxed* copy of the schema: `relax_schema()` strips
`additionalProperties: false` first, because models routinely add a stray
`confidence` or `notes` key and the normalizers drop unrecognized fields for
free. Spending a round-trip to remove one would be pure waste. The rule is:
repair only what we cannot fix ourselves — missing required fields, wrong types,
bad enum values, and output that isn't JSON at all.

When something does fail that check, there is exactly **one** repair turn: a
fresh call handed the schema, the specific problems, and the previous output,
asked to correct it. One, because each turn is a full round-trip and a model that
can't fix it when told precisely what's wrong won't fix it on a third attempt.
Failing that, the request 502s with the remaining `problems` attached; a
successful repair fires `ai_block_creator_repaired_response`.

This matters most for the silent case. An unparseable response was always a hard
502, which is at least visible. A *merely malformed* one — a style with no `css`,
a pattern with empty `content` — used to sail through the normalizers and reach
the author as a definition that looks fine and does nothing.

### 3.2 Block metadata as an input

A variation presets attributes on a block that already exists, so that block's
own `block.json` — reachable at runtime as `WP_Block_Type::$attributes` — is the
authority on which attribute names are real. It is used three ways, all from the
same source so they cannot disagree:

- `attribute_schema_for()` builds the variation schema's `attributes` fragment
  from it, so structured-output providers are *structurally* prevented from
  inventing attribute names.
- `describe_block_attributes()` renders the same list into the prompt, so
  providers without structured output are told the same facts.
- `filter_to_block_attributes()` drops anything the target doesn't declare on the
  way into storage.

The third is the one that has to exist. An invented attribute isn't dangerous,
but it is silently inert: it reads like configuration and does nothing, and the
author has no way to tell the difference. All three degrade to permissive when
the target block isn't registered (which includes running before `init`), so an
unknown target keeps what it was given rather than losing all of it.

### 3.3 Kind precedence and storage

All four kinds share the `ai_block_def` post type, discriminated by a `kind`
field. A definition with no `kind` is a `custom_block` — that's every definition
saved before this existed, and they keep validating and registering exactly as
they always did.

Because `post_name` is the uniqueness key that decides create-vs-update, kinds are
namespaced against each other (`{slug}`, `style-{slug}`, `variation-{slug}`,
`pattern-{slug}`), and `get()` searches all four;
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

Every selector in `css` is confined to `.is-style-{name}` by `scope_css()`, on top
of the same `sanitize_css()` pass that protects custom blocks (stripping
`<script>`/`<style>`/`@import`/`expression()`/`javascript:`).

The prompt asks for scoped selectors and a well-behaved response passes through
byte-identical — but a style is registered site-wide, so a single unscoped
`blockquote { … }` restyles every quote on the site, silently, on pages the author
isn't looking at. Instruction is the wrong enforcement mechanism for that.

`scope_css()` is deliberately not a full CSS parser. It tracks braces, strings and
comments well enough to tell a selector list from a declaration block, and leaves
anything it doesn't understand alone. The behaviors that matter:

- A bare type selector is ambiguous — `blockquote` may mean the block's own root
  element or a descendant of it — so both forms are emitted
  (`.is-style-x blockquote, .is-style-x:is(blockquote)`). A compound selector
  can't describe a single element, so it only gets the descendant form.
- `@keyframes` bodies are left alone: their contents are keyframe selectors
  (`from`, `to`, `50%`), and scoping them would corrupt the animation.
  `@media`/`@supports`/`@container`/`@layer` are recursed into.
- Commas inside `:is(a, b)`, `[attr="a,b"]` and comments are not split on.
- `:root`, `html` and `body` map onto the block root. Scoping them the usual way
  would produce a selector that can never match, silently deleting (for instance)
  the custom properties the rest of the style depends on.
- A trailing unclosed rule is scoped and closed rather than passed through — a
  browser closes it for us and applies it, so passing it through would leak.

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

A variation that carries CSS also gets an `ai-variation-{name}` class minted into
its `className` preset, and its CSS scoped to that class — its stylesheet is
enqueued for the entire target block *type*, so an unscoped selector would reach
every instance of that block rather than only the ones using the variation.

Those values nest further than a first glance suggests — core's own markup carries
`style.spacing.padding.top` and `style.elements.link.color.text` — so
`MAX_ATTRIBUTE_DEPTH` sits above the deepest shape WordPress itself emits. When a
branch does exceed it, the key is omitted rather than kept as an empty array:
`{"style":{"elements":[]}}` isn't a truncated object, it's an array where
Gutenberg expects an object, and a malformed attribute is worse than a missing
one.

`inner_block_names` is a flat, length-capped list of block names, not a recursive
`innerBlocks` template. One level covers the cases this is for (a two-column
layout, a heading-plus-paragraph pairing) without inviting an AI to emit an
arbitrarily deep tree that has to be validated node by node. Names that aren't
registered on the site are dropped rather than substituted.

### 4.4 `block_pattern`

An arrangement of blocks that already exist, stored as serialized block markup.

```json
{
  "kind": "block_pattern",
  "name": "ai-hero-with-cta",
  "title": "Hero With Call To Action",
  "description": "A centred heading, subheading and two buttons.",
  "keywords": [ "hero", "banner" ],
  "viewport_width": 1200,
  "content": "<!-- wp:group --><div class=\"wp-block-group\">…</div><!-- /wp:group -->"
}
```

`content` cannot be string-filtered the way `render_html` is. Block delimiters
are HTML comments, and `wp_kses()` strips comments outright — running it over the
raw markup would destroy the pattern rather than clean it. So
`sanitize_pattern_content()` round-trips instead: `parse_blocks()` → validate
each node → `serialize_blocks()`. Parsing is also what makes the check that
actually matters possible — that every block in the tree is one this site has
registered. Unregistered blocks are dropped (a reference to one renders as a
broken-block warning), each node's literal HTML goes through the same
`sanitize_render_html()` pass as everything else, depth and total size are capped.

One subtlety worth knowing before editing that walk: a parsed block's
`innerContent` interleaves literal HTML chunks with one `null` per inner block,
in order, and `serialize_blocks()` refills the nulls positionally. Dropping an
inner block therefore means dropping its `null` too, or every later sibling gets
paired with the wrong slot.

Patterns are the one kind with nothing to register client-side: they reach the
inserter via the editor settings rendered at page load, so a newly created
pattern joins that list on the next editor load. Inserting one works immediately
regardless, because `createBlocksFromDefinition()` just parses the markup into
ordinary blocks. Nothing about the pattern survives insertion — there is no live
link back to the definition — which is precisely what makes it the right answer
for "an arrangement I want to start from".

---

## 5. Verification & Testing Strategy
- Verify PHP syntax and plugin activation via WP-CLI.
- Verify REST endpoint responses using `wp_ai_client_prompt()`.
- Test voice dictation fallback and screenshot handling.
- Verify live insertion into post content, serialization to post HTML, and frontend dynamic rendering.

The PHPUnit suite boots a real WordPress install (see `tests/php/bootstrap.php`),
which is what makes the registration and metadata tests meaningful — they assert
against the actual block, style, variation and pattern registries rather than
mocks. Tests must not make model calls, though: a route test that reaches
`/generate`'s handler will hit whatever provider the site has configured, taking
a network round-trip per run and failing on a site with none. Assert route
contracts structurally (`rest_get_server()->get_routes()`) instead.

Suites and what each is for:
- `BlockStoreTest` / `RendererTest` — the original validation, sanitization and
  template-rendering behavior.
- `DefinitionKindsTest` — per-kind normalization and storage: kind dispatch,
  backward compatibility for definitions with no `kind`, slug namespacing, the
  pattern block-tree sanitizer.
- `KindRegistrationTest` — that each kind lands in the right WordPress registry,
  and in no other.
- `ResponseSchemaTest` — that the response schemas and the normalizers agree in
  both directions (§3.1).
- `RestControllerTest` — the REST boundary: route registration, argument
  validation, and JSON body parsing.
