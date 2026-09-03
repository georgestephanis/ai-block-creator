# AI Block Creator

[![GPL-2.0-or-later License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](https://spdx.org/licenses/GPL-2.0-or-later.html)
[![WordPress 7.0+](https://img.shields.io/badge/WordPress-7.0%2B-blue.svg)](https://wordpress.org)

> **Speak it, type it, or screenshot it into existence.**  
> Create, iteratively refine, and insert custom Gutenberg blocks on the fly directly within the WordPress Block Editor without context switching.

---

## ✨ Features

- 💬 **Conversational Block Creation**: Describe any component (*"3-tier pricing table with a featured plan"*, *"Testimonial card with 5 star rating"*, *"FAQ accordion"*) and refine it through multi-turn chat.
- 🎙️ **Voice Dictation ("Speak it into existence")**: Click the microphone button and dictate your block requirements hands-free using the browser's Web Speech API.
- 📸 **Screenshot-to-Block ("Screenshot it into existence")**: Paste (`Cmd+V` / `Ctrl+V`), drag & drop, or upload UI screenshots or mockups. The AI interprets the design and structures a matching Gutenberg block.
- 🎨 **Live Interactive Preview**: Test block rendering, change preview attributes in real time, and inspect generated HTML and scoped CSS before inserting.
- 📚 **Block Library**: Every saved block shows up in the **AI Block Library** panel (editor's More menu), where you can insert it again, reopen it for conversational refinement, or delete it.
- ⚡ **Instant Dynamic Registration**: Blocks are immediately registered in the Gutenberg client runtime (`wp.blocks.registerBlockType`) and saved to the database (`ai_block_def` CPT) with dynamic server-side rendering and scoped CSS injection.
- 🔌 **Native WordPress 7.0+ AI Client Integration**: Connects seamlessly with WordPress core's `AiClient` (`WordPress\AiClient\AiClient`) and works with any configured provider (such as local LLMs via OpenAI-compatible servers, Ollama, vLLM, or cloud models).
- 🎛️ **Follows Your Admin Color Scheme**: The creator UI's accent color adapts to whichever admin color scheme (Blue, Coffee, Midnight, ...) you've chosen in your WordPress profile, instead of a fixed brand color.

---

## 🚀 Getting Started

### Requirements
- **WordPress**: 7.0 or higher (or WordPress with core AI Client enabled).
- **PHP**: 7.4 or higher (PHP 8.2+ recommended).
- **AI Connector**: An active connector registered with the WordPress AI Client (e.g., [AI Provider for OpenAI Compatible Servers](https://wordpress.org/plugins/ai-provider-for-openai-compatible-servers/)).

### Installation
1. Clone or download this repository into your WordPress `wp-content/plugins/` directory:
   ```bash
   git clone https://github.com/georgestephanis/ai-block-creator.git wp-content/plugins/ai-block-creator
   ```
2. Install dependencies and build assets:
   ```bash
   cd wp-content/plugins/ai-block-creator
   npm install
   npm run build
   ```
3. Activate the plugin in **WordPress Admin > Plugins** or via WP-CLI:
   ```bash
   wp plugin activate ai-block-creator
   ```

---

## 🛠️ Usage

1. Open the Block Editor to edit or create a post (**Posts > Add New**).
2. Click the **✨ Create Block with AI** button in the top toolbar, or type `/ai-block` in the editor.
3. Enter your prompt, speak into the microphone, or paste a screenshot.
4. Interact with the live preview and chat with the AI to refine styles or attributes.
5. Click **🚀 Insert into Post** to add the newly created block directly onto your page canvas, or **Save to Library** to keep it for later without inserting it now.
6. To reuse a saved block later, open the **AI Block Library** panel from the editor's More menu (⋮). From there you can insert it again, click **Refine** to reopen it in the creator for further changes, or delete it.

### Permissions

Anyone who can edit posts can generate and preview blocks. Saving a block to the library (and therefore inserting it, since inserting saves first) requires the `unfiltered_html` capability — Administrators and Editors have this by default on a standard WordPress install; Authors and Contributors do not. This is intentional: a saved block's HTML and CSS are served to every visitor of your site, the same trust boundary WordPress already applies to post content from those roles.

---

## 🧑‍💻 Development

```bash
# Install JS dependencies
npm install

# Start development build with file watcher
npm run start

# Production build
npm run build

# Lint JS/CSS (composer install first, then composer lint, for PHP)
npm run lint-js
npm run lint-css
```

`build/` is committed to this repository — installing via a Git checkout (e.g. the Playground blueprint's `git:directory` step) doesn't run a build step, so a PR touching `src/` should include a rebuilt `build/`.

---

## 🤖 AI Disclosure & Model Contributions

This project embraces collaborative AI-assisted development. Below is a log of models and their contributions to the codebase:

### Gemini 3.7 Flash (Antigravity)
* **Date**: September 2026
* **Contributions**:
  * Designed the initial plugin architecture and authored [`plans/architecture-and-design.md`](plans/architecture-and-design.md).
  * Built the backend PHP dynamic renderer (`AI_Block_Renderer`) with template interpolation and scoped CSS injection.
  * Implemented `AI_Block_REST_Controller` integrating with WordPress 7.0+ core `WordPress\AiClient\AiClient` and configuring `ModelConfig` (`enable_thinking: false`) for sub-4-second token generation.
  * Built the Gutenberg editor extension: top toolbar button, block inserter card (`/ai-block`), Web Speech voice dictation, clipboard screenshot paste listener, and interactive live preview canvas with tabs.
  * Created dynamic runtime factory (`dynamic-block-factory.js`) for instant client-side block registration.
  * Authored `README.md`, `AGENTS.md`, `readme.txt`, and WordPress Playground `blueprint.json`.

### Claude Fable 5.1 (Claude Code)
* **Date**: September 2026
* **Contributions**:
  * Performed a full security and correctness review, written up in [`plans/code-review-2026-09-03.md`](plans/code-review-2026-09-03.md) and tracked in [`plans/TODO.md`](plans/TODO.md).
  * Fixed an IDOR in `DELETE /blocks/{id}` that let any `edit_posts` user delete arbitrary posts on the site, and a stored-XSS path where a saved block's `render_html`/`css` reached every site visitor unsanitized; save/delete now require `unfiltered_html`, and both fields are validated through a new `AI_Block_Store` class before ever reaching post meta.
  * Fixed renderer bugs in both `AI_Block_Renderer::render_template()` (PHP) and `interpolateTemplate()` (JS): triple-brace raw output, nested `{{#if}}` conditionals, and the block wrapper (`align`/`anchor`/`className` supports) silently never being applied on the front end. Interpolated values are now escaped by the attribute context they appear in (URL, CSS, or text) instead of one blanket escaper.
  * Reworked the AI Client integration to use `wp_ai_client_prompt()` (so the request-timeout filter and `wp_supports_ai()` actually take effect), wire up conversation history via `withHistory()`, and request structured JSON via `asJsonResponse()`.
  * Fixed several editor-side bugs: voice dictation aborting itself mid-sentence and duplicating words, the live preview panel going stale after a refinement turn, and the `/ai-block` slash-command placeholder losing its insertion point on insert.
  * Set up PHPCS/WPCS (via Composer) and `@wordpress/scripts` JS/CSS linting, and brought the existing codebase to a clean pass under both.
  * Added the missing `LICENSE` file, `uninstall.php`, and fixed `blueprint.json`'s invalid `git:directory` resource reference and lack of AI-provider setup guidance.
  * Added the **AI Block Library** sidebar (list/insert/refine/delete saved blocks), which previously had no UI despite the delete endpoint already existing server-side.
  * Replaced the hardcoded accent color throughout the editor UI with WordPress's admin color-scheme custom properties, so it follows whichever scheme (Blue, Coffee, Midnight, ...) the user has chosen.
  * Brought `AGENTS.md`, `architecture-and-design.md`, and this file up to date with the architecture changes above.

---

## 📄 License
This project is licensed under the GPL-2.0-or-later License. See [LICENSE](LICENSE) for details.
