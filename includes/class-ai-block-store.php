<?php
/**
 * AI Block Store.
 *
 * Single source of truth for reading, validating, and persisting
 * `ai_block_def` post definitions. Every read/write path (REST controller,
 * renderer, block registration) goes through this class so validation and
 * caching only exist in one place.
 *
 * @package AI_Block_Creator
 */

declare(strict_types=1);

namespace AI_Block_Creator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for reading, validating, and persisting AI Block
 * Definition posts. Every read/write path goes through this class.
 */
class AI_Block_Store {

	/**
	 * Post type slug for stored block definitions.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'ai_block_def';

	/**
	 * Meta key holding the JSON-encoded block definition.
	 *
	 * @var string
	 */
	public const META_KEY = '_ai_block_definition';

	/**
	 * Definition kind: a brand-new dynamic `ai-block/{slug}` block rendered by
	 * AI_Block_Renderer. The historical (and still default) kind.
	 *
	 * @var string
	 */
	public const KIND_CUSTOM_BLOCK = 'custom_block';

	/**
	 * Definition kind: a block style registered against an existing block via
	 * register_block_style(). Contributes CSS only — the target block renders
	 * itself, with one extra `is-style-{name}` class.
	 *
	 * @var string
	 */
	public const KIND_BLOCK_STYLE = 'block_style';

	/**
	 * Definition kind: a block variation (a named attribute/inner-block preset)
	 * registered against an existing block.
	 *
	 * @var string
	 */
	public const KIND_BLOCK_VARIATION = 'block_variation';

	/**
	 * Every recognized definition kind.
	 *
	 * @var string[]
	 */
	public const ALLOWED_KINDS = array(
		self::KIND_CUSTOM_BLOCK,
		self::KIND_BLOCK_STYLE,
		self::KIND_BLOCK_VARIATION,
	);

	/**
	 * Cache group for in-request/object-cache memoization.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'ai_block_creator';

	/**
	 * Allowed attribute types (mirrors Gutenberg's attribute schema).
	 *
	 * @var string[]
	 */
	private const ALLOWED_ATTRIBUTE_TYPES = array( 'string', 'boolean', 'number', 'integer', 'array', 'object' );

	/**
	 * Allowed edit-field control types.
	 *
	 * @var string[]
	 */
	private const ALLOWED_FIELD_TYPES = array( 'text', 'textarea', 'color', 'toggle', 'url', 'number', 'select' );

	/**
	 * Returns all published block definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$cached = wp_cache_get( 'all_definitions', self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			)
		);

		$definitions = array();
		foreach ( $posts as $post ) {
			$def = self::decode( $post );
			if ( $def ) {
				$definitions[] = $def;
			}
		}

		wp_cache_set( 'all_definitions', $definitions, self::CACHE_GROUP );

		return $definitions;
	}

	/**
	 * Gets a single block definition by slug (without the `ai-block/` prefix) or full block name.
	 *
	 * @param string $block_name_or_slug Block slug or full `ai-block/{slug}` name.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $block_name_or_slug ): ?array {
		$slug = str_replace( 'ai-block/', '', $block_name_or_slug );

		$cache_key = 'definition_' . $slug;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'name'           => $slug,
				'posts_per_page' => 1,
				'post_status'    => 'publish',
			)
		);

		if ( empty( $posts ) ) {
			return null;
		}

		$def = self::decode( $posts[0] );
		if ( $def ) {
			wp_cache_set( $cache_key, $def, self::CACHE_GROUP );
		}

		return $def;
	}

	/**
	 * Decodes and normalizes the stored definition for a post.
	 *
	 * @param \WP_Post $post Block definition post.
	 * @return array<string, mixed>|null
	 */
	private static function decode( \WP_Post $post ): ?array {
		$raw_json = get_post_meta( $post->ID, self::META_KEY, true );
		if ( ! $raw_json || ! is_string( $raw_json ) ) {
			return null;
		}

		$def = json_decode( $raw_json, true );
		if ( ! is_array( $def ) ) {
			return null;
		}

		$def['id'] = $post->ID;

		return $def;
	}

