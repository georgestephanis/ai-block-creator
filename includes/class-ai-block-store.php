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
	 * Definition kind: a block pattern — a ready-made arrangement of blocks
	 * that already exist, registered via register_block_pattern(). Contributes
	 * no new block, no style and no variation; inserting it drops ordinary
	 * blocks into the post, which the author then edits normally.
	 *
	 * @var string
	 */
	public const KIND_BLOCK_PATTERN = 'block_pattern';

	/**
	 * Namespace every AI-authored pattern is registered under. Pattern names
	 * are `namespace/slug`, but definitions store only the slug half (matching
	 * every other kind), so the namespace is prepended at registration time.
	 *
	 * @var string
	 */
	public const PATTERN_NAMESPACE = 'ai-block-creator';

	/**
	 * Every recognized definition kind.
	 *
	 * @var string[]
	 */
	public const ALLOWED_KINDS = array(
		self::KIND_CUSTOM_BLOCK,
		self::KIND_BLOCK_STYLE,
		self::KIND_BLOCK_VARIATION,
		self::KIND_BLOCK_PATTERN,
	);

	/**
	 * Maximum stored length of a pattern's serialized block markup. Generous
	 * for any plausible pattern, but bounded: this is model output landing in
	 * post meta, so it should not be able to grow without limit.
	 *
	 * @var int
	 */
	private const MAX_PATTERN_CONTENT_BYTES = 100000;

	/**
	 * Maximum nesting depth walked when sanitizing a pattern's block tree.
	 * Blocks nested deeper than this are dropped rather than recursed into.
	 *
	 * @var int
	 */
	private const MAX_PATTERN_DEPTH = 10;

	/**
	 * Maximum nesting depth kept when sanitizing attribute *values*.
	 *
	 * Block attribute values nest deeper than they first appear: core's own
	 * markup routinely carries `style.spacing.padding.top` (4) and
	 * `style.elements.link.color.text` (5), and a pseudo-selector variant like
	 * `style.elements.link.:hover.color.text` reaches 6. A cap below that
	 * silently strips real styling, so this is set above the deepest shape
	 * WordPress itself emits while still bounding what can be stored.
	 *
	 * @var int
	 */
	private const MAX_ATTRIBUTE_DEPTH = 8;

	/**
	 * Maximum at-rule nesting recursed into when scoping a style's CSS.
	 *
	 * @var int
	 */
	private const MAX_AT_RULE_DEPTH = 5;

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
	public const ALLOWED_FIELD_TYPES = array( 'text', 'textarea', 'color', 'toggle', 'url', 'number', 'select' );

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

		// Kinds are namespaced in post_name (see post_slug_for()), so a bare
		// slug only ever resolves a custom block. Looking under the other
		// prefixes too means a caller asking for a style by its name gets the
		// style, rather than a null that reads as "no such definition".
		$candidate_slugs = array( $slug, 'style-' . $slug, 'variation-' . $slug, 'pattern-' . $slug );

		$posts = get_posts(
			array(
				'post_type'           => self::POST_TYPE,
				'post_name__in'       => $candidate_slugs,
				'posts_per_page'      => 1,
				'post_status'         => 'publish',
				'orderby'             => 'post_name__in',
				'ignore_sticky_posts' => true,
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
			case self::KIND_BLOCK_PATTERN:
				return 'pattern-' . $name;
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
			case self::KIND_BLOCK_PATTERN:
				return self::normalize_block_pattern( $def, $title_fallback );
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

		$slug = self::prefixed_slug( $def, $label, 'style' );

		$normalized = array(
			'kind'         => self::KIND_BLOCK_STYLE,
			'name'         => $slug,
			'label'        => $label,
			'title'        => $label,
			'description'  => sanitize_text_field( (string) ( $def['description'] ?? '' ) ),
			'target_block' => self::sanitize_target_block( (string) ( $def['target_block'] ?? '' ) ),
			'css'          => self::scope_css(
				self::sanitize_css( is_string( $def['css'] ?? null ) ? $def['css'] : '' ),
				'.is-style-' . $slug
			),
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

		$slug = self::prefixed_slug( $def, $title, 'variation' );

		$target_block = self::sanitize_target_block( (string) ( $def['target_block'] ?? '' ) );

		$attributes = self::sanitize_attribute_values( is_array( $def['attributes'] ?? null ) ? $def['attributes'] : array() );
		$css        = self::sanitize_css( is_string( $def['css'] ?? null ) ? $def['css'] : '' );

		// A variation's stylesheet is enqueued for the whole target block type
		// — that is the block that will be on the page — so an unscoped
		// selector in it restyles every instance of that block, not just the
		// ones using this variation. Unlike a style, a variation has no class
		// of its own to scope to, so one is minted here and preset on the
		// variation, giving the CSS something real to be confined to.
		if ( '' !== $css ) {
			$marker     = 'ai-variation-' . $slug;
			$attributes = self::ensure_class_name( $attributes, $marker );
			$css        = self::scope_css( $css, '.' . $marker );
		}

		$normalized = array(
			'kind'              => self::KIND_BLOCK_VARIATION,
			'name'              => $slug,
			'title'             => $title,
			'description'       => sanitize_text_field( (string) ( $def['description'] ?? '' ) ),
			'icon'              => self::sanitize_icon( (string) ( $def['icon'] ?? 'star-filled' ) ),
			'target_block'      => $target_block,
			'attributes'        => self::filter_to_block_attributes( $attributes, $target_block ),
			'inner_block_names' => self::sanitize_inner_block_names( is_array( $def['inner_block_names'] ?? null ) ? $def['inner_block_names'] : array() ),
			'css'               => $css,
		);

		if ( ! empty( $def['id'] ) ) {
			$normalized['id'] = (int) $def['id'];
		}

		return $normalized;
	}

	/**
	 * Normalizes a block pattern definition.
	 *
	 * A pattern is serialized block markup: no new block type, no style, no
	 * variation. Inserting it drops ordinary blocks into the post, which the
	 * author then edits like any other content — which is also why `content`
	 * is held to the same trust boundary as post content itself.
	 *
	 * @param array<string, mixed> $def            Raw definition.
	 * @param string               $title_fallback Fallback title.
	 * @return array<string, mixed>
	 */
	private static function normalize_block_pattern( array $def, string $title_fallback ): array {
		$title = ! empty( $def['title'] ) && is_string( $def['title'] )
			? sanitize_text_field( $def['title'] )
			: $title_fallback;

		$slug = self::prefixed_slug( $def, $title, 'pattern' );

		$normalized = array(
			'kind'           => self::KIND_BLOCK_PATTERN,
			'name'           => $slug,
			'title'          => $title,
			'description'    => sanitize_text_field( (string) ( $def['description'] ?? '' ) ),
			'keywords'       => self::sanitize_keywords( is_array( $def['keywords'] ?? null ) ? $def['keywords'] : array() ),
			'viewport_width' => self::sanitize_viewport_width( $def['viewport_width'] ?? null ),
			'content'        => self::sanitize_pattern_content( is_string( $def['content'] ?? null ) ? $def['content'] : '' ),
		);

		if ( ! empty( $def['id'] ) ) {
			$normalized['id'] = (int) $def['id'];
		}

		return $normalized;
	}

	/**
	 * Validates and re-serializes a pattern's block markup.
	 *
	 * The markup is round-tripped through parse_blocks() → validation →
	 * serialize_blocks() rather than being string-filtered. That matters for
	 * two reasons: block delimiters are HTML comments, which wp_kses() strips
	 * outright (so filtering the raw markup would destroy it), and parsing is
	 * what makes it possible to check the thing that actually needs checking —
	 * that every block in the tree is one this site has registered. Anything
	 * else is dropped, so a pattern can't smuggle in a reference to a block
	 * type that doesn't exist (which renders as a broken-block warning) or
	 * carry an unbounded nested payload.
	 *
	 * @param string $content Raw serialized block markup.
	 * @return string Clean block markup.
	 */
	private static function sanitize_pattern_content( string $content ): string {
		if ( '' === trim( $content ) ) {
			return '';
		}

		if ( strlen( $content ) > self::MAX_PATTERN_CONTENT_BYTES ) {
			// mb_strcut, not substr: cutting mid-character would leave invalid
			// UTF-8 in post meta. Any block left incomplete by the cut is then
			// dropped by the parse/serialize round-trip below, so an oversized
			// pattern degrades to its first N whole blocks rather than to
			// broken markup.
			$content = mb_strcut( $content, 0, self::MAX_PATTERN_CONTENT_BYTES );
		}

		$blocks = self::sanitize_block_list( parse_blocks( $content ), 0 );

		return trim( serialize_blocks( $blocks ) );
	}

	/**
	 * Sanitizes a list of parsed blocks, dropping any that don't survive.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param int                              $depth  Current nesting depth.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sanitize_block_list( array $blocks, int $depth ): array {
		if ( $depth > self::MAX_PATTERN_DEPTH ) {
			return array();
		}

		$clean = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$sanitized = self::sanitize_block_node( $block, $depth );
			if ( null !== $sanitized ) {
				$clean[] = $sanitized;
			}
		}

		return $clean;
	}

	/**
	 * Sanitizes one parsed block node.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @param int                  $depth Current nesting depth.
	 * @return array<string, mixed>|null The clean node, or null if it should be dropped.
	 */
	private static function sanitize_block_node( array $block, int $depth ): ?array {
		$block_name = $block['blockName'] ?? null;

		// A null blockName is the parser's representation of loose HTML
		// between blocks — usually just the newlines separating them. Those
		// are kept (dropping them would run every block together on
		// re-serialization) but held to the same markup allowlist.
		if ( null !== $block_name ) {
			$block_name = self::validate_block_name( (string) $block_name );
			if ( null === $block_name ) {
				return null;
			}
		}

		$inner_blocks  = is_array( $block['innerBlocks'] ?? null ) ? array_values( $block['innerBlocks'] ) : array();
		$inner_content = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array();

		$clean_inner_blocks  = array();
		$clean_inner_content = array();
		$inner_index         = 0;

		// innerContent interleaves literal HTML chunks with nulls, one null per
		// inner block, in order. Dropping an inner block therefore means also
		// dropping its null placeholder, or serialize_blocks() would pair every
		// subsequent block with the wrong slot.
		foreach ( $inner_content as $chunk ) {
			if ( null !== $chunk ) {
				$clean_inner_content[] = self::sanitize_render_html( (string) $chunk );
				continue;
			}

			$child = $inner_blocks[ $inner_index ] ?? null;
			++$inner_index;

			if ( ! is_array( $child ) ) {
				continue;
			}

			$sanitized_child = self::sanitize_block_node( $child, $depth + 1 );
			if ( null === $sanitized_child ) {
				continue;
			}

			$clean_inner_blocks[]  = $sanitized_child;
			$clean_inner_content[] = null;
		}

		$attrs = is_array( $block['attrs'] ?? null ) ? self::sanitize_attribute_values( $block['attrs'] ) : array();

		return array(
			'blockName'    => $block_name,
			'attrs'        => $attrs,
			'innerBlocks'  => $clean_inner_blocks,
			'innerHTML'    => self::sanitize_render_html( (string) ( $block['innerHTML'] ?? '' ) ),
			'innerContent' => $clean_inner_content,
		);
	}

	/**
	 * Validates a block name for inclusion in a pattern.
	 *
	 * Unlike sanitize_target_block(), an unrecognized name has no sensible
	 * substitute here — a pattern is a specific arrangement, and quietly
	 * swapping in a Group would change what it is — so this returns null and
	 * the caller drops the block.
	 *
	 * @param string $block_name Raw block name.
	 * @return string|null Valid block name, or null.
	 */
	private static function validate_block_name( string $block_name ): ?string {
		$block_name = strtolower( trim( $block_name ) );

		if ( ! preg_match( '#^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$#', $block_name ) ) {
			return null;
		}

		// As in sanitize_target_block(), registry membership is only enforced
		// when the registry is actually populated — this can run from the meta
		// sanitize callback before `init` has registered anything.
		$registered = self::registered_block_names();
		if ( ! empty( $registered ) && ! in_array( $block_name, $registered, true ) ) {
			return null;
		}

		return $block_name;
	}

	/**
	 * Allowlists a pattern's inserter keywords.
	 *
	 * @param array<int, mixed> $keywords Raw keywords.
	 * @return string[]
	 */
	private static function sanitize_keywords( array $keywords ): array {
		$clean = array();

		foreach ( array_slice( $keywords, 0, 10 ) as $keyword ) {
			if ( ! is_string( $keyword ) ) {
				continue;
			}

			$keyword = sanitize_text_field( $keyword );
			if ( '' !== $keyword ) {
				$clean[] = $keyword;
			}
		}

		return $clean;
	}

	/**
	 * Clamps a pattern's preview viewport width to a sane pixel range.
	 *
	 * @param mixed $width Raw viewport width.
	 * @return int
	 */
	private static function sanitize_viewport_width( $width ): int {
		$width = is_numeric( $width ) ? (int) $width : 1200;

		return max( 320, min( 2400, $width ) );
	}

	/**
	 * Builds the slug (and registered name) for a style, variation or pattern.
	 *
	 * All three are namespaced with an `ai-` prefix so an AI-authored entry is
	 * visibly distinct from a theme's or core's own wherever it shows up, and
	 * so a generated CSS class (`.is-style-ai-…`) can't collide with one the
	 * target block already ships.
	 *
	 * @param array<string, mixed> $def      Raw definition.
	 * @param string               $fallback Label/title to derive a slug from when none is given.
	 * @param string               $context  'style', 'variation' or 'pattern'; used only for the random fallback.
	 * @return string
	 */
	private static function prefixed_slug( array $def, string $fallback, string $context ): string {
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
	 * Confines every selector in a block style's CSS to that style's own class.
	 *
	 * The prompt already asks the model to scope its selectors, and a
	 * well-behaved response passes through here untouched. But a style is
	 * registered site-wide, so a single unscoped `blockquote { … }` restyles
	 * every quote on the site — silently, and only for the pages an author
	 * happens not to be looking at. Instruction is the wrong enforcement
	 * mechanism for that; this is.
	 *
	 * Deliberately not a full CSS parser. It tracks braces, strings and
	 * comments well enough to tell a selector list from a declaration block and
	 * to recurse into conditional at-rules, and it leaves anything it does not
	 * understand alone rather than guessing.
	 *
	 * @param string $css   Already-sanitized CSS.
	 * @param string $scope Scope selector, e.g. `.is-style-ai-gold-pullquote`.
	 * @param int    $depth Current at-rule nesting depth.
	 * @return string CSS with every rule confined to $scope.
	 */
	private static function scope_css( string $css, string $scope, int $depth = 0 ): string {
		if ( '' === trim( $css ) || $depth > self::MAX_AT_RULE_DEPTH ) {
			return $css;
		}

		$out           = '';
		$length        = strlen( $css );
		$prelude_start = 0;
		$i             = 0;

		while ( $i < $length ) {
			$char = $css[ $i ];

			if ( '"' === $char || "'" === $char ) {
				$i = self::skip_css_string( $css, $i );
				continue;
			}

			if ( '/' === $char && $i + 1 < $length && '*' === $css[ $i + 1 ] ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = ( false === $end ) ? $length : $end + 2;
				continue;
			}

			// A semicolon at this level ends a statement with no block of its
			// own (`@charset "utf-8";`). Emit it verbatim; there is no selector.
			if ( ';' === $char ) {
				$out .= substr( $css, $prelude_start, $i - $prelude_start + 1 );
				++$i;
				$prelude_start = $i;
				continue;
			}

			if ( '{' !== $char ) {
				++$i;
				continue;
			}

			$prelude  = substr( $css, $prelude_start, $i - $prelude_start );
			$body_end = self::find_matching_brace( $css, $i );

			if ( null === $body_end ) {
				// Unbalanced braces. Passing the remainder through verbatim
				// would leak an unscoped rule, because a browser closes the
				// final block for us and applies it anyway — so scope it and
				// close it here instead.
				return $out . self::scope_selector_list( $prelude, $scope ) . '{' . substr( $css, $i + 1 ) . '}';
			}

			$body    = substr( $css, $i + 1, $body_end - $i - 1 );
			$trimmed = ltrim( $prelude );

			if ( '' !== $trimmed && '@' === $trimmed[0] ) {
				// Conditional groups wrap ordinary rules, so their contents
				// still need scoping. Everything else that takes a block
				// (@keyframes, @font-face, @property, @page…) contains
				// keyframe selectors or descriptors, not selectors — scoping
				// those would corrupt them.
				$body = preg_match( '/^@(media|supports|container|layer|scope)\b/i', $trimmed )
					? self::scope_css( $body, $scope, $depth + 1 )
					: $body;

				$out .= $prelude . '{' . $body . '}';
			} else {
				$out .= self::scope_selector_list( $prelude, $scope ) . '{' . $body . '}';
			}

			$i             = $body_end + 1;
			$prelude_start = $i;
		}

		return $out . substr( $css, $prelude_start );
	}

	/**
	 * Prefixes each selector in a comma-separated list with the scope.
	 *
	 * A selector that already mentions the scope class is left exactly as
	 * written — that is the well-formed case, and rewriting it would be both
	 * pointless and a way to introduce bugs into CSS that was already correct.
	 *
	 * @param string $selector_list Raw selector list (may carry leading whitespace).
	 * @param string $scope         Scope selector.
	 * @return string
	 */
	private static function scope_selector_list( string $selector_list, string $scope ): string {
		// Preserve the original leading whitespace so the output keeps the
		// input's formatting rather than collapsing onto one line.
		$leading = '';
		if ( preg_match( '/^\s+/', $selector_list, $match ) ) {
			$leading = $match[0];
		}

		// Comments are dropped before splitting: they mean nothing in a
		// selector, and one containing a comma ("/* h1, h2 */") would otherwise
		// be split across two selectors and mangle both.
		$without_comments = (string) preg_replace( '#/\*.*?\*/#s', '', trim( $selector_list ) );

		$scoped = array();
		foreach ( self::split_selector_list( $without_comments ) as $selector ) {
			$selector = trim( $selector );
			if ( '' === $selector ) {
				continue;
			}

			if ( false !== strpos( $selector, $scope ) ) {
				$scoped[] = $selector;
				continue;
			}

			// `:root`, `html` and `body` can never appear inside a block, so
			// scoping them the usual way would produce a selector that matches
			// nothing — quietly deleting, say, the custom properties the rest
			// of the style depends on. Map them onto the block's own root
			// instead, which both preserves the intent and contains it.
			if ( preg_match( '/^(:root|html|body)$/i', $selector ) ) {
				$scoped[] = $scope;
				continue;
			}

			// Descendant form covers the usual case (`cite` meaning "the cite
			// inside this block").
			$scoped[] = $scope . ' ' . $selector;

			// A *simple* selector may also have been meant as the block's own
			// root element (`blockquote` on a Quote block), which the
			// descendant form would never match. Compound selectors are not
			// given this treatment: `cite em` cannot describe a single element.
			if ( ! preg_match( '/[\s>+~]/', $selector ) ) {
				$scoped[] = $scope . ':is(' . $selector . ')';
			}
		}

		return empty( $scoped ) ? $selector_list : $leading . implode( ', ', $scoped ) . ' ';
	}

	/**
	 * Splits a selector list on top-level commas only, so commas inside
	 * `:is(a, b)`, `[attr="a,b"]` and the like stay put.
	 *
	 * @param string $selector_list Selector list.
	 * @return string[]
	 */
	private static function split_selector_list( string $selector_list ): array {
		$parts  = array();
		$buffer = '';
		$depth  = 0;
		$length = strlen( $selector_list );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $selector_list[ $i ];

			if ( '"' === $char || "'" === $char ) {
				$end     = self::skip_css_string( $selector_list, $i );
				$buffer .= substr( $selector_list, $i, $end - $i );
				$i       = $end - 1;
				continue;
			}

			if ( '(' === $char || '[' === $char ) {
				++$depth;
			} elseif ( ')' === $char || ']' === $char ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( ',' === $char && 0 === $depth ) {
				$parts[] = $buffer;
				$buffer  = '';
				continue;
			}

			$buffer .= $char;
		}

		$parts[] = $buffer;

		return $parts;
	}

	/**
	 * Returns the offset of the `}` matching the `{` at $open, or null when the
	 * CSS is unbalanced.
	 *
	 * @param string $css  CSS being scanned.
	 * @param int    $open Offset of the opening brace.
	 * @return int|null
	 */
	private static function find_matching_brace( string $css, int $open ): ?int {
		$depth  = 0;
		$length = strlen( $css );

		for ( $i = $open; $i < $length; $i++ ) {
			$char = $css[ $i ];

			if ( '"' === $char || "'" === $char ) {
				$i = self::skip_css_string( $css, $i ) - 1;
				continue;
			}

			if ( '/' === $char && $i + 1 < $length && '*' === $css[ $i + 1 ] ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = ( false === $end ) ? $length : $end + 1;
				continue;
			}

			if ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}

		return null;
	}

	/**
	 * Returns the offset just past the string literal starting at $start.
	 *
	 * @param string $css   CSS being scanned.
	 * @param int    $start Offset of the opening quote.
	 * @return int
	 */
	private static function skip_css_string( string $css, int $start ): int {
		$quote  = $css[ $start ];
		$length = strlen( $css );

		for ( $i = $start + 1; $i < $length; $i++ ) {
			if ( '\\' === $css[ $i ] ) {
				++$i;
				continue;
			}
			if ( $css[ $i ] === $quote ) {
				return $i + 1;
			}
		}

		return $length;
	}

	/**
	 * Returns a registered block's own attribute schema — which is exactly what
	 * its `block.json` declares — or an empty array when the block isn't
	 * registered (or has no attributes).
	 *
	 * @param string $block_name Block name, e.g. `core/media-text`.
	 * @return array<string, array<string, mixed>> Attribute name => schema.
	 */
	public static function registered_block_attributes( string $block_name ): array {
		if ( '' === $block_name || ! class_exists( '\\WP_Block_Type_Registry' ) ) {
			return array();
		}

		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
		if ( ! $block_type || ! is_array( $block_type->attributes ) ) {
			return array();
		}

		return $block_type->attributes;
	}

	/**
	 * Sanitizes a flat map of concrete attribute *values* (as opposed to an
	 * attribute schema — see sanitize_attributes()).
	 *
	 * Values may be scalars or nested arrays/objects up to
	 * MAX_ATTRIBUTE_DEPTH levels, which is deeper than any shape WordPress
	 * itself emits; beyond that a branch is omitted rather than recursed into,
	 * so a definition can't carry an unbounded nested payload into post meta.
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
			} elseif ( is_array( $value ) && $depth < self::MAX_ATTRIBUTE_DEPTH ) {
				$nested = self::sanitize_attribute_values( $value, $depth + 1 );

				// An emptied branch is omitted rather than stored as an empty
				// array. `{"style":{"elements":[]}}` is not a smaller version
				// of the original — it's the wrong *type* (Gutenberg expects an
				// object there), which is worse than the key being absent.
				if ( ! empty( $nested ) ) {
					$clean[ $key ] = $nested;
				}
			}
		}

		return $clean;
	}

	/**
	 * Adds a class to an attribute set's `className`, preserving any already there.
	 *
	 * If the target block turns out not to support `className`,
	 * filter_to_block_attributes() drops it afterwards and the scoped CSS
	 * simply matches nothing. That is the intended degradation: CSS that is
	 * inert is better than CSS that leaks onto every instance of the block.
	 *
	 * @param array<string, mixed> $attributes Attribute values.
	 * @param string               $class_name Class to ensure is present.
	 * @return array<string, mixed>
	 */
	private static function ensure_class_name( array $attributes, string $class_name ): array {
		$existing = isset( $attributes['className'] ) && is_string( $attributes['className'] )
			? trim( $attributes['className'] )
			: '';

		if ( '' === $existing ) {
			$attributes['className'] = $class_name;
			return $attributes;
		}

		$classes = preg_split( '/\s+/', $existing );
		if ( ! is_array( $classes ) ) {
			$classes = array();
		}

		if ( ! in_array( $class_name, $classes, true ) ) {
			$classes[] = $class_name;
		}

		$attributes['className'] = implode( ' ', $classes );

		return $attributes;
	}

	/**
	 * Drops attributes the target block doesn't actually declare.
	 *
	 * A variation presets attributes on a block that already exists, so the
	 * block's own `block.json` schema is the authority on which names are real.
	 * An invented attribute isn't dangerous, but it is silently inert — it
	 * looks like it configures something and does nothing — which is worse than
	 * it not being there, because the author has no way to tell the difference.
	 *
	 * As elsewhere, this is skipped when the block isn't registered (which
	 * includes running before `init`), so an unknown target degrades to keeping
	 * what was given rather than discarding all of it.
	 *
	 * @param array<string, mixed> $attributes   Sanitized attribute values.
	 * @param string               $target_block Target block name.
	 * @return array<string, mixed>
	 */
	private static function filter_to_block_attributes( array $attributes, string $target_block ): array {
		$known = self::registered_block_attributes( $target_block );
		if ( empty( $known ) ) {
			return $attributes;
		}

		return array_intersect_key( $attributes, $known );
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
