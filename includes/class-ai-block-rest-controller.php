<?php
/**
 * AI Block Creator REST Controller.
 *
 * @package AI_Block_Creator
 */

declare(strict_types=1);

namespace AI_Block_Creator;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

class AI_Block_REST_Controller extends WP_REST_Controller
{
    /**
     * Namespace for REST API.
     *
     * @var string
     */
    protected $namespace = 'ai-block-creator/v1';

    /**
     * Registers REST API routes.
     */
    public function register_routes(): void
    {
        // Generation endpoint.
        register_rest_route(
            $this->namespace,
            '/generate',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'generate_block'),
                    'permission_callback' => array($this, 'check_permissions'),
                    'args'                => array(
                        'prompt' => array(
                            'type'        => 'string',
                            'required'    => true,
                            'description' => 'User prompt describing the desired block or refinement.',
                        ),
                        'image'  => array(
                            'type'        => 'string',
                            'required'    => false,
                            'description' => 'Optional image data (URL, base64 data URI, or attachment ID) for screenshot-to-block.',
                        ),
                        'history' => array(
                            'type'        => 'array',
                            'required'    => false,
                            'description' => 'Optional conversation history for multi-turn refinement.',
                        ),
                        'current_block' => array(
                            'type'        => 'object',
                            'required'    => false,
                            'description' => 'Optional existing block definition being refined.',
                        ),
                    ),
                ),
            )
        );

        // Blocks collection endpoint.
        register_rest_route(
            $this->namespace,
            '/blocks',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'get_blocks'),
                    'permission_callback' => array($this, 'check_permissions'),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'save_block'),
                    'permission_callback' => array($this, 'check_permissions'),
                    'args'                => array(
                        'block_definition' => array(
                            'type'        => 'object',
                            'required'    => true,
                            'description' => 'The complete JSON block definition.',
                        ),
                    ),
                ),
            )
        );

        // Individual block deletion.
        register_rest_route(
            $this->namespace,
            '/blocks/(?P<id>\d+)',
            array(
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array($this, 'delete_block'),
                    'permission_callback' => array($this, 'check_permissions'),
                ),
            )
        );
    }

    /**
     * Checks if current user has permission.
     */
    public function check_permissions(): bool
    {
        return current_user_can('edit_posts');
    }

    /**
     * Handles block generation via WordPress AI Client.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function generate_block(WP_REST_Request $request)
    {
        $prompt        = sanitize_textarea_field($request->get_param('prompt') ?? '');
        $image         = $request->get_param('image');
        $history       = $request->get_param('history') ?? array();
        $current_block = $request->get_param('current_block');

        if (empty($prompt)) {
            return new WP_Error('empty_prompt', __('Prompt cannot be empty.', 'ai-block-creator'), array('status' => 400));
        }

        if (!function_exists('wp_ai_client_prompt')) {
            return new WP_Error('no_ai_client', __('WordPress AI Client is not available in this environment.', 'ai-block-creator'), array('status' => 500));
        }

        $system_instructions = $this->build_system_prompt();

        $user_content = "User Request:\n" . $prompt;
        if (!empty($current_block)) {
            $user_content .= "\n\nCurrent Block Definition to Refine/Update:\n" . wp_json_encode($current_block, JSON_PRETTY_PRINT);
        }

        // Build prompt with WP AI Client.
        try {
            $builder = wp_ai_client_prompt();

            // Provide instructions.
            $builder->using_system_instruction($system_instructions);

            // Add history if any.
            if (!empty($history) && is_array($history)) {
                foreach ($history as $msg) {
                    $role = ($msg['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
                    $text = is_string($msg['content'] ?? '') ? $msg['content'] : wp_json_encode($msg['content']);
                    if ($role === 'user') {
                        $builder->with_text("User: " . $text);
                    } else {
                        $builder->with_text("Assistant: " . $text);
                    }
                }
            }

            // Handle image/screenshot if provided.
            if (!empty($image) && is_string($image)) {
                if (str_starts_with($image, 'data:image/')) {
                    // Extract binary data from base64 data URI.
                    if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $image, $matches)) {
                        $mime_type = 'image/' . $matches[1];
                        $image_data = base64_decode($matches[2]);
                        if ($image_data !== false) {
                            $user_content .= "\n[Screenshot Attached: The block should replicate/interpret the UI design shown in the attached image]";
                            $builder->with_file($image_data, $mime_type);
                        }
                    }
                } elseif (filter_var($image, FILTER_VALIDATE_URL)) {
                    $user_content .= "\n[Screenshot Attached URL: " . esc_url_raw($image) . "]";
                }
            }

            $builder->with_text($user_content);

            $response = $builder->generate_text();

            if (is_wp_error($response)) {
                return $response;
            }

            $raw_text = (string) $response;
            $parsed_json = $this->extract_json_from_response($raw_text);

            if (!$parsed_json) {
                return new WP_Error(
                    'invalid_ai_response',
                    __('AI model did not return a valid block JSON structure. Raw output: ', 'ai-block-creator') . substr($raw_text, 0, 300),
                    array('status' => 502, 'raw_response' => $raw_text)
                );
            }

            // Sanitize & normalize the block definition.
            $normalized_block = $this->normalize_block_definition($parsed_json, $prompt);

            return new WP_REST_Response(array(
                'success' => true,
                'block'   => $normalized_block,
                'raw'     => $raw_text,
            ), 200);

        } catch (\Exception $e) {
            return new WP_Error('generation_failed', $e->getMessage(), array('status' => 500));
        }
    }

    /**
     * Extracts and decodes JSON from model output (handling code fences and reasoning).
     *
     * @param string $raw Text from model.
     * @return array|null
     */
    private function extract_json_from_response(string $raw): ?array
    {
        $raw = trim($raw);

        // Strip thinking tags if present (<think>...</think>).
        $raw = preg_replace('/<think>.*?<\/think>/s', '', $raw);

        // Check if wrapped in ```json ... ``` code fences.
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $raw, $matches)) {
            $json = json_decode($matches[1], true);
            if (is_array($json)) {
                return $json;
            }
        }

        // Try direct decode.
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }

        // Try searching for first { and last }.
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $extracted = substr($raw, $start, $end - $start + 1);
            $json = json_decode($extracted, true);
            if (is_array($json)) {
                return $json;
            }
        }

        return null;
    }

    /**
     * System prompt instructions for Gutenberg block generation.
     *
     * @return string
     */
    private function build_system_prompt(): string
    {
        return <<<'PROMPT'
You are an expert WordPress Gutenberg Block engineer and modern UI designer.
Your task is to create a complete, beautifully designed, highly functional WordPress Custom Block definition in JSON format based on the user's prompt or screenshot.

You MUST output ONLY a valid JSON object matching the specification below (no markdown introduction, no chatter outside the JSON).

Schema specification:
{
  "name": "ai-block/unique-slug", // lowercase alphanumeric with dashes, prefixed with ai-block/
  "title": "Human Readable Title",
  "description": "Brief description of the block",
  "icon": "star-filled", // Dashicon slug e.g. "grid-view", "money-alt", "format-chat", "megaphone", "id", "star-filled", "embed-generic", "testimonial", "layout"
  "category": "widgets", // "widgets", "design", "text", or "theme"
  "attributes": {
    // Schema of editable attributes
    // e.g. "title": { "type": "string", "default": "Default Value" },
    //      "highlight": { "type": "boolean", "default": false },
    //      "accentColor": { "type": "string", "default": "#2563eb" },
    //      "items": { "type": "string", "default": "Item 1\nItem 2\nItem 3" }
  },
  "edit_fields": [
    // Array of inspector/controls settings for the Gutenberg sidebar:
    // Supported field types: "text", "textarea", "color", "toggle", "url", "number", "select"
    // { "name": "title", "label": "Heading", "type": "text" },
    // { "name": "items", "label": "List Items (one per line)", "type": "textarea" },
    // { "name": "accentColor", "label": "Accent Color", "type": "color" },
    // { "name": "highlight", "label": "Enable Badge", "type": "toggle" }
  ],
  "render_html": "HTML template with mustache tags for variables. e.g. <div class=\"my-block {{#if highlight}}highlighted{{/if}}\" style=\"--accent: {{accentColor}};\"><h3>{{title}}</h3><ul>{{#list items}}<li>{{item}}</li>{{/list}}</ul></div>",
  "css": "Scoped CSS for styling the block. Always prefix classes with the unique block container class name to avoid style bleeding. Make designs look modern, polished, responsive, with subtle gradients, shadows, border-radius, clean typography, and hover effects."
}

Important Rules:
1. "render_html" supports:
   - `{{attributeName}}` for values.
   - `{{#if attributeName}}...{{/if}}` and `{{^if attributeName}}...{{/if}}` for boolean conditionals.
   - `{{#list attributeName}}<li>{{item}}</li>{{/list}}` for multi-line or array lists where each line becomes an `{{item}}`.
2. Provide sensible, beautiful defaults in "attributes".
3. Write clean, complete CSS in "css" that works across light and dark themes and looks state-of-the-art.
4. If refining an existing block, preserve unchanged attributes and update the requested changes.
PROMPT;
    }

    /**
     * Normalizes and validates the block definition structure.
     *
     * @param array  $def    Raw block definition.
     * @param string $prompt Original prompt for fallback naming.
     * @return array
     */
    private function normalize_block_definition(array $def, string $prompt): array
    {
        $title = !empty($def['title']) ? sanitize_text_field($def['title']) : 'Custom AI Block';
        $slug  = !empty($def['name']) ? sanitize_title(str_replace('ai-block/', '', $def['name'])) : sanitize_title($title);
        if (empty($slug)) {
            $slug = 'custom-block-' . substr(md5($prompt . time()), 0, 6);
        }

        $def['name']        = 'ai-block/' . $slug;
        $def['title']       = $title;
        $def['description'] = sanitize_text_field($def['description'] ?? 'Custom block created with AI');
        $def['icon']        = sanitize_text_field($def['icon'] ?? 'star-filled');
        $def['category']    = sanitize_text_field($def['category'] ?? 'widgets');

        if (!isset($def['attributes']) || !is_array($def['attributes'])) {
            $def['attributes'] = array();
        }

        if (!isset($def['edit_fields']) || !is_array($def['edit_fields'])) {
            $def['edit_fields'] = array();
            foreach ($def['attributes'] as $key => $attr) {
                $type = $attr['type'] ?? 'string';
                $field_type = 'text';
                if ($type === 'boolean') {
                    $field_type = 'toggle';
                } elseif ($type === 'number') {
                    $field_type = 'number';
                }
                $def['edit_fields'][] = array(
                    'name'  => $key,
                    'label' => ucwords(str_replace(array('_', '-'), ' ', $key)),
                    'type'  => $field_type,
                );
            }
        }

        $def['render_html'] = $def['render_html'] ?? '<div class="ai-block-default">' . esc_html($title) . '</div>';
        $def['css']         = $def['css'] ?? '';

        return $def;
    }

    /**
     * Gets all saved AI custom blocks.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function get_blocks(WP_REST_Request $request): WP_REST_Response
    {
        $posts = get_posts(array(
            'post_type'      => 'wp_block_def',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ));

        $blocks = array();
        foreach ($posts as $post) {
            $meta = get_post_meta($post->ID, '_ai_block_definition', true);
            if ($meta) {
                $data = json_decode($meta, true);
                if (is_array($data)) {
                    $data['id'] = $post->ID;
                    $blocks[] = $data;
                }
            }
        }

        return new WP_REST_Response($blocks, 200);
    }

    /**
     * Saves or updates an AI custom block definition.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function save_block(WP_REST_Request $request)
    {
        $def = $request->get_param('block_definition');
        if (empty($def) || !is_array($def)) {
            return new WP_Error('invalid_data', __('Invalid block definition.', 'ai-block-creator'), array('status' => 400));
        }

        $title = sanitize_text_field($def['title'] ?? 'AI Block');
        $slug  = sanitize_title(str_replace('ai-block/', '', $def['name'] ?? ''));

        if (empty($slug)) {
            $slug = sanitize_title($title);
        }

        $existing_posts = get_posts(array(
            'post_type'      => 'wp_block_def',
            'name'           => $slug,
            'posts_per_page' => 1,
            'post_status'    => 'any',
        ));

        $post_id = 0;
        if (!empty($existing_posts)) {
            $post_id = $existing_posts[0]->ID;
            wp_update_post(array(
                'ID'         => $post_id,
                'post_title' => $title,
            ));
        } else {
            $post_id = wp_insert_post(array(
                'post_title'  => $title,
                'post_name'   => $slug,
                'post_type'   => 'wp_block_def',
                'post_status' => 'publish',
            ));
        }

        if (is_wp_error($post_id) || empty($post_id)) {
            return new WP_Error('save_failed', __('Failed to save block definition.', 'ai-block-creator'), array('status' => 500));
        }

        $def['id']   = $post_id;
        $def['name'] = 'ai-block/' . $slug;

        update_post_meta($post_id, '_ai_block_definition', wp_json_encode($def));

        return new WP_REST_Response(array(
            'success' => true,
            'block'   => $def,
        ), 200);
    }

    /**
     * Deletes a block definition.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function delete_block(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        if (!$id) {
            return new WP_Error('invalid_id', __('Invalid block ID.', 'ai-block-creator'), array('status' => 400));
        }

        $deleted = wp_delete_post($id, true);
        if (!$deleted) {
            return new WP_Error('delete_failed', __('Failed to delete block.', 'ai-block-creator'), array('status' => 500));
        }

        return new WP_REST_Response(array('success' => true), 200);
    }
}
