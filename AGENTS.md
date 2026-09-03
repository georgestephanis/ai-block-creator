# AGENTS.md — Development Guidelines for AI Block Creator

This guide provides context, conventions, and architectural rules for AI agents and developers working on the `ai-block-creator` WordPress plugin repository.

---

## Repository Overview

`ai-block-creator` is a WordPress plugin that allows users to create, refine, and insert custom Gutenberg blocks in real time using WordPress core's `AiClient` (`WordPress\AiClient\AiClient`).

### Directory Structure
```
ai-block-creator/
├── ai-block-creator.php          # Main plugin bootstrap and hook loader
├── includes/
│   ├── class-ai-block-renderer.php       # Dynamic PHP render callback & template parser
│   └── class-ai-block-rest-controller.php # REST API controller (/generate, /blocks)
├── plans/
│   └── architecture-and-design.md        # Architecture specification document
├── src/
│   ├── index.js                          # Gutenberg editor entrypoint & plugin registration
│   ├── components/
│   │   ├── AIBlockCreatorModal.js       # Main creation modal & conversational thread
│   │   ├── BlockPreview.js              # Interactive live preview & attribute tester
│   │   ├── ImageDropzone.js             # Drag-and-drop & clipboard paste listener
│   │   └── VoiceInput.js                # Web Speech API voice dictation
│   ├── runtime/
│   │   └── dynamic-block-factory.js     # Client-side dynamic wp.blocks.registerBlockType
│   └── styles.scss                      # Scoped editor & modal UI styling
├── build/                                # Webpack compiled output
├── blueprint.json                        # WordPress Playground blueprint
├── readme.txt                            # WordPress.org standard plugin readme
└── README.md                             # Project overview
```

---

## Architectural Principles & Rules

### 1. WordPress Core AI Client Integration
- Always use `\WordPress\AiClient\AiClient` or `wp_ai_client_prompt()` for AI interactions.
- When generating structured text/JSON with thinking models (e.g. Qwen 3.6, DeepSeek), **always** configure `ModelConfig` with:
  ```php
  $model_config = new \WordPress\AiClient\Providers\Models\DTO\ModelConfig();
  $model_config->setCustomOption( 'chat_template_kwargs', [ 'enable_thinking' => false ] );
  ```
  This prevents thinking delay timeouts and ensures fast, deterministic token generation.

### 2. Block Registration & Persistence Lifecycle
- Custom blocks are persisted as custom post type `wp_block_def`.
- **Database Gotcha**: When saving JSON strings into post meta (`_ai_block_definition`), **always** use `wp_slash( wp_json_encode( $def ) )` so WordPress's internal `stripslashes_deep` in `update_post_meta` does not corrupt JSON quotes.
- On `init`, all published `wp_block_def` posts are registered server-side with `register_block_type()` using `AI_Block_Renderer::render`.
- In the Block Editor, `registerDynamicAiBlock( blockDef )` registers the block client-side immediately so that freshly created blocks can be inserted without requiring a page reload.

### 3. Frontend & Build Conventions
- Use `@wordpress/scripts` (`npm run build` / `npm run start`).
- Standard WordPress packages: `@wordpress/element`, `@wordpress/components`, `@wordpress/block-editor`, `@wordpress/blocks`, `@wordpress/data`, `@wordpress/plugins`, `@wordpress/api-fetch`.
- Scoped CSS: Always prefix block styling classes with `.ai-block-{slug}` or `.ai-custom-block` to avoid style leakage across the editor or theme.

---

## Verification Commands

- **Build Assets**:
  ```bash
  npm run build
  ```
- **Activate Plugin**:
  ```bash
  wp plugin activate ai-block-creator
  ```
- **Test Generation via WP-CLI**:
  ```bash
  wp eval '$req = new WP_REST_Request("POST", "/ai-block-creator/v1/generate"); $req->set_param("prompt", "Create an author badge block"); $ctrl = new \AI_Block_Creator\AI_Block_REST_Controller(); $res = $ctrl->generate_block($req); var_dump($res->get_data()["block"]["name"]);'
  ```