	/**
	 * Returns every stored definition of a given kind.
	 *
	 * @param string $kind One of the KIND_* constants.
	 * @return array<int, array<string, mixed>>
	 */
	public static function by_kind( string $kind ): array {
		$kind = self::sanitize_kind( $kind );

		return array_values(
			array_filter(
				self::all(),
				static function ( array $def ) use ( $kind ): bool {
					return self::sanitize_kind( $def['kind'] ?? '' ) === $kind;
				}
			)
		);
	}

	/**
	 * Builds the `post_name` a definition is stored under.
	 *
	 * Kinds share one post type, and post_name is the uniqueness key used to
	 * decide create-vs-update, so the kinds are namespaced against each other:
	 * without this, a style named "callout" would overwrite the custom block
	 * named "callout" (and vice versa) on save. Custom blocks keep their bare
	 * slug so definitions saved before kinds existed still resolve.
	 *
	 * @param array<string, mixed> $normalized A definition that has already been normalized.
	 * @return string
	 */
	private static function post_slug_for( array $normalized ): string {
		$name = (string) ( $normalized['name'] ?? '' );

		switch ( self::sanitize_kind( $normalized['kind'] ?? '' ) ) {
			case self::KIND_BLOCK_STYLE:
				return 'style-' . $name;
			case self::KIND_BLOCK_VARIATION:
				return 'variation-' . $name;
			default:
				return str_replace( 'ai-block/', '', $name );
		}
	}

