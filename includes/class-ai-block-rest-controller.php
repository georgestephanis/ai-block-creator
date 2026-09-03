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
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for generating, listing, saving, and deleting AI
 * block definitions.
 */
class AI_Block_REST_Controller extends WP_REST_Controller {

	/**
	 * Namespace for REST API.
	 *
	 * @var string
	 */
	protected $namespace = 'ai-block-creator/v1';

	/**
	 * Maximum allowed decoded size (bytes) for a screenshot upload. ~4MB.
	 *
	 * @var int
	 */
	private const MAX_IMAGE_BYTES = 4 * 1024 * 1024;

	/**
	 * Allowed screenshot MIME types.
	 *
	 * @var string[]
	 */
	private const ALLOWED_IMAGE_MIMES = array( 'image/png', 'image/jpeg', 'image/webp', 'image/gif' );

	/**
	 * Registers REST API routes.
	 */
	public function register_routes(): void {
		// Generation endpoint.
		register_rest_route(
			$this->namespace,
			'/generate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'generate_block' ),
					'permission_callback' => array( $this, 'check_generate_permissions' ),
					'args'                => array(
						'prompt'        => array(
							'type'        => 'string',
							'required'    => true,
							'description' => 'User prompt describing the desired block or refinement.',
						),
						'image'         => array(
							'type'        => 'string',
							'required'    => false,
							'description' => 'Optional base64 image data URI for screenshot-to-block.',
						),
						'history'       => array(
							'type'        => 'array',
							'required'    => false,
							'description' => 'Optional conversation history for multi-turn refinement.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'role'    => array(
										'type' => 'string',
										'enum' => array( 'user', 'assistant' ),
									),
									'content' => array( 'type' => 'string' ),
								),
							),
						),
						'current_block' => array(
							'type'                 => 'object',
							'required'             => false,
							'description'          => 'Optional existing block definition being refined.',
							'properties'           => array(),
							'additionalProperties' => true,
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
					'callback'            => array( $this, 'get_blocks' ),
					'permission_callback' => array( $this, 'check_read_permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_block' ),
					'permission_callback' => array( $this, 'check_write_permissions' ),
					// No `args` schema: the request body IS the block definition
					// (see save_block()) and its shape is validated exhaustively by
					// AI_Block_Store::normalize_and_validate(), not here — declaring
					// a required wrapper param here previously caused WordPress to
					// 400 the request during arg validation, before save_block() (or
					// even normalize_and_validate()) ever ran.
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
					'callback'            => array( $this, 'delete_block' ),
					'permission_callback' => array( $this, 'check_write_permissions' ),
				),
			)
		);
	}

	/**
	 * Permission check for generating a block draft (does not persist anything).
	 */
	public function check_generate_permissions(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permission check for reading saved block definitions.
	 */
	public function check_read_permissions(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permission check for saving/deleting block definitions. Saving persists
	 * arbitrary HTML/CSS that is served to every site visitor, and deleting
	 * removes a post outright, so both require a stronger capability than
	 * generation does.
	 */
	public function check_write_permissions(): bool {
		return current_user_can( 'unfiltered_html' );
	}

	/**
	 * Handles block generation via WordPress AI Client.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_block( WP_REST_Request $request ) {
		$prompt        = trim( (string) wp_check_invalid_utf8( (string) $request->get_param( 'prompt' ) ) );
		$image         = $request->get_param( 'image' );
		$history       = $request->get_param( 'history' ) ?? array();
		$current_block = $request->get_param( 'current_block' );

		if ( empty( $prompt ) && empty( $image ) ) {
			return new WP_Error( 'empty_prompt', __( 'Prompt cannot be empty.', 'ai-block-creator' ), array( 'status' => 400 ) );
		}
		if ( empty( $prompt ) && ! empty( $image ) ) {
			$prompt = __( 'Recreate this screenshot as a WordPress block.', 'ai-block-creator' );
		}

		if ( mb_strlen( $prompt ) > 4000 ) {
			$prompt = mb_substr( $prompt, 0, 4000 );
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'no_ai_client', __( 'WordPress AI Client is not available in this environment.', 'ai-block-creator' ), array( 'status' => 500 ) );
		}

		if ( ! empty( $image ) && function_exists( 'AI_Block_Creator\\supports_image_input' ) && ! \AI_Block_Creator\supports_image_input() ) {
			return new WP_Error( 'image_input_not_supported', __( 'The configured AI provider does not support image inputs.', 'ai-block-creator' ), array( 'status' => 400 ) );
		}

		$validated_image = $this->validate_image_data_uri( $image );
		if ( is_wp_error( $validated_image ) ) {
			return $validated_image;
		}

		$system_instructions = $this->build_system_prompt();

		$user_content = "User Request:\n" . $prompt;
		if ( ! empty( $current_block ) ) {
			$user_content .= "\n\nCurrent Block Definition to Refine/Update:\n" . wp_json_encode( $current_block, JSON_PRETTY_PRINT );
		}

		$timeout          = (float) apply_filters( 'ai_block_creator_request_timeout', 300.0 );
		$timeout_callback = static fn() => $timeout;
		add_filter( 'wp_ai_client_default_request_timeout', $timeout_callback, 999 );
		add_filter( 'http_request_timeout', $timeout_callback, 999 );

		try {
			/**
			 * Filters the ModelConfig custom options passed to the AI client.
			 *
			 * @param array $custom_options Provider-specific custom options.
			 */
			$custom_options = apply_filters(
				'ai_block_creator_model_config_options',
				array(
					'chat_template_kwargs' => array( 'enable_thinking' => false ),
				)
			);

			$model_config = new \WordPress\AiClient\Providers\Models\DTO\ModelConfig();
			$model_config->setSystemInstruction( $system_instructions );
			foreach ( $custom_options as $option_key => $option_value ) {
				$model_config->setCustomOption( (string) $option_key, $option_value );
			}

			$builder = wp_ai_client_prompt();
			$builder = $builder->using_model_config( $model_config );
			$builder = $builder->as_json_response();

			$history_messages = $this->build_history_messages( is_array( $history ) ? $history : array() );
			if ( ! empty( $history_messages ) ) {
				$builder = $builder->with_history( ...$history_messages );
			}

			if ( is_array( $validated_image ) ) {
				$builder = $builder->with_file( $validated_image['data'], $validated_image['mime_type'] );
			}

			$builder = $builder->with_text( $user_content );

			$result = $builder->generate_text();
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( $result instanceof \WP_AI_Client_Prompt_Builder ) {
				return new WP_Error(
					'ai_generation_failed',
					__( 'AI prompt failed to execute.', 'ai-block-creator' ),
					array( 'status' => 500 )
				);
			}

			$raw_text = is_object( $result ) && method_exists( $result, 'toText' )
				? $result->toText()
				: (string) $result;

			$parsed_json = $this->extract_json_from_response( $raw_text );

			if ( ! $parsed_json ) {
				return new WP_Error(
					'invalid_ai_response',
					__( 'AI model did not return a valid block JSON structure.', 'ai-block-creator' ),
					array(
						'status'       => 502,
						'raw_response' => substr( (string) $raw_text, 0, 2000 ),
					)
				);
			}

			// Preserve the incoming name/slug when refining, so a follow-up
			// turn doesn't accidentally rename (and thereby fork) the block.
			if ( ! empty( $current_block['name'] ) && empty( $parsed_json['name'] ) ) {
				$parsed_json['name'] = $current_block['name'];
			}

			$normalized_block = AI_Block_Store::normalize_and_validate( $parsed_json, $prompt );

			return new WP_REST_Response(
				array(
					'success' => true,
					'block'   => $normalized_block,
				),
				200
			);
		} catch ( \Throwable $e ) {
			return new WP_Error( 'generation_failed', $e->getMessage(), array( 'status' => 500 ) );
		} finally {
			remove_filter( 'wp_ai_client_default_request_timeout', $timeout_callback, 999 );
			remove_filter( 'http_request_timeout', $timeout_callback, 999 );
		}
	}

	/**
	 * Validates and decodes a `data:image/...;base64,...` URI.
	 *
	 * @param mixed $image Raw image param.
	 * @return array{data: string, mime_type: string}|null|WP_Error Decoded image, null if no image given, or an error.
	 */
	private function validate_image_data_uri( $image ) {
		if ( empty( $image ) || ! is_string( $image ) ) {
			return null;
		}

		if ( ! preg_match( '/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/', $image, $matches ) ) {
			return new WP_Error( 'invalid_image', __( 'Image must be a base64 data URI.', 'ai-block-creator' ), array( 'status' => 400 ) );
		}

		$mime_type = strtolower( $matches[1] );
		if ( ! in_array( $mime_type, self::ALLOWED_IMAGE_MIMES, true ) ) {
			return new WP_Error( 'unsupported_image_type', __( 'Unsupported image type. Use PNG, JPEG, WEBP, or GIF.', 'ai-block-creator' ), array( 'status' => 400 ) );
		}

		$decoded = base64_decode( $matches[2], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a client-uploaded screenshot, not obfuscating code.
		if ( false === $decoded ) {
			return new WP_Error( 'invalid_image', __( 'Image data could not be decoded.', 'ai-block-creator' ), array( 'status' => 400 ) );
		}

		if ( strlen( $decoded ) > self::MAX_IMAGE_BYTES ) {
			return new WP_Error( 'image_too_large', __( 'Screenshot is too large. Please use an image under 4MB.', 'ai-block-creator' ), array( 'status' => 400 ) );
		}

		return array(
			'data'      => $decoded,
			'mime_type' => $mime_type,
		);
	}

	/**
	 * Converts the client-supplied conversation history into Message DTOs.
	 *
	 * @param array<int, mixed> $history Raw history entries ({role, content}).
	 * @return array<int, UserMessage|ModelMessage>
	 */
	private function build_history_messages( array $history ): array {
		$messages = array();

		// Cap history length to keep prompt size bounded.
		$history = array_slice( $history, -10 );

		foreach ( $history as $turn ) {
			if ( ! is_array( $turn ) || empty( $turn['content'] ) || ! is_string( $turn['content'] ) ) {
				continue;
			}

			$text = mb_substr( $turn['content'], 0, 4000 );
			$role = $turn['role'] ?? 'user';

			if ( 'assistant' === $role ) {
				$messages[] = new ModelMessage( array( new MessagePart( $text ) ) );
			} else {
				$messages[] = new UserMessage( array( new MessagePart( $text ) ) );
			}
		}

		return $messages;
	}

	/**
	 * Extracts and decodes JSON from model output (handling code fences and reasoning).
	 *
	 * @param string $raw Text from model.
	 * @return array|null
	 */
	private function extract_json_from_response( string $raw ): ?array {
		$raw = trim( $raw );

		// Strip thinking tags if present (<think>...</think>).
		$raw = (string) preg_replace( '/<think>.*?<\/think>/s', '', $raw );

		// Try direct decode first — the common case with asJsonResponse().
		$json = json_decode( $raw, true );
		if ( is_array( $json ) ) {
			return $json;
		}

		// Check if wrapped in ```json ... ``` code fences.
		if ( preg_match( '/```(?:json)?\s*(\{.*\})\s*```/s', $raw, $matches ) ) {
			$json = json_decode( $matches[1], true );
			if ( is_array( $json ) ) {
				return $json;
			}
		}

		// Try searching for first { and last }.
		$start = strpos( $raw, '{' );
		$end   = strrpos( $raw, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$extracted = substr( $raw, $start, $end - $start + 1 );
			$json      = json_decode( $extracted, true );
			if ( is_array( $json ) ) {
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
	private function build_system_prompt(): string {
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
  "attributes": {
    // Schema of editable attributes. "type" must be one of: string, boolean, number, integer, array, object.
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
   - `{{attributeName}}` for values, escaped for the context it appears in (text, href/src, or a style attribute).
   - `{{{attributeName}}}` (triple braces) ONLY when the attribute is meant to contain rich HTML; it is filtered through wp_kses_post on the server.
   - `{{#if attributeName}}...{{/if}}` and `{{^if attributeName}}...{{/if}}` for boolean conditionals. These MAY be nested.
   - `{{#list attributeName}}<li>{{item}}</li>{{/list}}` for multi-line or array lists where each line becomes an `{{item}}`. Only `{{item}}` is available inside a list block, not other attribute names.
2. NO JAVASCRIPT EVER RUNS. There is no <script> tag, no viewScript, no Interactivity API for these blocks — any <script> tag is discarded, and any inline event-handler attribute (onclick, onchange, etc.) is silently stripped before a non-administrator's block is ever saved. Never rely on either. For anything that needs to look "interactive" (an accordion, a toggle, a tabbed panel, a tooltip on hover), build it with native HTML + CSS only:
   - Expand/collapse content (FAQ accordions, "read more") → use <details> and <summary>. It is accessible, keyboard-operable, and needs zero script.
   - Hover/focus effects → CSS :hover / :focus / :focus-within.
   - Never promise or describe behavior in "description" that the markup can't actually deliver without JavaScript.
3. Do not reference any external resource: no @import, no remote image/font/icon URLs (https://... or //...) in "css" OR in "render_html" (no <img src="https://...">). There is no upload mechanism for real images. For visual interest use CSS gradients/shapes, emoji, or a small handwritten inline <svg> instead.
4. Use semantic HTML (<h1>-<h6>, <button> for actions that aren't links, <ul>/<ol> for lists, <blockquote> for quotes) rather than generic <div>/<span> for everything, and never convey information (like "required" or "featured") through color alone — pair it with text or an icon too.
5. Provide sensible, beautiful defaults in "attributes".
6. Write clean, complete CSS in "css" that works across light and dark themes and looks state-of-the-art.
7. If refining an existing block, preserve unchanged attributes and update the requested changes, and keep the same "name" unless the user asks for a fundamentally different block.
PROMPT;
	}

	/**
	 * Gets all saved AI custom blocks.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_blocks( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( AI_Block_Store::all(), 200 );
	}

	/**
	 * Saves or updates an AI custom block definition.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_block( WP_REST_Request $request ) {
		// The request body IS the block definition (current client convention).
		// A `block_definition` wrapper key is also accepted for backward/forward
		// compatibility with any caller still using the older shape -- checked
		// first and explicitly, since an object that merely CONTAINS a
		// `block_definition` key is not itself empty and would otherwise be
		// mistaken for the definition (with every real field silently missing).
		$body = $request->get_json_params();
		if ( is_array( $body ) && isset( $body['block_definition'] ) && is_array( $body['block_definition'] ) ) {
			$def = $body['block_definition'];
		} elseif ( is_array( $request->get_param( 'block_definition' ) ) ) {
			$def = $request->get_param( 'block_definition' );
		} else {
			$def = $body;
		}

		if ( empty( $def ) || ! is_array( $def ) ) {
			return new WP_Error( 'invalid_data', __( 'Invalid block definition.', 'ai-block-creator' ), array( 'status' => 400 ) );
		}

		$saved = AI_Block_Store::save( $def );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'block'   => $saved,
			),
			200
		);
	}

	/**
	 * Deletes a block definition.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_block( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		$result = AI_Block_Store::delete( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete block.', 'ai-block-creator' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}
}
