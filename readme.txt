=== AI Block Creator ===
Contributors: georgestephanis
Tags: ai, gutenberg, blocks, custom-blocks, ai-client
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: ai
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

Not every request is best answered with a brand-new block, so the AI plans before it builds. It first decides *what kind of thing* your request should become, tells you which it picked and why, and lets you overrule it:

* **A custom block** — when the request needs its own structure and its own editable fields. *"A 3-tier pricing table with feature checklists."*
* **A block style** — when it's purely about how existing content looks. This becomes a new option in an ordinary block's Styles panel, right where you'd expect to find it. *"Make pull quotes gold with a big serif quotation mark."*
* **A block variation** — when a core block already does the job and just needs the right settings. This becomes a ready-made preset in the inserter. *"A two-column layout with the image on the left."*
* **A block pattern** — when it's an arrangement of several ordinary blocks. You insert it and then edit it like any other content. *"A hero section with a heading, subheading and two buttons."*

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
Yes! Generated blocks are registered using standard WordPress block APIs (`register_block_type` and `wp.blocks.registerBlockType`), with full support for sidebar inspector controls, custom attributes, and responsive scoped styles. Generated styles, variations and patterns use the matching core APIs (`register_block_style()`, the `get_block_type_variations` filter, and `register_block_pattern()`), so they appear in the Styles panel and the inserter exactly like a theme's own would.

= What if the AI picks the wrong kind of thing to build? =
Every result tells you what it decided to build and why, with one-click buttons to rebuild the same request as any of the other kinds instead. If the planning step fails or is unavailable, the request falls back to building a custom block.

= A pattern I just created isn't in the inserter yet. =
Patterns reach the inserter through settings WordPress renders when the editor loads, so a brand-new one appears there after you reload the editor. Inserting it from the creation modal or the AI Block Library works straight away in the meantime.

= What happens if the AI returns something malformed? =
Requests are sent with a JSON schema describing the expected shape, which providers that support structured output will enforce. If a response still comes back malformed, the plugin shows the model exactly what was wrong and asks it once to correct it before giving up — and everything that is stored is independently validated and sanitized regardless of what the model returned.

= How do screenshots work? =
You can paste (`Cmd+V` / `Ctrl+V`), drag & drop, or select an image file (PNG, JPEG, WEBP, or GIF, up to 4MB). The image is passed to the AI prompt builder for vision-driven block creation.

= Can anyone save or delete blocks? =
Anyone who can edit posts can generate and preview a block. Saving it to the library (which inserting also requires, since it saves first) needs the `unfiltered_html` capability, which Administrators and Editors have by default and Authors/Contributors do not — the same trust boundary WordPress already applies to raw HTML in post content, since a saved block is served to every visitor of your site.

= Where do my saved blocks live, and what happens if I delete the plugin? =
Saved blocks are stored as a private custom post type. Deactivating the plugin does not remove them (so reactivating brings everything back); uninstalling it through **Plugins > Delete** does.

== Changelog ==

= 1.0.0 =
Initial release.

* Conversational block creation: describe what you want, refine it across multiple turns, and insert it — with voice dictation, and a screenshot dropzone when your provider accepts images.
* Two-stage generation: every request is first classified as a custom block, a block style, a block variation, or a block pattern, then built accordingly. The reasoning is shown in the editor, and one click rebuilds the request as any of the other kinds.
* Schema-validated responses: requests carry a JSON schema of the shape they expect, with a single automatic repair turn if a model returns something malformed.
* AI Block Library panel for inserting, refining, and deleting anything you've saved.
* Generated CSS is confined to its own class, so an AI-authored style cannot restyle the rest of your site. Block variations are checked against the target block's own registered attributes, so they can't be saved with settings that would silently do nothing.
* Saving or deleting a definition requires the `unfiltered_html` capability, and all stored markup and CSS is validated and sanitized regardless of what the model returned.
* The editor UI follows your chosen admin color scheme.
