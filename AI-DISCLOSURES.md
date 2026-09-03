# AI Disclosures & Model Contributions

This project embraces collaborative AI-assisted development across multiple models. Below is a chronological log of models and their contributions to the codebase:

---

## Gemini 3.7 Flash (Antigravity)
* **Date**: September 2026
* **Contributions**:
  * Designed the initial plugin architecture and authored [`plans/architecture-and-design.md`](plans/architecture-and-design.md).
  * Built the backend PHP dynamic renderer (`AI_Block_Renderer`) with template interpolation and scoped CSS injection.
  * Implemented `AI_Block_REST_Controller` integrating with WordPress 7.0+ core `WordPress\AiClient\AiClient` and configuring `ModelConfig` (`enable_thinking: false`) for sub-4-second token generation.
  * Built the Gutenberg editor extension: top toolbar button, block inserter card (`/ai-block`), Web Speech voice dictation, clipboard screenshot paste listener, and interactive live preview canvas with tabs.
  * Created dynamic runtime factory (`dynamic-block-factory.js`) for instant client-side block registration.
  * Authored `README.md`, `AGENTS.md`, `readme.txt`, and WordPress Playground `blueprint.json`.
  * **Follow-up Audit & Bugfix (September 2026)**: Identified and resolved a greedy regex matching bug in both PHP (`AI_Block_Renderer`) and JS (`dynamic-block-factory.js`) template interpolation that previously prevented multiple variables inside `style="..."` (e.g. `--accent: {{accent}}; background: {{bg}};`) and complex `href="..."` attributes from being substituted. Added full PHPUnit and Jest test coverage to lock in multi-variable attribute interpolation.

---

## Claude Fable 5.1 (Claude Code)
* **Date**: September 2026
* **Contributions**:
  * Performed a full security and correctness review, written up in [`plans/done/code-review-2026-09-03.md`](plans/done/code-review-2026-09-03.md) and tracked as a task list in [`plans/done/TODO-2026-09-03.md`](plans/done/TODO-2026-09-03.md). This was analysis and planning only — no code changes; the remediation below was a separate, later pass by Claude Sonnet 5.

---

## Claude Sonnet 5 (Claude Code)
* **Date**: September 2026
* **Contributions**: Worked through the review above end to end, verifying each fix live against a real WordPress install (not stubbed functions) rather than trusting the diff to be correct.
  * Fixed an IDOR in `DELETE /blocks/{id}` that let any `edit_posts` user delete arbitrary posts on the site, and a stored-XSS path where a saved block's `render_html`/`css` reached every site visitor unsanitized; save/delete now require `unfiltered_html`, and both fields are validated through a new `AI_Block_Store` class before ever reaching post meta.
  * Fixed renderer bugs in both `AI_Block_Renderer::render_template()` (PHP) and `interpolateTemplate()` (JS): triple-brace raw output, nested `{{#if}}` conditionals, and the block wrapper (`align`/`anchor`/`className` supports) silently never being applied on the front end. Interpolated values are now escaped by the attribute context they appear in (URL, CSS, or text) instead of one blanket escaper.
  * Reworked the AI Client integration to use `wp_ai_client_prompt()` (so the request-timeout filter and `wp_supports_ai()` actually take effect), wire up conversation history via `withHistory()`, and request structured JSON via `asJsonResponse()`.
  * Fixed several editor-side bugs: voice dictation aborting itself mid-sentence and duplicating words, the live preview panel going stale after a refinement turn, and the `/ai-block` slash-command placeholder losing its insertion point on insert.
  * Set up PHPCS/WPCS (via Composer) and `@wordpress/scripts` JS/CSS linting, and brought the existing codebase to a clean pass under both.
  * Added the missing `LICENSE` file, `uninstall.php`, and fixed `blueprint.json`'s invalid `git:directory` resource reference and lack of AI-provider setup guidance.
  * Added the **AI Block Library** sidebar (list/insert/refine/delete saved blocks), which previously had no UI despite the delete endpoint already existing server-side.
  * Replaced the hardcoded accent color throughout the editor UI with WordPress's admin color-scheme custom properties, so it follows whichever scheme (Blue, Coffee, Midnight, ...) the user has chosen.
  * Brought `AGENTS.md`, `architecture-and-design.md`, and this file up to date with the architecture changes above, including documenting which conventions come from WordPress's official [`wp-block-development` agent skill](https://github.com/WordPress/agent-skills/tree/trunk/skills/wp-block-development) versus which are original to this plugin.
  * Built the PHPUnit (`tests/php/`) and Jest (`src/**/test/*.test.js`) test suites, and in the process of running them for real, caught and fixed two bugs the earlier ad hoc verification had missed: attribute names were being silently lowercased (`accentColor` → `accentcolor`) by a sanitizer meant for URL slugs, and the `register_post_meta()` sanitize callback was double-unslashing, corrupting and dropping any saved block whose HTML contained an escaped quote.
  * Audited and fixed the AI's own system prompt as a piece of product surface, not just documentation: removed a `"category"` field the model was asked to produce but that was silently discarded, added an explicit rule that no JavaScript ever runs for these blocks (steering the model toward `<details>`/`<summary>` and CSS-only interactivity instead of non-functional `onclick`/`<script>` output), and forbade referencing external images/fonts that have no way to actually load.
  * Filed [`#1`](https://github.com/georgestephanis/ai-block-creator/issues/1), a detailed design proposal for letting the AI register block styles/variations on *existing* blocks (core or theme) instead of always generating a brand-new custom block — grounded in WordPress's actual `register_block_style()`/block-variations APIs, with the storage, UI, and security implications worked through and open questions flagged for a future implementation pass.
