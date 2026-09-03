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

# Run the test suites
npm run test-unit-js   # Jest
composer test           # PHPUnit — needs `composer install` first
```

`build/` is committed to this repository — installing via a Git checkout (e.g. the Playground blueprint's `git:directory` step) doesn't run a build step, so a PR touching `src/` should include a rebuilt `build/`.

The PHPUnit suite boots a real WordPress install (see `tests/php/bootstrap.php`) rather than the synthetic WP core test harness; if this plugin isn't checked out at the conventional `wp-content/plugins/<slug>/` depth, point it at one with `WP_ROOT=/path/to/wordpress composer test`.

---

## 🤖 AI Disclosures & Model Contributions

This project embraces collaborative AI-assisted development across multiple models. Detailed logs of all contributing models and their specific architectural and code contributions are maintained in [**`AI-DISCLOSURES.md`**](AI-DISCLOSURES.md).

---

## 📄 License
This project is licensed under the GPL-2.0-or-later License. See [LICENSE](LICENSE) for details.
