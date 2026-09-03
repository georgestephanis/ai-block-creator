<?php
/**
 * AI Block Renderer.
 *
 * @package AI_Block_Creator
 */

declare(strict_types=1);

namespace AI_Block_Creator;

if (!defined('ABSPATH')) {
    exit;
}

class AI_Block_Renderer
{
    /**
     * Set of rendered block CSS handles to avoid duplicate style tags.
     *
     * @var array<string, bool>
     */
    private static array $rendered_styles = array();

    /**
     * Renders a dynamic AI block.
     *
     * @param array    $attributes Block attributes.
     * @param string   $content    Block inner content.
     * @param \WP_Block $block      Block instance.
     * @return string Rendered HTML.
     */
    public static function render(array $attributes, string $content, \WP_Block $block): string
    {
        $block_name = $block->name; // e.g. ai-block/pricing-table
        $block_def  = self::get_block_definition($block_name);

        if (!$block_def) {
            return '<div class="ai-block-error">' . esc_html__('Block definition not found.', 'ai-block-creator') . '</div>';
        }

        $template = $block_def['render_html'] ?? '';
        $css      = $block_def['css'] ?? '';
        $block_id = 'ai-block-' . sanitize_title(str_replace('ai-block/', '', $block_name));

        // Merge defaults.
        $defaults = array();
        if (!empty($block_def['attributes']) && is_array($block_def['attributes'])) {
            foreach ($block_def['attributes'] as $key => $attr_config) {
                $defaults[$key] = $attr_config['default'] ?? '';
            }
        }
        $merged_attrs = array_merge($defaults, $attributes);

        // Get WordPress block wrapper attributes.
        $wrapper_attributes = get_block_wrapper_attributes(array(
            'class' => $block_id . ' ai-custom-block',
        ));

        $rendered_html = self::render_template($template, $merged_attrs, $wrapper_attributes);

        // Include scoped CSS if not already output.
        $style_output = '';
        if (!empty($css) && empty(self::$rendered_styles[$block_name])) {
            self::$rendered_styles[$block_name] = true;
            $style_output = '<style id="' . esc_attr($block_id) . '-css">' . wp_strip_all_tags($css) . '</style>';
        }

        return $style_output . $rendered_html;
    }

    /**
     * Interpolates attributes into HTML template safely.
     *
     * @param string $template           HTML template with mustache-style tags.
     * @param array  $attributes         Attribute values.
     * @param string $wrapper_attributes WordPress get_block_wrapper_attributes() string.
     * @return string
     */
    public static function render_template(string $template, array $attributes, string $wrapper_attributes = ''): string
    {
        if (empty($template)) {
            return '';
        }

        // Replace wrapper attributes placeholder if present, or wrap if not.
        if (strpos($template, '{{wrapper_attributes}}') !== false) {
            $template = str_replace('{{wrapper_attributes}}', $wrapper_attributes, $template);
        }

        // Process conditional blocks: {{#if isFeatured}}...{{/if}} or {{^if isFeatured}}...{{/if}}
        $template = preg_replace_callback(
            '/\{\{#if\s+([a-zA-Z0-9_-]+)\}\}(.*?)\{\{\/if\}\}/s',
            function ($matches) use ($attributes) {
                $var = $matches[1];
                $inner = $matches[2];
                return !empty($attributes[$var]) ? $inner : '';
            },
            $template
        );

        $template = preg_replace_callback(
            '/\{\{\^if\s+([a-zA-Z0-9_-]+)\}\}(.*?)\{\{\/if\}\}/s',
            function ($matches) use ($attributes) {
                $var = $matches[1];
                $inner = $matches[2];
                return empty($attributes[$var]) ? $inner : '';
            },
            $template
        );

        // Process list repeaters: {{#list features}}<li>{{item}}</li>{{/list}}
        $template = preg_replace_callback(
            '/\{\{#list\s+([a-zA-Z0-9_-]+)\}\}(.*?)\{\{\/list\}\}/s',
            function ($matches) use ($attributes) {
                $var = $matches[1];
                $item_template = $matches[2];
                $val = $attributes[$var] ?? '';

                $items = array();
                if (is_array($val)) {
                    $items = $val;
                } elseif (is_string($val)) {
                    $items = array_filter(array_map('trim', explode("\n", $val)));
                }

                $output = '';
                foreach ($items as $item) {
                    $item_str = is_array($item) ? json_encode($item) : (string) $item;
                    $output .= str_replace('{{item}}', esc_html($item_str), $item_template);
                }
                return $output;
            },
            $template
        );

        // Replace regular variables: {{attributeName}}
        $rendered = preg_replace_callback(
            '/\{\{([a-zA-Z0-9_-]+)\}\}/',
            function ($matches) use ($attributes) {
                $key = $matches[1];
                if (!isset($attributes[$key])) {
                    return '';
                }

                $val = $attributes[$key];
                if (is_bool($val)) {
                    return $val ? '1' : '0';
                }
                if (is_array($val)) {
                    return esc_html(implode(', ', $val));
                }

                return esc_html((string) $val);
            },
            $template
        );

        // Raw variables: {{{attributeName}}} for HTML safe content if explicitly used.
        $rendered = preg_replace_callback(
            '/\{\{\{([a-zA-Z0-9_-]+)\}\}\}/',
            function ($matches) use ($attributes) {
                $key = $matches[1];
                if (!isset($attributes[$key])) {
                    return '';
                }
                return wp_kses_post((string) $attributes[$key]);
            },
            $rendered
        );

        return $rendered;
    }

    /**
     * Gets a block definition by block name.
     *
     * @param string $block_name Block slug (e.g. ai-block/pricing-table).
     * @return array|null
     */
    public static function get_block_definition(string $block_name): ?array
    {
        $slug = str_replace('ai-block/', '', $block_name);
        $posts = get_posts(array(
            'post_type'      => 'wp_block_def',
            'name'           => $slug,
            'posts_per_page' => 1,
            'post_status'    => 'publish',
        ));

        if (empty($posts)) {
            return null;
        }

        $raw_json = get_post_meta($posts[0]->ID, '_ai_block_definition', true);
        if (!$raw_json || !is_string($raw_json)) {
            return null;
        }

        $def = json_decode($raw_json, true);
        if (is_array($def)) {
            $def['id'] = $posts[0]->ID;
            return $def;
        }

        return null;
    }
}
