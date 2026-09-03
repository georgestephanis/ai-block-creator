<?php
/**
 * AI Block Renderer.
 *
 * @package AI_Block_Creator
 */

declare(strict_types=1);

namespace AI_Block_Creator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders AI-generated dynamic blocks on the front end from their stored
 * mustache-style HTML template and CSS.
 */
class AI_Block_Renderer {

	/**
	 * Renders a dynamic AI block.
	 *
	 * @param array     $attributes Block attributes.
	 * @param string    $content    Block inner content.
	 * @param \WP_Block $block      Block instance.
	 * @return string Rendered HTML.
	 */
	public static function render( array $attributes, string $content, \WP_Block $block ): string {
		$block_name = $block->name; // e.g. ai-block/pricing-table.
		$block_def  = AI_Block_Store::get( $block_name );

		if ( ! $block_def ) {
			return '<div class="ai-block-error">' . esc_html__( 'Block definition not found.', 'ai-block-creator' ) . '</div>';
		}

		$template = $block_def['render_html'] ?? '';
		$block_id = 'ai-block-' . sanitize_title( str_replace( 'ai-block/', '', $block_name ) );

		// Merge defaults.
		$defaults = array();
		if ( ! empty( $block_def['attributes'] ) && is_array( $block_def['attributes'] ) ) {
			foreach ( $block_def['attributes'] as $key => $attr_config ) {
				$defaults[ $key ] = $attr_config['default'] ?? '';
			}
		}
		$merged_attrs = array_merge( $defaults, $attributes );

		// Get WordPress block wrapper attributes.
		$wrapper_attributes = get_block_wrapper_attributes(
			array(
				'class' => $block_id . ' ai-custom-block',
			)
		);

		$inner_html = self::render_template( $template, $merged_attrs );

		// Always apply the wrapper: either substituting an explicit placeholder,
		// or wrapping the rendered output in a <div> that carries it. Without
		// this, block supports (align, anchor, custom className) and the
		// scoping class used by the block's CSS never reach the front end.
		if ( strpos( $template, '{{wrapper_attributes}}' ) !== false ) {
			$rendered_html = str_replace( '{{wrapper_attributes}}', $wrapper_attributes, $inner_html );
		} else {
			$rendered_html = sprintf( '<div %s>%s</div>', $wrapper_attributes, $inner_html );
		}

		return $rendered_html;
	}

	/**
	 * Interpolates attributes into HTML template safely.
	 *
	 * Escaping is context-blind (there is no HTML parser here), so it uses
	 * the pattern immediately surrounding each placeholder to pick an
	 * escaper: `href="{{x}}"` / `src="{{x}}"` get esc_url(), `style="...{{x}}..."`
	 * gets safecss_filter_attr(), everything else gets esc_html() (or
	 * esc_attr() when quoted inside an arbitrary attribute).
	 *
	 * @param string $template HTML template with mustache-style tags.
	 * @param array  $attributes Attribute values.
	 * @return string
	 */
	public static function render_template( string $template, array $attributes ): string {
		if ( empty( $template ) ) {
			return '';
		}

		// Raw variables: {{{attributeName}}} for HTML-safe content, explicitly
		// opted into. Must run BEFORE the {{var}} pass, since {{{x}}} contains
		// a valid {{x}} match that would otherwise be consumed first.
		$template = preg_replace_callback(
			'/\{\{\{([a-zA-Z0-9_-]+)\}\}\}/',
			function ( $matches ) use ( $attributes ) {
				$key = $matches[1];
				if ( ! isset( $attributes[ $key ] ) ) {
					return '';
				}
				return wp_kses_post( (string) $attributes[ $key ] );
			},
			$template
		);

		// Process conditional blocks: {{#if isFeatured}}...{{/if}} or {{^if isFeatured}}...{{/if}}.
		// Nesting of the SAME tag type is supported via a small stack-based
		// scanner; a plain regex with non-greedy matching pairs the first
		// {{#if}} with the first {{/if}}, breaking on nested conditionals.
		$template = self::process_conditionals( $template, $attributes, '#if', true );
		$template = self::process_conditionals( $template, $attributes, '^if', false );

		// Process list repeaters: {{#list features}}<li>{{item}}</li>{{/list}}.
		$template = preg_replace_callback(
			'/\{\{#list\s+([a-zA-Z0-9_-]+)\}\}(.*?)\{\{\/list\}\}/s',
			function ( $matches ) use ( $attributes ) {
				$var           = $matches[1];
				$item_template = $matches[2];
				$val           = $attributes[ $var ] ?? '';

				$items = array();
				if ( is_array( $val ) ) {
					$items = $val;
				} elseif ( is_string( $val ) ) {
					$items = array_filter( array_map( 'trim', explode( "\n", $val ) ) );
				}

				$output = '';
				foreach ( $items as $item ) {
					$item_str = is_array( $item ) ? wp_json_encode( $item ) : (string) $item;
					$output  .= str_replace( '{{item}}', esc_html( $item_str ), $item_template );
				}
				return $output;
			},
			$template
		);

		// Replace regular variables: {{attributeName}}, escaping by the
		// attribute context they're found in.
		$rendered = preg_replace_callback(
			'/(?:(href|src)\s*=\s*")\{\{([a-zA-Z0-9_-]+)\}\}"|(?:style\s*=\s*")([^"]*)\{\{([a-zA-Z0-9_-]+)\}\}([^"]*)"|\{\{([a-zA-Z0-9_-]+)\}\}/',
			function ( $matches ) use ( $attributes ) {
				// URL context: href="{{x}}" or src="{{x}}".
				if ( ! empty( $matches[1] ) && isset( $matches[2] ) ) {
					$val = $attributes[ $matches[2] ] ?? '';
					return $matches[1] . '="' . esc_url( (string) self::stringify( $val ) ) . '"';
				}

				// style="...{{x}}..." context: filter through the CSS attribute allowlist.
				if ( isset( $matches[4] ) && '' !== $matches[4] ) {
					$key        = $matches[4];
					$val        = $attributes[ $key ] ?? '';
					$safe_value = safecss_filter_attr( (string) self::stringify( $val ) );
					return 'style="' . $matches[3] . esc_attr( $safe_value ) . $matches[5] . '"';
				}

				// Generic {{x}} in text/attribute context.
				$key = $matches[6] ?? '';
				if ( '' === $key || ! isset( $attributes[ $key ] ) ) {
					return '';
				}

				return esc_html( self::stringify( $attributes[ $key ] ) );
			},
			$rendered ?? $template
		);

		return (string) $rendered;
	}

