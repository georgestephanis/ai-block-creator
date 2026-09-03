=== AI Block Creator ===
Contributors: georgestephanis
Tags: ai, gutenberg, blocks, custom-blocks, ai-client
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Speak it, type it, or screenshot it into existence. Create, refine, and insert custom Gutenberg blocks on the fly with AI directly inside the editor.

== Description ==

**AI Block Creator** brings conversational, multi-modal custom block creation directly into the WordPress Block Editor (Gutenberg).

When writing a post, you shouldn't have to leave the editor or scaffold complex code just to create a tailored block that doesn't exist yet. With AI Block Creator, you can:

* **Type it**: Describe your desired block (*"3-tier pricing comparison table"*, *"FAQ accordion"*, *"Customer testimonial card"*).
* **Speak it**: Use hands-free voice dictation powered by the browser Web Speech API.
* **Screenshot it**: Paste (`Cmd+V` / `Ctrl+V`), drag & drop, or upload a screenshot or mockup of any UI component.
* **Iterate Conversationally**: Refine colors, layouts, and attributes through follow-up prompts before committing.
* **Live Interactive Preview**: Test attribute controls, inspect generated markup and scoped CSS, and watch changes render in real time.
* **1-Click Insert & Save**: Automatically registers the custom block on the site and inserts it directly onto your editor canvas.
* **Block Library**: Every saved block appears in the AI Block Library panel (editor's More menu), where you can insert it again, reopen it for further refinement, or delete it.

AI Block Creator natively leverages the official **WordPress 7.0+ core AI Client** (`WordPress\AiClient\AiClient`), integrating with any active AI Connector configured on your site.

== Installation ==

1. Upload the `ai-block-creator` folder to your `/wp-content/plugins/` directory.
2. Ensure you have an active connector configured under **Settings > Connectors** (such as [AI Provider for OpenAI Compatible Servers](https://wordpress.org/plugins/ai-provider-for-openai-compatible-servers/)).
3. Activate the plugin through the **Plugins** menu in WordPress.
4. Open the Block Editor on any post or page and click the ✨ **Create Block with AI** button in the top toolbar or type `/ai-block`.

== Frequently Asked Questions ==

= Does this require an external subscription? =
No. The plugin uses whatever AI Connector you have configured with the WordPress AI Client. You can use local LLM servers (LM Studio, Ollama, vLLM) or any supported cloud provider.

= Are the generated blocks true Gutenberg blocks? =
Yes! Generated blocks are registered using standard WordPress block APIs (`register_block_type` and `wp.blocks.registerBlockType`), with full support for sidebar inspector controls, custom attributes, and responsive scoped styles.

= How do screenshots work? =
You can paste (`Cmd+V` / `Ctrl+V`), drag & drop, or select an image file (PNG, JPEG, WEBP, or GIF, up to 4MB). The image is passed to the AI prompt builder for vision-driven block creation.

= Can anyone save or delete blocks? =
Anyone who can edit posts can generate and preview a block. Saving it to the library (which inserting also requires, since it saves first) needs the `unfiltered_html` capability, which Administrators and Editors have by default and Authors/Contributors do not — the same trust boundary WordPress already applies to raw HTML in post content, since a saved block is served to every visitor of your site.

= Where do my saved blocks live, and what happens if I delete the plugin? =
Saved blocks are stored as a private custom post type. Deactivating the plugin does not remove them (so reactivating brings everything back); uninstalling it through **Plugins > Delete** does.

== Changelog ==

= 1.0.0 =
* Initial release: Conversational custom block creation, voice dictation, screenshot dropzone, live preview, dynamic block registration, and WordPress AI Client integration.
* Security hardening: saving/deleting a block now requires the `unfiltered_html` capability, and both the block's markup and its CSS are validated/sanitized before they're stored.
* Added the AI Block Library panel for managing previously saved blocks (insert, refine, delete).
* The editor UI now follows your chosen admin color scheme instead of a fixed accent color.