	/**
	 * Saves (creates or updates) a block definition after strict validation.
	 *
	 * @param array<string, mixed> $def   Raw, untrusted definition (e.g. from the REST request body).
	 * @param string               $title_fallback Fallback title used when the definition has none.
	 * @return array<string, mixed>|\WP_Error The normalized, saved definition, or an error.
	 */
	public static function save( array $def, string $title_fallback = 'AI Block' ) {
		$normalized = self::normalize_and_validate( $def, $title_fallback );
		$slug       = self::post_slug_for( $normalized );

		$existing_posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'name'           => $slug,
				'posts_per_page' => 1,
				'post_status'    => 'any',
			)
		);

		$post_id = 0;
		if ( ! empty( $existing_posts ) ) {
			$post_id = $existing_posts[0]->ID;
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_title'  => $normalized['title'],
					'post_status' => 'publish',
				)
			);
		} else {
			$post_id = wp_insert_post(
				array(
					'post_title'  => $normalized['title'],
					'post_name'   => $slug,
					'post_type'   => self::POST_TYPE,
					'post_status' => 'publish',
				),
				true
			);
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		if ( empty( $post_id ) ) {
			return new \WP_Error( 'save_failed', __( 'Failed to save block definition.', 'ai-block-creator' ), array( 'status' => 500 ) );
		}

		$normalized['id'] = $post_id;

		update_post_meta( $post_id, self::META_KEY, wp_slash( wp_json_encode( $normalized ) ) );

		self::flush_cache();

		return $normalized;
	}

	/**
	 * Deletes a block definition by post ID, guarding against deleting unrelated content.
	 *
	 * @param int $post_id Post ID.
	 * @return bool|\WP_Error
	 */
	public static function delete( int $post_id ) {
		if ( ! $post_id ) {
			return new \WP_Error( 'invalid_id', __( 'Invalid block ID.', 'ai-block-creator' ), array( 'status' => 400 ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'not_found', __( 'Block definition not found.', 'ai-block-creator' ), array( 'status' => 404 ) );
		}

		$deleted = wp_delete_post( $post_id, true );
		self::flush_cache();

		return (bool) $deleted;
	}

	/**
	 * Strictly normalizes and validates an untrusted definition of any kind.
	 *
	 * A definition's `kind` decides which shape it must conform to, and each
	 * kind has its own allowlist: a `custom_block` keeps the historical
	 * render_html/attributes/edit_fields shape, while a `block_style` or
	 * `block_variation` targets an already-registered block and carries no
	 * render template at all. An unrecognized or absent `kind` falls back to
	 * `custom_block`, so definitions stored before kinds existed keep
	 * validating (and registering) exactly as they always did.
	 *
	 * @param array<string, mixed> $def            Raw definition.
	 * @param string               $title_fallback Fallback title.
	 * @return array<string, mixed>
	 */
	public static function normalize_and_validate( array $def, string $title_fallback = 'AI Block' ): array {
		switch ( self::sanitize_kind( $def['kind'] ?? '' ) ) {
			case self::KIND_BLOCK_STYLE:
				return self::normalize_block_style( $def, $title_fallback );
			case self::KIND_BLOCK_VARIATION:
				return self::normalize_block_variation( $def, $title_fallback );
			default:
				return self::normalize_custom_block( $def, $title_fallback );
		}
	}

	/**
	 * Normalizes a block style definition.
	 *
	 * A style contributes nothing but a name, a human label, and CSS scoped to
	 * the `.is-style-{name}` class WordPress adds to the target block. There is
	 * deliberately no render_html/attributes handling here: the target block
	 * still renders itself, so AI_Block_Renderer is never involved and there is
	 * no template for a caller to smuggle markup through.
	 *
	 * @param array<string, mixed> $def            Raw definition.
	 * @param string               $title_fallback Fallback label.
	 * @return array<string, mixed>
	 */
	private static function normalize_block_style( array $def, string $title_fallback ): array {
		$label = ! empty( $def['label'] ) && is_string( $def['label'] )
			? sanitize_text_field( $def['label'] )
			: ( ! empty( $def['title'] ) && is_string( $def['title'] ) ? sanitize_text_field( $def['title'] ) : $title_fallback );

		$slug = self::style_or_variation_slug( $def, $label, 'style' );

		$normalized = array(
			'kind'         => self::KIND_BLOCK_STYLE,
			'name'         => $slug,
			'label'        => $label,
			'title'        => $label,
			'description'  => sanitize_text_field( (string) ( $def['description'] ?? '' ) ),
			'target_block' => self::sanitize_target_block( (string) ( $def['target_block'] ?? '' ) ),
			'css'          => self::sanitize_css( is_string( $def['css'] ?? null ) ? $def['css'] : '' ),
		);

		if ( ! empty( $def['id'] ) ) {
			$normalized['id'] = (int) $def['id'];
		}

		return $normalized;
	}

	/**
	 * Normalizes a block variation definition.
	 *
	 * Note the difference from a custom block's `attributes`: those are a
	 * Gutenberg attribute *schema* (`{ type, default }` per key), whereas a
	 * variation's are concrete attribute *values* to preset on the target
	 * block. They therefore go through sanitize_attribute_values(), not
	 * sanitize_attributes(); running the schema sanitizer over them would
	 * discard every one of them (none is an array with a `type`).
	 *
	 * @param array<string, mixed> $def            Raw definition.
	 * @param string               $title_fallback Fallback title.
	 * @return array<string, mixed>
	 */
	private static function normalize_block_variation( array $def, string $title_fallback ): array {
		$title = ! empty( $def['title'] ) && is_string( $def['title'] )
			? sanitize_text_field( $def['title'] )
			: $title_fallback;

		$slug = self::style_or_variation_slug( $def, $title, 'variation' );

		$normalized = array(
			'kind'              => self::KIND_BLOCK_VARIATION,
			'name'              => $slug,
			'title'             => $title,
			'description'       => sanitize_text_field( (string) ( $def['description'] ?? '' ) ),
			'icon'              => self::sanitize_icon( (string) ( $def['icon'] ?? 'star-filled' ) ),
			'target_block'      => self::sanitize_target_block( (string) ( $def['target_block'] ?? '' ) ),
			'attributes'        => self::sanitize_attribute_values( is_array( $def['attributes'] ?? null ) ? $def['attributes'] : array() ),
			'inner_block_names' => self::sanitize_inner_block_names( is_array( $def['inner_block_names'] ?? null ) ? $def['inner_block_names'] : array() ),
			'css'               => self::sanitize_css( is_string( $def['css'] ?? null ) ? $def['css'] : '' ),
		);

		if ( ! empty( $def['id'] ) ) {
			$normalized['id'] = (int) $def['id'];
		}

		return $normalized;
	}

	/**
	 * Builds the slug (and registered name) for a style or variation.
	 *
	 * Both are namespaced with an `ai-` prefix so an AI-authored style is
	 * visibly distinct from a theme's or core's own in the Styles panel, and
	 * so the generated CSS class (`.is-style-ai-…`) can't collide with one
	 * the target block already ships.
	 *
	 * @param array<string, mixed> $def      Raw definition.
	 * @param string               $fallback Label/title to derive a slug from when none is given.
	 * @param string               $context  'style' or 'variation'; used only for the random fallback.
	 * @return string
	 */
	private static function style_or_variation_slug( array $def, string $fallback, string $context ): string {
		$raw  = ! empty( $def['name'] ) && is_string( $def['name'] ) ? $def['name'] : $fallback;
		$slug = sanitize_title( $raw );

		if ( '' === $slug ) {
			$slug = $context . '-' . substr( md5( $fallback . microtime() ), 0, 8 );
		}

		return str_starts_with( $slug, 'ai-' ) ? $slug : 'ai-' . $slug;
	}

	/**
	 * Allowlists a definition kind, defaulting to `custom_block`.
	 *
	 * @param mixed $kind Raw kind.
	 * @return string
	 */
	public static function sanitize_kind( $kind ): string {
		$kind = is_string( $kind ) ? strtolower( trim( $kind ) ) : '';

		return in_array( $kind, self::ALLOWED_KINDS, true ) ? $kind : self::KIND_CUSTOM_BLOCK;
	}

	/**
	 * Validates a `namespace/name` target block reference.
	 *
	 * The format is always enforced. Membership in the block registry is only
	 * enforced when the registry is actually populated: normalize_and_validate()
	 * also runs from the `_ai_block_definition` meta sanitize callback, which
	 * can fire before `init` has registered any block type, and rejecting every
	 * target there would silently blank out stored definitions.
	 *
	 * @param string $target_block Raw target block name.
	 * @return string Valid block name, or the `core/group` fallback.
	 */
	private static function sanitize_target_block( string $target_block ): string {
		$fallback     = 'core/group';
		$target_block = strtolower( trim( $target_block ) );

		if ( ! preg_match( '#^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$#', $target_block ) ) {
			return $fallback;
		}

		$registered = self::registered_block_names();
		if ( ! empty( $registered ) && ! in_array( $target_block, $registered, true ) ) {
			return $fallback;
		}

		return $target_block;
	}

	/**
	 * Returns every currently registered block name, or an empty array when the
	 * registry isn't available/populated yet.
	 *
	 * @return string[]
	 */
	public static function registered_block_names(): array {
		if ( ! class_exists( '\\WP_Block_Type_Registry' ) ) {
			return array();
		}

		return array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() );
	}

	/**
	 * Sanitizes a flat map of concrete attribute *values* (as opposed to an
	 * attribute schema — see sanitize_attributes()).
	 *
	 * Values may be scalars, or one level of array/object containing scalars
	 * (enough for e.g. `style.color.background`-shaped presets); anything
	 * deeper is dropped rather than recursed into, so a definition can't carry
	 * an unbounded nested payload into post meta.
	 *
	 * @param array<string, mixed> $values Raw attribute values.
	 * @param int                  $depth  Current recursion depth; callers should omit it.
	 * @return array<string, mixed>
	 */
	private static function sanitize_attribute_values( array $values, int $depth = 0 ): array {
		$clean = array();

		foreach ( $values as $key => $value ) {
			$key = self::sanitize_identifier( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = sanitize_text_field( $value );
			} elseif ( is_array( $value ) && $depth < 2 ) {
				$clean[ $key ] = self::sanitize_attribute_values( $value, $depth + 1 );
			}
		}

		return $clean;
	}

	/**
	 * Validates the flat list of inner block names a variation should be
	 * scaffolded with. Deliberately a flat list, not a recursive innerBlocks
	 * template: one level covers the cases this is for (a two-column layout, a
	 * heading-plus-paragraph pairing) without inviting an AI to emit an
	 * arbitrarily deep tree that has to be validated node by node.
	 *
	 * @param array<int, mixed> $names Raw inner block names.
	 * @return string[]
	 */
	private static function sanitize_inner_block_names( array $names ): array {
		$clean = array();

		foreach ( array_slice( $names, 0, 10 ) as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}

			$requested = strtolower( trim( $name ) );
			$validated = self::sanitize_target_block( $requested );

			// sanitize_target_block() falls back to core/group for anything it
			// can't validate. For a *target* that's a sensible default, but an
			// unrecognized inner block should be dropped, not silently turned
			// into a Group nested inside the variation's markup.
			if ( $validated !== $requested ) {
				continue;
			}

			$clean[] = $validated;
		}

		return $clean;
	}

	/**
	 * Strictly normalizes and validates an untrusted `custom_block` definition.
	 *
	 * Every field is allowlisted; unknown top-level keys are dropped so
	 * arbitrary data can never ride along into post meta. `render_html` is
	 * passed through `wp_kses_post()` and `css` through a conservative
	 * sanitizer so a client without `unfiltered_html` cannot store markup or
	 * styles that would execute on the front end.
	 *
	 * @param array<string, mixed> $def            Raw definition.
	 * @param string               $title_fallback Fallback title.
	 * @return array<string, mixed>
	 */
	private static function normalize_custom_block( array $def, string $title_fallback ): array {
		$title = ! empty( $def['title'] ) && is_string( $def['title'] ) ? sanitize_text_field( $def['title'] ) : $title_fallback;

		$slug = ! empty( $def['name'] ) && is_string( $def['name'] )
			? sanitize_title( str_replace( 'ai-block/', '', $def['name'] ) )
			: sanitize_title( $title );
		if ( empty( $slug ) ) {
			$slug = 'custom-block-' . substr( md5( $title . microtime() ), 0, 8 );
		}

		$normalized = array(
			'kind'        => self::KIND_CUSTOM_BLOCK,
			'name'        => 'ai-block/' . $slug,
			'title'       => $title,
			'description' => sanitize_text_field( (string) ( $def['description'] ?? 'Custom block created with AI' ) ),
			'icon'        => self::sanitize_icon( (string) ( $def['icon'] ?? 'star-filled' ) ),
			'category'    => self::sanitize_category( (string) ( $def['category'] ?? 'widgets' ) ),
			'attributes'  => self::sanitize_attributes( is_array( $def['attributes'] ?? null ) ? $def['attributes'] : array() ),
		);

		$normalized['edit_fields'] = self::sanitize_edit_fields(
			is_array( $def['edit_fields'] ?? null ) ? $def['edit_fields'] : array(),
			$normalized['attributes']
		);

		$raw_html                  = is_string( $def['render_html'] ?? null ) ? $def['render_html'] : '';
		$normalized['render_html'] = '' !== $raw_html
			? self::sanitize_render_html( $raw_html )
			: '<div class="ai-block-default">' . esc_html( $title ) . '</div>';

		$normalized['css'] = self::sanitize_css( is_string( $def['css'] ?? null ) ? $def['css'] : '' );

		if ( ! empty( $def['id'] ) ) {
			$normalized['id'] = (int) $def['id'];
		}

		return $normalized;
	}

	/**
	 * Allowlists a Dashicon slug (letters, digits, dashes only).
	 *
	 * @param string $icon Raw icon slug.
	 * @return string
	 */
	private static function sanitize_icon( string $icon ): string {
		$icon = sanitize_key( $icon );
		return '' !== $icon ? $icon : 'star-filled';
	}

	/**
	 * Allowlists an attribute/edit-field name to safe identifier characters,
	 * WITHOUT lowercasing. Unlike sanitize_key(), which is meant for
	 * database/URL-safe slugs and lowercases everything, attribute names are
	 * Gutenberg/JS object keys and are conventionally camelCase (e.g.
	 * "accentColor", "isFeatured") -- lowercasing them here would silently
	 * rename every camelCase attribute the AI generates, breaking any
	 * render_html template or edit_fields entry that references the
	 * original name.
	 *
	 * @param string $identifier Raw attribute/field name.
	 * @return string
	 */
	private static function sanitize_identifier( string $identifier ): string {
		return preg_replace( '/[^a-zA-Z0-9_-]/', '', $identifier ) ?? '';
	}

	/**
	 * Restricts block category to the ones offered in the schema/prompt.
	 *
	 * @param string $category Raw category slug.
	 * @return string
	 */
	private static function sanitize_category( string $category ): string {
		$allowed = array( 'widgets', 'design', 'text', 'theme', 'ai-blocks' );
		return in_array( $category, $allowed, true ) ? $category : 'widgets';
	}

	/**
	 * Validates and coerces the attributes schema.
	 *
	 * @param array<string, mixed> $attributes Raw attributes map.
	 * @return array<string, array{type: string, default: mixed}>
	 */
	private static function sanitize_attributes( array $attributes ): array {
		$clean = array();

		foreach ( $attributes as $key => $config ) {
			$key = self::sanitize_identifier( (string) $key );
			if ( '' === $key || ! is_array( $config ) ) {
				continue;
			}

			$type = is_string( $config['type'] ?? null ) ? $config['type'] : 'string';
			if ( ! in_array( $type, self::ALLOWED_ATTRIBUTE_TYPES, true ) ) {
				$type = 'string';
			}

			$default       = $config['default'] ?? null;
			$clean[ $key ] = array(
				'type'    => $type,
				'default' => self::coerce_default( $type, $default ),
			);
		}

		return $clean;
	}

	/**
	 * Coerces a default value to match its declared attribute type.
	 *
	 * @param string $type  Declared attribute type.
	 * @param mixed  $value Raw default value.
	 * @return mixed
	 */
	private static function coerce_default( string $type, $value ) {
		switch ( $type ) {
			case 'boolean':
				return (bool) $value;
			case 'number':
			case 'integer':
				return is_numeric( $value ) ? $value + 0 : 0;
			case 'array':
				return is_array( $value ) ? $value : array();
			case 'object':
				return is_array( $value ) ? $value : new \stdClass();
			default:
				return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}
	}

	/**
	 * Validates edit-field inspector control definitions, deriving them from
	 * attributes when the AI didn't supply any usable set.
	 *
	 * @param array<int, mixed>                                  $edit_fields Raw edit fields.
	 * @param array<string, array{type: string, default: mixed}> $attributes  Sanitized attributes.
	 * @return array<int, array{name: string, label: string, type: string}>
	 */
	private static function sanitize_edit_fields( array $edit_fields, array $attributes ): array {
		$clean = array();

		foreach ( $edit_fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				continue;
			}

			$name = self::sanitize_identifier( (string) $field['name'] );
			if ( '' === $name || ! isset( $attributes[ $name ] ) ) {
				continue;
			}
			$type = is_string( $field['type'] ?? null ) ? $field['type'] : 'text';
			if ( ! in_array( $type, self::ALLOWED_FIELD_TYPES, true ) ) {
				$type = 'text';
			}

			$entry = array(
				'name'  => $name,
				'label' => sanitize_text_field( (string) ( $field['label'] ?? ucwords( str_replace( array( '_', '-' ), ' ', $name ) ) ) ),
				'type'  => $type,
			);

			if ( 'select' === $type && ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
				$entry['options'] = array_values(
					array_map(
						static function ( $opt ) {
							return array(
								'label' => sanitize_text_field( (string) ( $opt['label'] ?? '' ) ),
								'value' => sanitize_text_field( (string) ( $opt['value'] ?? '' ) ),
							);
						},
						array_filter( $field['options'], 'is_array' )
					)
				);
			}

			$clean[] = $entry;
		}

		if ( empty( $clean ) ) {
			foreach ( $attributes as $key => $attr ) {
				$field_type = 'text';
				if ( 'boolean' === $attr['type'] ) {
					$field_type = 'toggle';
				} elseif ( in_array( $attr['type'], array( 'number', 'integer' ), true ) ) {
					$field_type = 'number';
				}

				$clean[] = array(
					'name'  => $key,
					'label' => ucwords( str_replace( array( '_', '-' ), ' ', $key ) ),
					'type'  => $field_type,
				);
			}
		}

		return $clean;
	}

	/**
	 * Sanitizes the render_html template.
	 *
	 * The template is treated as trusted-once-sanitized post content: it is
	 * passed through wp_kses_post() (extended to allow `style` attributes,
	 * since scoped inline styles are core to how these blocks are designed)
	 * so a saver without `unfiltered_html` cannot smuggle `<script>`, event
	 * handler attributes, or other active content into a page every visitor
	 * will load.
	 *
	 * @param string $html Raw render_html template.
	 * @return string
	 */
	private static function sanitize_render_html( string $html ): string {
		if ( current_user_can( 'unfiltered_html' ) ) {
			return $html;
		}

		$allowed = wp_kses_allowed_html( 'post' );
		foreach ( $allowed as $tag => $attrs ) {
			$attrs['style']  = true;
			$attrs['class']  = true;
			$allowed[ $tag ] = $attrs;
		}

		return wp_kses( $html, $allowed );
	}

	/**
	 * Conservatively sanitizes a raw CSS string.
	 *
	 * This is not a full CSS parser; it strips constructs that are never
	 * legitimate in scoped block styles and would otherwise allow escaping
	 * the <style> element or loading remote/active content.
	 *
	 * @param string $css Raw CSS.
	 * @return string
	 */
	private static function sanitize_css( string $css ): string {
		if ( '' === $css ) {
			return '';
		}

		// Never allow the CSS to close its own <style> tag or introduce markup.
		$css = str_ireplace( array( '</style', '<style', '<script', '</script' ), '', $css );
		$css = wp_strip_all_tags( $css );

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			// Strip @import (arbitrary remote stylesheet loading) and expression()/javascript: (legacy IE XSS vectors).
			$css = preg_replace( '/@import[^;]*;?/i', '', (string) $css );
			$css = preg_replace( '/expression\s*\([^)]*\)/i', '', (string) $css );
			$css = preg_replace( '/javascript\s*:/i', '', (string) $css );
			$css = preg_replace( '/url\s*\(\s*[\'"]?\s*(?!data:|#)(?!https?:)[^)\'"]*[\'"]?\s*\)/i', 'url()', (string) $css );
		}

		return trim( (string) $css );
	}

	/**
	 * Flushes the in-memory/object cache for definitions. Call after any write.
	 */
	public static function flush_cache(): void {
		wp_cache_delete( 'all_definitions', self::CACHE_GROUP );
		// Per-slug entries are left to expire naturally (object cache) or are
		// simply re-fetched (non-persistent cache); enumerating them here
		// would require tracking every slug ever cached this request.
	}
}