	/**
	 * Coerces an attribute value into a display string.
	 *
	 * @param mixed $val Attribute value.
	 * @return string
	 */
	private static function stringify( $val ): string {
		if ( is_bool( $val ) ) {
			return $val ? '1' : '0';
		}
		if ( is_array( $val ) ) {
			return implode( ', ', array_map( 'strval', $val ) );
		}
		return (string) $val;
	}

	/**
	 * Stack-based processor for {{#if x}}...{{/if}} / {{^if x}}...{{/if}}
	 * blocks, supporting arbitrary nesting of the same tag type.
	 *
	 * @param string $template   Template containing the tag pairs.
	 * @param array  $attributes Attribute values.
	 * @param string $open_tag   '#if' or '^if'.
	 * @param bool   $show_when_truthy True to keep inner content when the attribute is truthy (i.e. #if), false for ^if.
	 * @return string
	 */
	private static function process_conditionals( string $template, array $attributes, string $open_tag, bool $show_when_truthy ): string {
		$open_re = '/\{\{' . preg_quote( $open_tag, '/' ) . '\s+([a-zA-Z0-9_-]+)\}\}/';
		$close   = '{{/if}}';

		// Fast path: no tags of this type present.
		if ( strpos( $template, '{{' . $open_tag . ' ' ) === false ) {
			return $template;
		}

		$result = '';
		$cursor = 0;
		$length = strlen( $template );

		while ( $cursor < $length ) {
			if ( ! preg_match( $open_re, $template, $m, PREG_OFFSET_CAPTURE, $cursor ) ) {
				$result .= substr( $template, $cursor );
				break;
			}

			list($full_match, $offset) = $m[0];
			$var                       = $m[1][0];
			$open_len                  = strlen( $full_match );
			$body_start                = $offset + $open_len;

			$result .= substr( $template, $cursor, $offset - $cursor );

			// Find the matching {{/if}}, accounting for nested {{#if}}/{{^if}} of the same variety.
			$depth     = 1;
			$search    = $body_start;
			$close_pos = false;
			while ( $depth > 0 ) {
				$next_open  = strpos( $template, '{{' . $open_tag . ' ', $search );
				$next_close = strpos( $template, $close, $search );

				if ( false === $next_close ) {
					// Unbalanced template; bail out treating the rest as body.
					$close_pos = $length;
					$depth     = 0;
					break;
				}

				if ( false !== $next_open && $next_open < $next_close ) {
					++$depth;
					$search = $next_open + strlen( '{{' . $open_tag . ' ' );
				} else {
					--$depth;
					$search = $next_close + strlen( $close );
					if ( 0 === $depth ) {
						$close_pos = $next_close;
					}
				}
			}

			$body = substr( $template, $body_start, $close_pos - $body_start );

			$is_truthy = ! empty( $attributes[ $var ] );
			if ( $is_truthy === $show_when_truthy ) {
				// Recurse so nested conditionals inside a kept block are also resolved.
				$result .= self::process_conditionals( $body, $attributes, $open_tag, $show_when_truthy );
			}

			$cursor = $close_pos + strlen( $close );
		}

		return $result;
	}

	/**
	 * Gets a block definition by block name.
	 *
	 * @deprecated Use AI_Block_Store::get() directly.
	 * @param string $block_name Block slug (e.g. ai-block/pricing-table).
	 * @return array|null
	 */
	public static function get_block_definition( string $block_name ): ?array {
		return AI_Block_Store::get( $block_name );
	}
}
