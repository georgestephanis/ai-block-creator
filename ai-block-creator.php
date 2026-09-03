<?php
/**
 * Plugin Name: AI Block Creator
 * Plugin URI: https://github.com/georgestephanis/ai-block-creator
 * Description: Speak it, type it, or screenshot it into existence. Create, refine, and insert custom Gutenberg blocks on the fly with AI directly inside the editor.
 * Version: 1.0.0
 * Requires at least: 6.7
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

if (!defined('ABSPATH')) {
    exit;
}

const VERSION = '1.0.0';
const PLUGIN_DIR = __DIR__;

if (!defined('AI_BLOCK_CREATOR_URL')) {
    define('AI_BLOCK_CREATOR_URL', plugin_dir_url(__FILE__));
}

require_once PLUGIN_DIR . '/includes/class-ai-block-renderer.php';
require_once PLUGIN_DIR . '/includes/class-ai-block-rest-controller.php';

/**
 * Registers custom post type for storing AI Block Definitions.
 */
function register_cpt(): void
{
    register_post_type('wp_block_def', array(
        'labels' => array(
            'name'          => __('AI Block Definitions', 'ai-block-creator'),
            'singular_name' => __('AI Block Definition', 'ai-block-creator'),
        ),
        'public'              => false,
        'publicly_queryable'  => false,
        'show_ui'             => false,
        'show_in_menu'        => false,
        'show_in_rest'        => false,
        'query_var'           => false,
        'rewrite'             => false,
        'capability_type'     => 'post',
        'has_archive'         => false,
        'hierarchical'        => false,
        'supports'            => array('title', 'custom-fields'),
    ));
}
add_action('init', __NAMESPACE__ . '\\register_cpt', 5);

/**
 * Dynamically registers all stored AI custom blocks with WordPress.
 */
function register_dynamic_blocks(): void
{
    // Register block category for AI blocks if not already present.
    add_filter('block_categories_all', function ($categories) {
        // Check if category already exists.
        foreach ($categories as $cat) {
            if ($cat['slug'] === 'ai-blocks') {
                return $categories;
            }
        }
        $categories[] = array(
            'slug'  => 'ai-blocks',
            'title' => __('AI Custom Blocks', 'ai-block-creator'),
            'icon'  => 'star-filled',
        );
        return $categories;
    });

    $posts = get_posts(array(
        'post_type'      => 'wp_block_def',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ));

    foreach ($posts as $post) {
        $raw_json = get_post_meta($post->ID, '_ai_block_definition', true);
        if (!$raw_json || !is_string($raw_json)) {
            continue;
        }

        $def = json_decode($raw_json, true);
        if (!is_array($def) || empty($def['name'])) {
            continue;
        }

        $block_name = $def['name'];
        if (!str_starts_with($block_name, 'ai-block/')) {
            $block_name = 'ai-block/' . $block_name;
        }

        $attributes_schema = array();
        if (!empty($def['attributes']) && is_array($def['attributes'])) {
            foreach ($def['attributes'] as $key => $attr) {
                $attributes_schema[$key] = array(
                    'type'    => $attr['type'] ?? 'string',
                    'default' => $attr['default'] ?? '',
                );
            }
        }

        register_block_type($block_name, array(
            'api_version'     => 3,
            'title'           => $def['title'] ?? 'AI Block',
            'category'        => 'ai-blocks',
            'icon'            => $def['icon'] ?? 'star-filled',
            'description'     => $def['description'] ?? '',
            'attributes'      => $attributes_schema,
            'supports'        => array(
                'html'   => false,
                'anchor' => true,
                'align'  => array('wide', 'full'),
            ),
            'render_callback' => array(AI_Block_Renderer::class, 'render'),
        ));
    }
}
add_action('init', __NAMESPACE__ . '\\register_dynamic_blocks', 10);

/**
 * Registers REST API controller.
 */
function register_rest_routes(): void
{
    $controller = new AI_Block_REST_Controller();
    $controller->register_routes();
}
add_action('rest_api_init', __NAMESPACE__ . '\\register_rest_routes');

/**
 * Enqueues assets for the Block Editor.
 */
function enqueue_editor_assets(): void
{
    $asset_file = PLUGIN_DIR . '/build/index.asset.php';
    $asset = file_exists($asset_file)
        ? require $asset_file
        : array(
            'dependencies' => array(
                'wp-blocks',
                'wp-element',
                'wp-components',
                'wp-block-editor',
                'wp-data',
                'wp-edit-post',
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

    if (file_exists(PLUGIN_DIR . '/build/index.css')) {
        wp_enqueue_style(
            'ai-block-creator-editor-styles',
            AI_BLOCK_CREATOR_URL . 'build/index.css',
            array('wp-components', 'wp-block-editor'),
            $asset['version']
        );
    }

    // Retrieve all saved blocks to pass to the editor.
    $posts = get_posts(array(
        'post_type'      => 'wp_block_def',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ));

    $saved_blocks = array();
    foreach ($posts as $post) {
        $meta = get_post_meta($post->ID, '_ai_block_definition', true);
        if ($meta) {
            $data = json_decode($meta, true);
            if (is_array($data)) {
                $data['id'] = $post->ID;
                $saved_blocks[] = $data;
            }
        }
    }

    wp_localize_script('ai-block-creator-editor', 'aiBlockCreatorSettings', array(
        'restUrl'     => esc_url_raw(rest_url('ai-block-creator/v1/')),
        'nonce'       => wp_create_nonce('wp_rest'),
        'savedBlocks' => $saved_blocks,
        'hasAiClient' => function_exists('wp_ai_client_prompt'),
    ));
}
add_action('enqueue_block_editor_assets', __NAMESPACE__ . '\\enqueue_editor_assets');

/**
 * Enqueues frontend styles for saved blocks.
 */
function enqueue_frontend_styles(): void
{
    $posts = get_posts(array(
        'post_type'      => 'wp_block_def',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ));

    $combined_css = '';
    foreach ($posts as $post) {
        $meta = get_post_meta($post->ID, '_ai_block_definition', true);
        if ($meta) {
            $data = json_decode($meta, true);
            if (is_array($data) && !empty($data['css'])) {
                $combined_css .= "\n/* Block: " . esc_attr($data['name'] ?? '') . " */\n" . $data['css'];
            }
        }
    }

    if (!empty($combined_css)) {
        wp_register_style('ai-blocks-frontend-styles', false);
        wp_enqueue_style('ai-blocks-frontend-styles');
        wp_add_inline_style('ai-blocks-frontend-styles', $combined_css);
    }
}
add_action('wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_frontend_styles');
