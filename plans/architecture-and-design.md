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
- **CPT `wp_block_def`**: Stores custom AI block definitions (slug, title, icon, attributes schema, edit template, render template, scoped CSS, and version).
- **Dynamic Registration (`register_block_type`)**:
  - Automatically loads all active `wp_block_def` posts on `init`.
  - Configures attributes and server-side render callback `Ai_Block_Renderer::render()`.
  - Injects scoped styles on frontend and editor.
- **REST Controller (`/wp-json/ai-block-creator/v1/`)**:
  - `POST /generate`: Integrates with WordPress 7.0+ AI Client (`wp_ai_client_prompt()`) to generate structured block definitions. Handles vision/multimodal requests when screenshots are uploaded.
  - `GET /blocks`: Returns all created custom block definitions.
  - `POST /blocks`: Saves/updates a custom block definition to the database.
  - `DELETE /blocks/<id>`: Deletes a custom block definition.
- **Security & Permissions**:
  - All REST endpoints verify `edit_posts` / `manage_options` permissions and WordPress REST nonces.
  - Template output sanitization and attribute validation.

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

## 3. Data Schema for AI Block Definition

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
  "render_html": "<div class=\"ai-block-pricing-card {{isFeatured ? 'featured' : ''}}\" style=\"--accent: {{accentColor}};\">...</div>",
  "css": ".ai-block-pricing-card { border-radius: 12px; padding: 24px; ... }"
}
```

---

## 4. Verification & Testing Strategy
- Verify PHP syntax and plugin activation via WP-CLI.
- Verify REST endpoint responses using `wp_ai_client_prompt()`.
- Test voice dictation fallback and screenshot handling.
- Verify live insertion into post content, serialization to post HTML, and frontend dynamic rendering.
