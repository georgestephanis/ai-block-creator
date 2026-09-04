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
						'kind'          => array(
							'type'        => 'string',
							'required'    => false,
							'enum'        => AI_Block_Store::ALLOWED_KINDS,
							'description' => 'Optional explicit output kind, skipping the planning stage.',
						),
						'target_block'  => array(
							'type'        => 'string',
							'required'    => false,
							'description' => 'Target block name for a block_style/block_variation, e.g. core/quote.',
						),
					),
				),
			)
		);

		// Planning endpoint (stage one).
		register_rest_route(
			$this->namespace,
			'/plan',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'plan_request' ),
					'permission_callback' => array( $this, 'check_generate_permissions' ),
					'args'                => array(
						'prompt' => array(
							'type'        => 'string',
							'required'    => true,
							'description' => 'User prompt to classify.',
						),
						'image'  => array(
							'type'        => 'string',
							'required'    => false,
							'description' => 'Optional base64 image data URI for screenshot-to-block.',
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
		$prompt        = $this->normalize_prompt( (string) $request->get_param( 'prompt' ) );
		$image         = $request->get_param( 'image' );
		$history       = $request->get_param( 'history' ) ?? array();
		$current_block = $request->get_param( 'current_block' );

		if ( '' === $prompt && empty( $image ) ) {
			return new WP_Error( 'empty_prompt', __( 'Prompt cannot be empty.', 'ai-block-creator' ), array( 'status' => 400 ) );
		}
		if ( '' === $prompt && ! empty( $image ) ) {
			$prompt = __( 'Recreate this screenshot as a WordPress block.', 'ai-block-creator' );
		}

		$precheck = $this->check_ai_availability( $image );
		if ( is_wp_error( $precheck ) ) {
			return $precheck;
		}

		$validated_image = $this->validate_image_data_uri( $image );
		if ( is_wp_error( $validated_image ) ) {
			return $validated_image;
		}

		$plan = $this->resolve_plan( $request, $prompt, $validated_image, is_array( $current_block ) ? $current_block : null );

		$user_content = "User Request:\n" . $prompt;
		if ( ! empty( $current_block ) ) {
			$user_content .= "\n\nCurrent Definition to Refine/Update:\n" . wp_json_encode( $current_block, JSON_PRETTY_PRINT );
		}
		if ( self::requires_target_block( $plan['kind'] ) ) {
			$user_content .= "\n\nTarget block (decided in planning): " . $plan['target_block'];
		}

		$parsed_json = $this->request_json_from_model(
			$this->build_system_prompt( $plan['kind'], $plan['target_block'] ),
			$user_content,
			$validated_image,
			is_array( $history ) ? $history : array(),
			$this->build_response_schema( $plan['kind'], $plan['target_block'] )
		);

		if ( is_wp_error( $parsed_json ) ) {
			return $parsed_json;
		}

		// The planner, not the generator, owns these two fields: a model asked
		// to write a style has no reason to be trusted to re-decide that it is
		// a style, and letting it contradict the plan would silently route the
		// result through the wrong normalizer and the wrong registration path.
		$parsed_json['kind']         = $plan['kind'];
		$parsed_json['target_block'] = $plan['target_block'];

		// Preserve the incoming name/slug when refining, so a follow-up
		// turn doesn't accidentally rename (and thereby fork) the definition.
		if ( ! empty( $current_block['name'] ) ) {
			// For a style or variation the name isn't just an identifier: a
			// style's name IS the `.is-style-{name}` class already written
			// into every post using it, so a rename here would orphan that
			// content behind a class nothing registers any more. Models do
			// rename on refinement even when told not to, so the stored name
			// wins outright rather than only filling in a blank one.
			$keep_name = AI_Block_Store::KIND_CUSTOM_BLOCK !== $plan['kind'] || empty( $parsed_json['name'] );

			if ( $keep_name ) {
				$parsed_json['name'] = $current_block['name'];
			}
		}

		$normalized_block = AI_Block_Store::normalize_and_validate( $parsed_json, $prompt );

		return new WP_REST_Response(
			array(
				'success' => true,
				'plan'    => $plan,
				'block'   => $normalized_block,
			),
			200
		);
	}

	/**
	 * Stage one: classifies a request into the kind of thing that should be
	 * built, without generating anything.
	 *
	 * Exposed as its own route so the editor can show the decision (and let the
	 * author override it) before spending a much larger generation call on it.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function plan_request( WP_REST_Request $request ) {
		$prompt = $this->normalize_prompt( (string) $request->get_param( 'prompt' ) );
		$image  = $request->get_param( 'image' );

		if ( '' === $prompt && empty( $image ) ) {
			return new WP_Error( 'empty_prompt', __( 'Prompt cannot be empty.', 'ai-block-creator' ), array( 'status' => 400 ) );
		}

		$precheck = $this->check_ai_availability( $image );
		if ( is_wp_error( $precheck ) ) {
			return $precheck;
		}

		$validated_image = $this->validate_image_data_uri( $image );
		if ( is_wp_error( $validated_image ) ) {
			return $validated_image;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'plan'    => $this->plan_output_kind( $prompt, $validated_image ),
			),
			200
		);
	}

	/**
	 * Decides which plan a generation request should run under.
	 *
	 * Precedence, highest first:
	 *  1. The definition being refined — a follow-up turn must not silently
	 *     change a saved style into a custom block.
	 *  2. An explicit `kind` param — the author overrode the planner in the UI.
	 *  3. Stage one: ask the model.
	 *
	 * @param WP_REST_Request                             $request       REST request.
	 * @param string                                      $prompt        Normalized prompt.
	 * @param array{data: string, mime_type: string}|null $image Validated image, if any.
	 * @param array<string, mixed>|null                   $current_block Definition being refined, if any.
	 * @return array{kind: string, target_block: string, rationale: string, source: string}
	 */
	private function resolve_plan( WP_REST_Request $request, string $prompt, ?array $image, ?array $current_block ): array {
		if ( ! empty( $current_block['kind'] ) ) {
			// The stored target is passed through as-is rather than re-checked
			// against candidate_target_blocks(): it was already validated
			// against the full block registry when it was saved, and that list
			// is a curation of what to *offer*, not of what is legal. Narrowing
			// it here would silently retarget a saved style on its next
			// refinement.
			$stored_target = strtolower( trim( (string) ( $current_block['target_block'] ?? '' ) ) );

			return array(
				'kind'         => AI_Block_Store::sanitize_kind( $current_block['kind'] ),
				'target_block' => preg_match( '#^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$#', $stored_target ) ? $stored_target : '',
				'rationale'    => __( 'Continuing to refine the existing definition.', 'ai-block-creator' ),
				'source'       => 'refinement',
			);
		}

		$requested_kind = $request->get_param( 'kind' );
		if ( is_string( $requested_kind ) && in_array( $requested_kind, AI_Block_Store::ALLOWED_KINDS, true ) ) {
			$target = $this->sanitize_target_block_param( (string) ( $request->get_param( 'target_block' ) ?? '' ) );

			// An override to a style or variation still needs something to
			// attach to. Without this, a caller that sends only `kind` gets the
			// core/group fallback from normalization — a style on Group, which
			// is nothing anyone asked for and looks like the feature is broken.
			if ( self::requires_target_block( $requested_kind ) && '' === $target ) {
				$planned = $this->plan_output_kind( $prompt, $image );

				if ( '' === $planned['target_block'] ) {
					// Nothing sensible to attach to, so the override can't be
					// honored. Building the planner's own choice and saying so
					// beats silently producing a style on Group.
					return array(
						'kind'         => $planned['kind'],
						'target_block' => $planned['target_block'],
						'rationale'    => __( 'No suitable block was found to attach that to, so this was built the way the AI suggested instead.', 'ai-block-creator' ),
						'source'       => 'planner',
					);
				}

				$target = $planned['target_block'];
			}

			return array(
				'kind'         => $requested_kind,
				'target_block' => $target,
				'rationale'    => __( 'Chosen explicitly for this request.', 'ai-block-creator' ),
				'source'       => 'explicit',
			);
		}

		return $this->plan_output_kind( $prompt, $image );
	}

	/**
	 * Runs the planning model call, degrading to a custom block on any failure.
	 *
	 * A planner that errors out must not take the whole request down with it:
	 * building a new custom block is what this plugin did for every request
	 * before planning existed, so it is the safe default. The returned
	 * `source` says which path produced the answer, so the UI can tell the
	 * author when the model was not actually consulted.
	 *
	 * @param string                                      $prompt Normalized prompt.
	 * @param array{data: string, mime_type: string}|null $image  Validated image, if any.
	 * @return array{kind: string, target_block: string, rationale: string, source: string}
	 */
	private function plan_output_kind( string $prompt, ?array $image ): array {
		$fallback = array(
			'kind'         => AI_Block_Store::KIND_CUSTOM_BLOCK,
			'target_block' => '',
			'rationale'    => __( 'Defaulted to a new custom block.', 'ai-block-creator' ),
			'source'       => 'fallback',
		);

		$parsed = $this->request_json_from_model(
			$this->build_planner_system_prompt(),
			"User Request:\n" . $prompt,
			$image,
			array(),
			$this->build_planner_response_schema()
		);

		if ( is_wp_error( $parsed ) || empty( $parsed['kind'] ) ) {
			return $fallback;
		}

		$kind = AI_Block_Store::sanitize_kind( $parsed['kind'] );

		// A style or variation is meaningless without something to attach it
		// to. If the planner named a block that isn't registered here, its
		// premise was wrong, so fall back rather than retargeting it to some
		// arbitrary other block the author never asked about. Custom blocks and
		// patterns have no single target and are unaffected.
		$target = $this->sanitize_target_block_param( (string) ( $parsed['target_block'] ?? '' ) );
		if ( self::requires_target_block( $kind ) && '' === $target ) {
			return $fallback;
		}

		return array(
			'kind'         => $kind,
			'target_block' => $target,
			'rationale'    => sanitize_text_field( (string) ( $parsed['rationale'] ?? '' ) ),
			'source'       => 'planner',
		);
	}

	/**
	 * Whether a kind attaches to one specific block.
	 *
	 * Styles and variations do; custom blocks stand alone, and a pattern is an
	 * arrangement of many blocks rather than a modification of one.
	 *
	 * @param string $kind One of AI_Block_Store's KIND_* values.
	 * @return bool
	 */
	private static function requires_target_block( string $kind ): bool {
		return in_array(
			$kind,
			array( AI_Block_Store::KIND_BLOCK_STYLE, AI_Block_Store::KIND_BLOCK_VARIATION ),
			true
		);
	}

	/**
	 * Validates a caller-supplied target block name against what this site
	 * actually has registered.
	 *
	 * @param string $target_block Raw block name.
	 * @return string Valid block name, or '' when it isn't one.
	 */
	private function sanitize_target_block_param( string $target_block ): string {
		$target_block = strtolower( trim( $target_block ) );

		return in_array( $target_block, $this->candidate_target_blocks(), true ) ? $target_block : '';
	}

	/**
	 * The blocks a style or variation may target.
	 *
	 * Deliberately a curated list rather than the whole registry: a site with
	 * a dozen plugins can have hundreds of registered blocks, which is both too
	 * many to put in a prompt and mostly noise (nobody wants an AI-authored
	 * style on a third-party form-field block). The list is intersected with
	 * the registry so a block the site doesn't have is never offered, and is
	 * filterable for sites that do want to opt more blocks in.
	 *
	 * @return string[]
	 */
	private function candidate_target_blocks(): array {
		$candidates = array(
			'core/paragraph',
			'core/heading',
			'core/list',
			'core/quote',
			'core/pullquote',
			'core/image',
			'core/gallery',
			'core/cover',
			'core/media-text',
			'core/group',
			'core/columns',
			'core/column',
			'core/buttons',
			'core/button',
			'core/separator',
			'core/table',
			'core/code',
			'core/details',
			'core/embed',
			'core/video',
			'core/post-title',
			'core/post-excerpt',
			'core/post-featured-image',
		);

		$registered = AI_Block_Store::registered_block_names();
		if ( ! empty( $registered ) ) {
			$candidates = array_values( array_intersect( $candidates, $registered ) );
		}

		/**
		 * Filters the blocks an AI-authored style or variation may target.
		 *
		 * @param string[] $candidates Block names, e.g. `core/quote`.
		 */
		return (array) apply_filters( 'ai_block_creator_target_block_candidates', $candidates );
	}

	/**
	 * Trims and length-caps an incoming prompt.
	 *
	 * @param string $prompt Raw prompt.
	 * @return string
	 */
	private function normalize_prompt( string $prompt ): string {
		$prompt = trim( (string) wp_check_invalid_utf8( $prompt ) );

		return mb_strlen( $prompt ) > 4000 ? mb_substr( $prompt, 0, 4000 ) : $prompt;
	}

	/**
	 * Checks that an AI client is present, and that it can accept an image if
	 * one was supplied.
	 *
	 * @param mixed $image Raw image param.
	 * @return true|WP_Error
	 */
	private function check_ai_availability( $image ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'no_ai_client', __( 'WordPress AI Client is not available in this environment.', 'ai-block-creator' ), array( 'status' => 500 ) );
		}

		if ( ! empty( $image ) && function_exists( 'AI_Block_Creator\\supports_image_input' ) && ! \AI_Block_Creator\supports_image_input() ) {
			return new WP_Error( 'image_input_not_supported', __( 'The configured AI provider does not support image inputs.', 'ai-block-creator' ), array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * Runs one JSON-returning model call.
	 *
	 * Shared by both stages so the timeout handling, ModelConfig options, and
	 * JSON extraction can't drift apart between them.
	 *
	 * @param string                                      $system_instructions System prompt.
	 * @param string                                      $user_content        User message.
	 * @param array{data: string, mime_type: string}|null $image              Validated image, if any.
	 * @param array<int, mixed>                           $history             Conversation history.
	 * @param array<string, mixed>|null                   $schema              JSON Schema the response must conform to.
	 * @return array<string, mixed>|WP_Error
	 */
	private function request_json_from_model( string $system_instructions, string $user_content, ?array $image, array $history, ?array $schema = null ) {
		$timeout          = (float) apply_filters( 'ai_block_creator_request_timeout', 300.0 );
		$timeout_callback = static fn() => $timeout;
		add_filter( 'wp_ai_client_default_request_timeout', $timeout_callback, 999 );
		add_filter( 'http_request_timeout', $timeout_callback, 999 );

		try {
			$raw = $this->execute_prompt( $system_instructions, $user_content, $image, $history, $schema );
			if ( is_wp_error( $raw ) ) {
				return $raw;
			}

			$parsed   = $this->extract_json_from_response( $raw );
			$problems = $this->schema_problems( $parsed, $schema );

			if ( empty( $problems ) ) {
				return $parsed;
			}

			// One repair turn, then give up. A model that returns the wrong
			// shape usually returns the right one when shown exactly what was
			// wrong with it -- and the alternative is worse than a retry: an
			// unparseable response is a hard 502, while a merely malformed one
			// gets quietly coerced by the normalizers into a definition that
			// looks fine and is missing half its content. The cap is one
			// because each turn costs a full round-trip, and a model that
			// can't fix it when told precisely what's wrong won't fix it on a
			// third try either.
			$repaired_raw = $this->execute_prompt(
				$this->build_repair_system_prompt(),
				$this->build_repair_user_content( $raw, $problems, $schema ),
				null,
				array(),
				$schema
			);

			if ( is_wp_error( $repaired_raw ) ) {
				return $repaired_raw;
			}

			$repaired          = $this->extract_json_from_response( $repaired_raw );
			$repaired_problems = $this->schema_problems( $repaired, $schema );

			if ( empty( $repaired_problems ) ) {
				/**
				 * Fires when a malformed model response was successfully repaired.
				 *
				 * @param string[] $problems The problems the first response had.
				 */
				do_action( 'ai_block_creator_repaired_response', $problems );

				return $repaired;
			}

			return new WP_Error(
				'invalid_ai_response',
				__( 'The AI model did not return a valid JSON structure, even after being asked to correct it.', 'ai-block-creator' ),
				array(
					'status'       => 502,
					'problems'     => $repaired_problems,
					'raw_response' => substr( (string) $repaired_raw, 0, 2000 ),
				)
			);
		} catch ( \Throwable $e ) {
			return new WP_Error( 'generation_failed', $e->getMessage(), array( 'status' => 500 ) );
		} finally {
			remove_filter( 'wp_ai_client_default_request_timeout', $timeout_callback, 999 );
			remove_filter( 'http_request_timeout', $timeout_callback, 999 );
		}
	}

	/**
	 * Runs a single model call and returns its raw text.
	 *
	 * Assumes the caller has already installed the request-timeout filters.
	 *
	 * @param string                                      $system_instructions System prompt.
	 * @param string                                      $user_content        User message.
	 * @param array{data: string, mime_type: string}|null $image               Validated image, if any.
	 * @param array<int, mixed>                           $history             Conversation history.
	 * @param array<string, mixed>|null                   $schema              JSON Schema for structured output.
	 * @return string|WP_Error
	 */
	private function execute_prompt( string $system_instructions, string $user_content, ?array $image, array $history, ?array $schema ) {
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

		/**
		 * Filters whether a JSON Schema is sent with the request.
		 *
		 * Passing a schema lets providers that support structured output
		 * constrain generation to the right shape, rather than us hoping the
		 * prompt was persuasive and repairing whatever comes back. Providers
		 * differ in which schemas they accept, though, so this is an escape
		 * hatch for one that rejects ours.
		 *
		 * @param bool $use_schema Whether to send the response schema.
		 */
		$use_schema = (bool) apply_filters( 'ai_block_creator_use_response_schema', true );

		$builder = ( $use_schema && null !== $schema )
			? $builder->as_json_response( $schema )
			: $builder->as_json_response();

		$history_messages = $this->build_history_messages( $history );
		if ( ! empty( $history_messages ) ) {
			$builder = $builder->with_history( ...$history_messages );
		}

		if ( is_array( $image ) ) {
			$builder = $builder->with_file( $image['data'], $image['mime_type'] );
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

		return is_object( $result ) && method_exists( $result, 'toText' )
			? (string) $result->toText()
			: (string) $result;
	}

	/**
	 * Lists what's wrong with a parsed response, if anything.
	 *
	 * Uses WordPress's own schema validator rather than a hand-rolled check, so
	 * the rules here are the same ones the REST API applies everywhere else.
	 * Note that it ignores unrecognized keys: our normalizers drop those
	 * anyway, and treating a harmless extra field as a failure would spend a
	 * whole repair round-trip on nothing.
	 *
	 * @param array<string, mixed>|null $parsed Parsed response, or null if it wouldn't parse.
	 * @param array<string, mixed>|null $schema Schema to validate against.
	 * @return string[] Human-readable problems; empty when the response is usable.
	 */
	private function schema_problems( ?array $parsed, ?array $schema ): array {
		if ( null === $parsed ) {
			return array( __( 'The response was not valid JSON.', 'ai-block-creator' ) );
		}

		if ( null === $schema ) {
			return array();
		}

		$validated = rest_validate_value_from_schema( $parsed, self::relax_schema( $schema ), 'response' );

		return is_wp_error( $validated ) ? $validated->get_error_messages() : array();
	}

	/**
	 * Strips `additionalProperties: false` from a schema, recursively.
	 *
	 * The constraint is worth sending to the provider — it is a clear
	 * instruction not to invent fields — but it is the wrong rule to validate
	 * against locally. Models routinely tack on a stray `confidence` or `notes`
	 * key, and the normalizers drop every unrecognized field anyway, so
	 * treating one as a failure would spend an entire repair round-trip
	 * removing something we discard for free. Repair only what we cannot fix
	 * ourselves: missing required fields, wrong types, bad enum values.
	 *
	 * @param array<string, mixed> $schema Schema as sent to the provider.
	 * @return array<string, mixed> Schema for local validation.
	 */
	private static function relax_schema( array $schema ): array {
		if ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] ) {
			unset( $schema['additionalProperties'] );
		}

		foreach ( array( 'properties', 'items' ) as $key ) {
			if ( ! isset( $schema[ $key ] ) || ! is_array( $schema[ $key ] ) ) {
				continue;
			}

			if ( 'items' === $key ) {
				$schema['items'] = self::relax_schema( $schema['items'] );
				continue;
			}

			foreach ( $schema['properties'] as $name => $property ) {
				if ( is_array( $property ) ) {
					$schema['properties'][ $name ] = self::relax_schema( $property );
				}
			}
		}

		return $schema;
	}

	/**
	 * System prompt for the repair turn.
	 *
	 * @return string
	 */
	private function build_repair_system_prompt(): string {
		return <<<'PROMPT'
You fix malformed JSON. You will be given a JSON Schema, a previous response that failed to satisfy it, and the
list of problems with that response.

Return ONLY the corrected JSON object. No markdown, no code fences, no explanation, no commentary.

Preserve every piece of the original content that was already correct -- you are reformatting and completing it,
not rewriting it. Where a required field is missing entirely, supply a sensible value consistent with the rest of
the content rather than a placeholder like "TODO" or an empty string.
PROMPT;
	}

	/**
	 * User message for the repair turn.
	 *
	 * @param string                    $raw      The response that failed.
	 * @param string[]                  $problems What was wrong with it.
	 * @param array<string, mixed>|null $schema   Schema it must satisfy.
	 * @return string
	 */
	private function build_repair_user_content( string $raw, array $problems, ?array $schema ): string {
		$content = "Problems with the previous response:\n";
		foreach ( $problems as $problem ) {
			$content .= '- ' . $problem . "\n";
		}

		if ( null !== $schema ) {
			$content .= "\nThe JSON Schema it must satisfy:\n" . wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		}

		// Bounded: a runaway response should not be echoed back in full and
		// blow the context window on the repair turn as well.
		$content .= "\n\nThe previous response:\n" . mb_substr( $raw, 0, 12000 );

		return $content;
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
	 * JSON Schema for the planning stage's response.
	 *
	 * Fully enumerable, which is the point: the planner's whole job is to pick
	 * one of four labels, and a provider that supports structured output can
	 * guarantee it picks one rather than inventing a fifth for us to coerce.
	 *
	 * @return array<string, mixed>
	 */
	private function build_planner_response_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'kind'         => array(
					'type' => 'string',
					'enum' => array_values( AI_Block_Store::ALLOWED_KINDS ),
				),
				'target_block' => array(
					'type'        => 'string',
					'description' => 'Required for block_style and block_variation; empty string otherwise.',
				),
				'rationale'    => array( 'type' => 'string' ),
			),
			'required'             => array( 'kind', 'target_block', 'rationale' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * JSON Schema for a stage-two response of the given kind.
	 *
	 * These mirror what AI_Block_Store's normalizers accept. They are a first
	 * line of defense, not the only one: the normalizers still allowlist every
	 * field, because a schema is a request to the provider and not every
	 * provider honors one.
	 *
	 * @param string $kind         One of AI_Block_Store's KIND_* values.
	 * @param string $target_block Target block, for kinds that have one.
	 * @return array<string, mixed>
	 */
	private function build_response_schema( string $kind, string $target_block = '' ): array {
		switch ( $kind ) {
			case AI_Block_Store::KIND_BLOCK_STYLE:
				return array(
					'type'                 => 'object',
					'properties'           => array(
						'name'        => array( 'type' => 'string' ),
						'label'       => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'css'         => array( 'type' => 'string' ),
					),
					'required'             => array( 'name', 'label', 'css' ),
					'additionalProperties' => false,
				);

			case AI_Block_Store::KIND_BLOCK_VARIATION:
				return array(
					'type'                 => 'object',
					'properties'           => array(
						'name'              => array( 'type' => 'string' ),
						'title'             => array( 'type' => 'string' ),
						'description'       => array( 'type' => 'string' ),
						'icon'              => array( 'type' => 'string' ),
						// Constrained to the target block's own attribute names
						// where we know them -- see attribute_properties_for().
						'attributes'        => $this->attribute_schema_for( $target_block ),
						'inner_block_names' => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'string',
								'enum' => $this->pattern_block_allowlist(),
							),
						),
						'css'               => array( 'type' => 'string' ),
					),
					'required'             => array( 'name', 'title', 'attributes' ),
					'additionalProperties' => false,
				);

			case AI_Block_Store::KIND_BLOCK_PATTERN:
				return array(
					'type'                 => 'object',
					'properties'           => array(
						'name'           => array( 'type' => 'string' ),
						'title'          => array( 'type' => 'string' ),
						'description'    => array( 'type' => 'string' ),
						'keywords'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'viewport_width' => array( 'type' => 'integer' ),
						'content'        => array(
							'type'        => 'string',
							'description' => 'Serialized Gutenberg block markup, with wp: delimiter comments.',
						),
					),
					'required'             => array( 'name', 'title', 'content' ),
					'additionalProperties' => false,
				);

			default:
				return array(
					'type'                 => 'object',
					'properties'           => array(
						'name'        => array( 'type' => 'string' ),
						'title'       => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'icon'        => array( 'type' => 'string' ),
						// A free-form map of attribute name => { type, default },
						// which JSON Schema can only express as an open object.
						'attributes'  => array( 'type' => 'object' ),
						'edit_fields' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'name'  => array( 'type' => 'string' ),
									'label' => array( 'type' => 'string' ),
									'type'  => array(
										'type' => 'string',
										// Taken from the store rather than
										// restated, so the shape we ask for and
										// the shape we accept cannot drift.
										'enum' => AI_Block_Store::ALLOWED_FIELD_TYPES,
									),
								),
								'required'   => array( 'name', 'label', 'type' ),
							),
						),
						'render_html' => array( 'type' => 'string' ),
						'css'         => array( 'type' => 'string' ),
					),
					'required'             => array( 'name', 'title', 'render_html', 'css' ),
					'additionalProperties' => false,
				);
		}
	}

	/**
	 * Builds the schema fragment for a variation's `attributes`.
	 *
	 * Where the target block is registered, its own attribute schema (which is
	 * exactly what its block.json declares) bounds what the model may emit:
	 * "don't guess at attribute names" stops being an instruction the model can
	 * ignore and becomes a constraint. Where it isn't registered, this degrades
	 * to an open object rather than forbidding everything.
	 *
	 * @param string $target_block Target block name.
	 * @return array<string, mixed>
	 */
	private function attribute_schema_for( string $target_block ): array {
		$properties = AI_Block_Store::registered_block_attributes( $target_block );
		if ( empty( $properties ) ) {
			return array( 'type' => 'object' );
		}

		$schema_properties = array();
		foreach ( $properties as $name => $definition ) {
			$property = array();

			$type = $definition['type'] ?? null;
			if ( is_string( $type ) && in_array( $type, array( 'string', 'boolean', 'number', 'integer', 'object', 'array' ), true ) ) {
				$property['type'] = $type;
			}

			if ( ! empty( $definition['enum'] ) && is_array( $definition['enum'] ) ) {
				$property['enum'] = array_values( $definition['enum'] );
			}

			$schema_properties[ $name ] = empty( $property ) ? new \stdClass() : $property;
		}

		return array(
			'type'                 => 'object',
			'properties'           => $schema_properties,
			'additionalProperties' => false,
		);
	}

	/**
	 * Renders the target block's attribute schema as prompt text.
	 *
	 * The schema on the request constrains providers that support structured
	 * output; this tells every other provider the same thing in the only
	 * channel it has. Both are derived from the same registered block.json
	 * metadata, so they cannot disagree.
	 *
	 * @param string $target_block Target block name.
	 * @return string Empty string when the block's attributes aren't known.
	 */
	private function describe_block_attributes( string $target_block ): string {
		$attributes = AI_Block_Store::registered_block_attributes( $target_block );
		if ( empty( $attributes ) ) {
			return '';
		}

		$lines = array();
		foreach ( $attributes as $name => $definition ) {
			$line = '- ' . $name;

			if ( ! empty( $definition['type'] ) && is_string( $definition['type'] ) ) {
				$line .= ' (' . $definition['type'] . ')';
			}
			if ( ! empty( $definition['enum'] ) && is_array( $definition['enum'] ) ) {
				$line .= ' one of: ' . implode( ', ', array_map( 'strval', $definition['enum'] ) );
			}

			$lines[] = $line;
		}

		return "\n\nThese are the ONLY attributes this block has. Use no others:\n" . implode( "\n", $lines ) . "\n";
	}

	/**
	 * Stage-one system prompt: classify the request, generate nothing.
	 *
	 * Kept deliberately small. This call runs on every un-planned request, so
	 * it should be cheap and fast; all it has to produce is a routing decision
	 * that stage two then builds against.
	 *
	 * @return string
	 */
	private function build_planner_system_prompt(): string {
		$targets = implode( ', ', $this->candidate_target_blocks() );

		$instructions = <<<'PROMPT'
You are a WordPress Gutenberg architect. You do NOT write any block code in this step.
Your only job is to decide WHICH OF THREE THINGS the user's request should become, and to say why.

Output ONLY a valid JSON object (no markdown, no prose outside it):
{
  "kind": "custom_block" | "block_style" | "block_variation" | "block_pattern",
  "target_block": "core/quote",   // REQUIRED for block_style and block_variation; use "" for the other two
  "rationale": "One short sentence, addressed to the site author, explaining the choice."
}

How to choose:

1. "block_style" — the request is ONLY about how existing content LOOKS. The user already has (or would use)
   an ordinary block, and wants a reusable visual treatment for it: a colour scheme, border, shadow, typography
   tweak, background. It adds NO new editable fields and NO new markup. It becomes CSS attached to an
   `.is-style-…` class the author toggles in that block's Styles panel.
   Examples: "make pull quotes gold with a big serif quote mark", "give my buttons a neon glow on hover",
   "a torn-paper edge treatment for image blocks".

2. "block_variation" — the request is a PRESET of a block that already does the job structurally. The user wants
   a named starting point with particular settings and/or a particular arrangement of inner blocks, but every
   piece of it is something the target block can already express.
   Examples: "a two-column feature layout with the image on the left", "a full-bleed dark cover with centred text",
   "a set of three outlined buttons".

3. "block_pattern" — the request is an ARRANGEMENT of SEVERAL blocks that already exist: a section built out of
   ordinary headings, paragraphs, images, columns and buttons. No new block type is needed, and no single block
   is being preset — it is a starting layout the author will then edit like any other content.
   Examples: "a hero section with a heading, subheading and two buttons", "an about section with a photo beside
   three paragraphs", "a call-to-action band with a heading and a button".

4. "custom_block" — the request needs its OWN structure and its OWN editable fields that no arrangement of
   existing blocks provides, and the author should be able to fill it in from the block sidebar. Reach for this
   when the content is repeating structured data whose shape matters, or when the markup simply isn't expressible
   with core blocks.
   Examples: "a 3-tier pricing table with feature checklists", "a testimonial card with a star rating",
   "a stats banner with four metrics".

Distinguishing the middle two, which are the easiest to confuse:
- ONE block, preset → "block_variation".
- SEVERAL blocks, arranged → "block_pattern".

Rules:
- "target_block" MUST be one of the allowed blocks listed below, exactly as written. If the best target for a
  style or variation is not in that list, choose "block_pattern" or "custom_block" instead.
- Prefer "block_style", "block_variation" or "block_pattern" when they genuinely fit: they reuse blocks the
  author already knows, and appear directly in the editor where they'd expect them.
- But when the request needs fields to fill in from the sidebar, or repeated structured items whose shape must
  stay consistent, choose "custom_block". If you are torn between a pattern and a custom block, ask whether the
  author would rather edit the result as ordinary blocks (pattern) or fill in a form (custom block).
- The rationale is shown to the author verbatim. Make it a plain, specific sentence, not a restatement of these rules.
PROMPT;

		return $instructions . "\n\nAllowed target_block values on this site: " . $targets . "\n";
	}

	/**
	 * Stage-two system prompt for the planned kind.
	 *
	 * @param string $kind         One of AI_Block_Store's KIND_* values.
	 * @param string $target_block Target block name for styles/variations.
	 * @return string
	 */
	private function build_system_prompt( string $kind = AI_Block_Store::KIND_CUSTOM_BLOCK, string $target_block = '' ): string {
		switch ( $kind ) {
			case AI_Block_Store::KIND_BLOCK_STYLE:
				return $this->build_block_style_prompt( $target_block );
			case AI_Block_Store::KIND_BLOCK_VARIATION:
				return $this->build_block_variation_prompt( $target_block );
			case AI_Block_Store::KIND_BLOCK_PATTERN:
				return $this->build_block_pattern_prompt();
			default:
				return $this->build_custom_block_prompt();
		}
	}

	/**
	 * Stage-two system prompt for a block style.
	 *
	 * @param string $target_block Target block name.
	 * @return string
	 */
	private function build_block_style_prompt( string $target_block ): string {
		$instructions = <<<'PROMPT'
You are an expert WordPress theme designer writing a single block style.

A block style is CSS ONLY. WordPress adds one class to the target block, and your CSS styles what is already
there. You are NOT creating a block: there is no markup, no attributes, no editable fields, no JavaScript.

You MUST output ONLY a valid JSON object (no markdown, no chatter outside the JSON):
{
  "name": "ai-gold-pullquote",     // lowercase, dashes only. It becomes the class `.is-style-{name}`.
  "label": "Gold Pull-Quote",      // Short human label shown in the block's Styles panel.
  "description": "One sentence describing the look.",
  "css": "…"
}

Rules for "css":
1. EVERY selector MUST be scoped to `.is-style-{name}` — the exact class formed from the "name" you chose.
   Write `.is-style-ai-gold-pullquote { … }` and `.is-style-ai-gold-pullquote cite { … }`, never a bare
   `blockquote { … }` or `cite { … }`, which would restyle every such element on the site.
2. Style only what the target block actually renders. Do not invent elements or classes it does not output.
3. No JavaScript, ever — no `<script>`, no event handlers. For interactivity use `:hover`, `:focus`,
   `:focus-within`, and CSS transitions only.
4. No external resources: no `@import`, no remote fonts, images, or icons (`https://…` or `//…`). Use CSS
   gradients, borders, shapes, and system font stacks instead.
5. Work in both light and dark themes, and keep text contrast accessible. Never convey meaning through colour alone.
6. Respect `prefers-reduced-motion` if you animate anything.
7. If refining an existing style, keep the same "name" and change only what was asked.
PROMPT;

		return $instructions . "\n\nThe style you are writing targets this block: " . $target_block . "\n";
	}

	/**
	 * Stage-two system prompt for a block variation.
	 *
	 * @param string $target_block Target block name.
	 * @return string
	 */
	private function build_block_variation_prompt( string $target_block ): string {
		$instructions = <<<'PROMPT'
You are an expert WordPress Gutenberg engineer writing a single block variation.

A block variation is a NAMED PRESET of an existing block: a set of starting attribute values, optionally with a
flat list of inner blocks to scaffold. You are NOT creating a block and NOT writing markup: the target block
renders itself exactly as it always does, starting from the values you choose.

You MUST output ONLY a valid JSON object (no markdown, no chatter outside the JSON):
{
  "name": "ai-two-col-feature",
  "title": "Two Column Feature",       // Shown in the block inserter.
  "description": "One sentence describing when to use it.",
  "icon": "columns",                   // Dashicon slug.
  "attributes": {                      // CONCRETE VALUES for the target block's own attributes — not a schema.
    "className": "is-style-default",   // Do NOT write { "type": …, "default": … } here.
    "align": "wide"
  },
  "inner_block_names": [ "core/column", "core/column" ],  // Optional, flat, max 10, core blocks only.
  "css": ""                            // Optional. Usually empty — see rule 4.
}

Rules:
1. "attributes" must only use attributes the target block genuinely supports, with values of the right type
   (strings, numbers, booleans). Do not guess at attribute names; if unsure, leave it out.
2. "inner_block_names" is a FLAT list of block names to insert inside the target, in order. There is no nesting
   and no per-inner-block attributes. Omit it entirely for blocks that take no inner blocks.
3. No JavaScript, ever.
4. Prefer expressing the design through the target block's own attributes. Only supply "css" when the variation
   genuinely needs styling that attributes cannot express, and if you do, scope every selector to a distinctive
   class you also set via the "className" attribute. Never write unscoped element selectors.
5. If refining an existing variation, keep the same "name" and change only what was asked.
PROMPT;

		return $instructions
			. "\n\nThe variation you are writing targets this block: " . $target_block . "\n"
			. $this->describe_block_attributes( $target_block );
	}

	/**
	 * Stage-two system prompt for a block pattern.
	 *
	 * The blocks a pattern may use are listed explicitly, because
	 * AI_Block_Store drops any block the site hasn't registered — a model left
	 * to guess at block names produces a pattern that silently loses pieces.
	 *
	 * @return string
	 */
	private function build_block_pattern_prompt(): string {
		$instructions = <<<'PROMPT'
You are an expert WordPress content designer writing a single block pattern.

A block pattern is an ARRANGEMENT OF EXISTING BLOCKS, written as serialized Gutenberg block markup. You are NOT
creating a block, a style, or a variation. When the author inserts your pattern, they get ordinary blocks in
their post, which they then edit like any other content.

You MUST output ONLY a valid JSON object (no markdown, no chatter outside the JSON):
{
  "name": "ai-hero-with-cta",
  "title": "Hero With Call To Action",
  "description": "One sentence describing the section and when to use it.",
  "keywords": [ "hero", "banner" ],
  "viewport_width": 1200,
  "content": "<!-- wp:group --><div class=\"wp-block-group\">…</div><!-- /wp:group -->"
}

Rules for "content":
1. It must be valid serialized block markup: each block wrapped in its `<!-- wp:name {\"attr\":\"value\"} -->` and
   `<!-- /wp:name -->` delimiter comments, with the block's normal saved HTML in between, exactly as WordPress
   writes it into post content. Self-closing blocks use the `<!-- wp:name /-->` form.
2. Use ONLY the blocks listed below. Any block not on that list is DROPPED from the pattern when it is saved,
   which silently leaves a hole in your layout — so do not guess at block names.
3. The saved HTML between the delimiters must match what the block actually saves, including its `wp-block-*`
   class, or the editor will flag the block as invalid. When unsure of a block's exact markup, prefer a simpler
   block you are certain of.
4. Write real, plausible placeholder copy — headings and sentences the author can edit down — not "Lorem ipsum"
   and not empty blocks.
5. Lay the pattern out with core's own layout tools (Group, Columns, Spacer) and its presets. Do NOT write a
   `<style>` block, and do not reach for custom CSS: a pattern has no stylesheet of its own, so anything it needs
   must come from block attributes and theme styles.
6. No JavaScript, ever, and no external resources: no remote images, fonts or icons (`https://…` or `//…`).
   There is no upload mechanism, so an external image reference is always either broken or an unreviewed
   third-party dependency. Use core's placeholder-friendly blocks (an empty Image block the author fills in),
   CSS-free shapes, or emoji instead.
7. Use semantic heading levels in order, and keep the pattern self-contained: it must make sense dropped into
   any post, not depend on surrounding content.
8. If refining an existing pattern, keep the same "name" and change only what was asked.
PROMPT;

		return $instructions . "\n\nBlocks you may use in this pattern: " . implode( ', ', $this->pattern_block_allowlist() ) . "\n";
	}

	/**
	 * The blocks a pattern's markup may be built from.
	 *
	 * Wider than candidate_target_blocks() — a pattern is an arrangement, so it
	 * legitimately needs layout and text primitives that nobody would hang a
	 * style on — but still curated and still intersected with the registry, so
	 * the model is never told about a block this site cannot render.
	 *
	 * @return string[]
	 */
	private function pattern_block_allowlist(): array {
		$candidates = array(
			'core/paragraph',
			'core/heading',
			'core/list',
			'core/list-item',
			'core/quote',
			'core/pullquote',
			'core/image',
			'core/cover',
			'core/media-text',
			'core/group',
			'core/columns',
			'core/column',
			'core/buttons',
			'core/button',
			'core/separator',
			'core/spacer',
			'core/table',
			'core/details',
			'core/code',
			'core/preformatted',
			'core/verse',
			'core/social-links',
			'core/social-link',
		);

		$registered = AI_Block_Store::registered_block_names();
		if ( ! empty( $registered ) ) {
			$candidates = array_values( array_intersect( $candidates, $registered ) );
		}

		/**
		 * Filters the blocks an AI-authored pattern may be built from.
		 *
		 * @param string[] $candidates Block names, e.g. `core/group`.
		 */
		return (array) apply_filters( 'ai_block_creator_pattern_block_allowlist', $candidates );
	}

	/**
	 * Stage-two system prompt for a brand-new custom block.
	 *
	 * @return string
	 */
	private function build_custom_block_prompt(): string {
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
