# AI Block Creator

[![GPL-2.0-or-later License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](https://spdx.org/licenses/GPL-2.0-or-later.html)
[![WordPress 7.0+](https://img.shields.io/badge/WordPress-7.0%2B-blue.svg)](https://wordpress.org)
[![Try in WordPress Playground](https://img.shields.io/badge/WordPress%20Playground-Try%20Live%20Demo-blue?logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/georgestephanis/ai-block-creator/trunk/blueprint.json)

> **Speak it, type it, or screenshot it into existence.**  
> Create, iteratively refine, and insert custom Gutenberg blocks on the fly directly within the WordPress Block Editor without context switching.

---

## ✨ Features

- 🧭 **Plans Before It Builds**: A first, fast AI pass decides whether your request should become a brand-new **custom block**, a **block style** on an existing block, a **block variation**, or a **block pattern** — then tells you which it chose and why, with one click to rebuild it as any of the others. A *"make pull quotes gold"* request becomes an option in the Quote block's own Styles panel rather than a bespoke block nobody will find again, and *"a hero section with two buttons"* becomes a pattern of ordinary blocks you can edit normally.
- 🧷 **Schema-Validated Generation**: Every request carries a JSON schema of the shape it expects, so providers that support structured output are constrained rather than merely asked. A response that still comes back malformed gets one automatic repair turn — and everything stored is independently validated regardless. Variations are checked against the target block's own `block.json` attributes, so they can't be saved with settings that would quietly do nothing.
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

### 🎮 Live Demo in WordPress Playground

Test **AI Block Creator** immediately in your browser — with the core `ai` plugin and OpenAI-compatible provider pre-installed:

[![Open in WordPress Playground](https://img.shields.io/badge/%E2%96%B6%EF%B8%8F_Open_in_WordPress_Playground-21759b?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/georgestephanis/ai-block-creator/trunk/blueprint.json)

> **Note**: The blueprint lands directly on the **Settings > Connectors** screen so you can configure your AI provider (e.g. OpenAI API key, Ollama, LM Studio, or local vLLM endpoint) to test generation against.

### Requirements
- **WordPress**: 7.0 or higher.
- **PHP**: 7.4 or higher (PHP 8.2+ recommended).
- **Required Plugin**: [WordPress AI Plugin](https://wordpress.org/plugins/ai/) (`Requires Plugins: ai`).
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

## 🪝 Hooks

Every hook is prefixed `ai_block_creator_`. All are optional — the plugin works without touching any of them.

**Capability detection** — override what the plugin thinks the site can do, e.g. to force the UI on while developing against a stubbed provider.

| Hook | Type | Default | Purpose |
| --- | --- | --- | --- |
| `ai_block_creator_has_connected_llm` | filter (`bool`) | detected | Whether an AI provider is connected. Gates the whole editor UI. |
| `ai_block_creator_supports_image_input` | filter (`bool`) | detected | Whether the provider accepts images. Gates the screenshot dropzone. |

**Model requests**

| Hook | Type | Default | Purpose |
| --- | --- | --- | --- |
| `ai_block_creator_request_timeout` | filter (`float`) | `300.0` | Per-request timeout, in seconds. Custom-block generation is the slow case; lower it if you'd rather fail fast. |
| `ai_block_creator_model_config_options` | filter (`array`) | `chat_template_kwargs` | Provider-specific `ModelConfig` custom options. The default disables "thinking" on vLLM/Qwen-style servers; other providers may reject unknown keys, so replace rather than extend if yours does. |
| `ai_block_creator_use_response_schema` | filter (`bool`) | `true` | Whether to send a JSON Schema with each request. Turn off for a provider that rejects ours — stored definitions are still validated either way. |
| `ai_block_creator_repaired_response` | **action** | — | Fires when a malformed response was successfully repaired on the retry. Receives the problems the first response had. Useful for logging how often a provider needs the second turn. |

**What the AI may target** — the default lists are curated rather than "every registered block", because a site with a dozen plugins has hundreds of them and most are noise in a prompt. Both results are intersected with the block registry *after* filtering, so you can add your own blocks but can't put a block this site doesn't have in front of the model.

| Hook | Type | Default | Purpose |
| --- | --- | --- | --- |
| `ai_block_creator_target_block_candidates` | filter (`string[]`) | 23 core blocks | Blocks a generated *style* or *variation* may attach to. Add your own blocks here to let the AI style them. |
| `ai_block_creator_pattern_block_allowlist` | filter (`string[]`) | 23 core blocks | Blocks a generated *pattern* may be built from. Anything a pattern references that isn't on this list is dropped when the pattern is saved. |

Example — let the AI style your theme's own blocks:

```php
add_filter(
	'ai_block_creator_target_block_candidates',
	function ( array $blocks ): array {
		$blocks[] = 'mytheme/callout';
		return $blocks;
	}
);
```

---

## 🤖 AI Disclosures & Model Contributions

This project embraces collaborative AI-assisted development across multiple models. Detailed logs of all contributing models and their specific architectural and code contributions are maintained in [**`AI-DISCLOSURES.md`**](AI-DISCLOSURES.md).

---

## 📄 License
This project is licensed under the GPL-2.0-or-later License. See [LICENSE](LICENSE) for details.
