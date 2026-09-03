<?php
/**
 * Plugin Name: AI Block Creator
 * Plugin URI: https://github.com/georgestephanis/ai-block-creator
 * Description: Speak it, type it, or screenshot it into existence. Create, refine, and insert custom Gutenberg blocks on the fly with AI directly inside the editor.
 * Version: 1.0.0
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Author: George Stephanis
 * Author URI: https://georgestephanis.wordpress.com
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: ai-block-creator
 *
 * @package AI_Block_Creator
 */

declare(strict_types=1);

namespace AI_Block_Creator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION    = '1.0.0';
const PLUGIN_DIR = __DIR__;

if ( ! defined( 'AI_BLOCK_CREATOR_URL' ) ) {
	define( 'AI_BLOCK_CREATOR_URL', plugin_dir_url( __FILE__ ) );
}

require_once PLUGIN_DIR . '/includes/class-ai-block-store.php';
require_once PLUGIN_DIR . '/includes/class-ai-block-renderer.php';
require_once PLUGIN_DIR . '/includes/class-ai-block-rest-controller.php';

/**
 * Registers custom post type for storing AI Block Definitions, and the
 * protected meta key that holds each definition's JSON payload.
 */
function register_cpt(): void {
	register_post_type(
		AI_Block_Store::POST_TYPE,
		array(
			'labels'             => array(
				'name'          => __( 'AI Block Definitions', 'ai-block-creator' ),
				'singular_name' => __( 'AI Block Definition', 'ai-block-creator' ),
			),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => false,
			'show_in_menu'       => false,
			'show_in_rest'       => false,
			'query_var'          => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'custom-fields' ),
		)
	);

	register_post_meta(
		AI_Block_Store::POST_TYPE,
		AI_Block_Store::META_KEY,
		array(
			'single'            => true,
			'type'              => 'string',
			'show_in_rest'      => false,
			'sanitize_callback' => __NAMESPACE__ . '\\sanitize_stored_definition_meta',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_cpt', 5 );

/**
 * Sanitizes the raw `_ai_block_definition` meta value on write, regardless
 * of call site, by round-tripping it through the same strict allowlist
 * AI_Block_Store::save() uses. This is defense-in-depth: AI_Block_Store
 * already validates before writing, but any other code path that touches
 * this meta key directly is protected too.
 *
 * Note: sanitize_meta() (which invokes this callback, via the
 * `sanitize_{$object_type}_meta_{$meta_key}` filter) is called from
 * update_metadata() AFTER that function has already run wp_unslash() on the
 * incoming value once. This callback therefore receives an already-unslashed
 * value and must return a clean (also unslashed) value — re-unslashing the
 * input here would corrupt any backslash-containing JSON content (e.g. the
 * `\"` WordPress's own wp_json_encode() produces for double quotes inside
 * render_html), and re-slashing the output would leave a stray layer of
 * slashes in the stored value, since nothing downstream unslashes it again.
 *
 * @param mixed $meta_value Meta value being saved (already unslashed by core).
 * @return string Clean (unslashed), re-validated JSON.
 */
function sanitize_stored_definition_meta( $meta_value ): string {
	$decoded = is_string( $meta_value ) ? json_decode( $meta_value, true ) : null;
	if ( ! is_array( $decoded ) ) {
		return '';
	}

	$normalized = AI_Block_Store::normalize_and_validate( $decoded );
	return (string) wp_json_encode( $normalized );
}

/**
 * Registers the "AI Custom Blocks" block category. Registered once at file
 * scope (not on every `init` callback invocation) since the category list
 * itself never changes at runtime.
 */
add_filter(
	'block_categories_all',
	function ( array $categories ): array {
		foreach ( $categories as $cat ) {
			if ( ( $cat['slug'] ?? '' ) === 'ai-blocks' ) {
				return $categories;
			}
		}

		$categories[] = array(
			'slug'  => 'ai-blocks',
			'title' => __( 'AI Custom Blocks', 'ai-block-creator' ),
			'icon'  => 'star-filled',
		);

		return $categories;
	}
);

/**
 * Dynamically registers all stored AI custom blocks with WordPress,
 * including a per-block style handle so each block's CSS is only enqueued
 * on pages/editor sessions where the block is actually present.
 */
function register_dynamic_blocks(): void {
	foreach ( AI_Block_Store::all() as $def ) {
		if ( empty( $def['name'] ) ) {
			continue;
		}

		$block_name = $def['name'];
		if ( ! str_starts_with( $block_name, 'ai-block/' ) ) {
			$block_name = 'ai-block/' . $block_name;
		}
		$slug = str_replace( 'ai-block/', '', $block_name );

		$attributes_schema = array();
		if ( ! empty( $def['attributes'] ) && is_array( $def['attributes'] ) ) {
			foreach ( $def['attributes'] as $key => $attr ) {
				$attributes_schema[ $key ] = array(
					'type'    => $attr['type'] ?? 'string',
					'default' => $attr['default'] ?? '',
				);
			}
		}

		$style_handle = register_block_style_handle( $slug, $def['css'] ?? '' );

		register_block_type(
			$block_name,
			array(
				'api_version'     => 3,
				'title'           => $def['title'] ?? 'AI Block',
				'category'        => 'ai-blocks',
				'icon'            => $def['icon'] ?? 'star-filled',
				'description'     => $def['description'] ?? '',
				'attributes'      => $attributes_schema,
				'supports'        => array(
					'html'            => false,
					'anchor'          => true,
					'align'           => array( 'wide', 'full' ),
					'customClassName' => true,
				),
				'style'           => $style_handle,
				'editor_style'    => $style_handle,
				'render_callback' => array( AI_Block_Renderer::class, 'render' ),
			)
		);
	}
}
add_action( 'init', __NAMESPACE__ . '\\register_dynamic_blocks', 10 );

/**
 * Registers (or updates) a per-block inline stylesheet handle, only when the
 * block actually has CSS. WordPress enqueues `style`/`editor_style` handles
 * only when the block is used, so — unlike inlining all blocks' CSS on
 * every page — this scales with the number of blocks in use, not the number
 * of blocks that exist.
 *
 * @param string $slug Block slug (without the `ai-block/` prefix).
 * @param string $css  Already-sanitized CSS (AI_Block_Store::save() sanitizes on write).
 * @return string|null Style handle, or null when there is no CSS.
 */
function register_block_style_handle( string $slug, string $css ): ?string {
	if ( empty( $css ) ) {
		return null;
	}

	$handle = 'ai-block-style-' . $slug;

	if ( ! wp_style_is( $handle, 'registered' ) ) {
		wp_register_style( $handle, false, array(), VERSION );
	}

	wp_add_inline_style( $handle, $css );

	return $handle;
}

/**
 * Registers REST API controller.
 */
function register_rest_routes(): void {
	$controller = new AI_Block_REST_Controller();
	$controller->register_routes();
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_routes' );

/**
 * Enqueues assets for the Block Editor.
 */
function enqueue_editor_assets(): void {
	$asset_file = PLUGIN_DIR . '/build/index.asset.php';
	$asset      = file_exists( $asset_file )
		? require $asset_file
		: array(
			'dependencies' => array(
				'wp-blocks',
				'wp-element',
				'wp-components',
				'wp-block-editor',
				'wp-data',
				'wp-editor',
				'wp-plugins',
				'wp-i18n',
				'wp-api-fetch',
				'wp-notices',
			),
			'version'      => VERSION,
		);

	wp_enqueue_script(
		'ai-block-creator-editor',
		AI_BLOCK_CREATOR_URL . 'build/index.js',
		$asset['dependencies'],
		$asset['version'],
		true
	);

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'ai-block-creator-editor', 'ai-block-creator', PLUGIN_DIR . '/languages' );
	}

	if ( file_exists( PLUGIN_DIR . '/build/index.css' ) ) {
		wp_enqueue_style(
			'ai-block-creator-editor-styles',
			AI_BLOCK_CREATOR_URL . 'build/index.css',
			array( 'wp-components', 'wp-block-editor' ),
			$asset['version']
		);
	}

	wp_add_inline_script(
		'ai-block-creator-editor',
		'var aiBlockCreatorSettings = ' . wp_json_encode(
			array(
				'savedBlocks'      => AI_Block_Store::all(),
				'hasAiClient'      => function_exists( 'wp_ai_client_prompt' ),
				'aiSupported'      => function_exists( 'wp_supports_ai' ) ? wp_supports_ai() : function_exists( 'wp_ai_client_prompt' ),
				'canManageLibrary' => current_user_can( 'unfiltered_html' ),
			)
		) . ';',
		'before'
	);
}
add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\enqueue_editor_assets' );
