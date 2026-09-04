<?php
/**
 * Tests for the kind-discriminated definitions introduced alongside the
 * two-stage planning pipeline: block styles and block variations.
 *
 * The invariant these lock in is that a definition's `kind` decides which
 * shape it must conform to, and that a definition of one kind can never
 * acquire another kind's fields (or overwrite another kind's stored post) by
 * accident — see includes/class-ai-block-store.php.
 *
 * @package AI_Block_Creator
 */

declare(strict_types=1);

use AI_Block_Creator\AI_Block_Store;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AI_Block_Creator\AI_Block_Store
 */
final class DefinitionKindsTest extends TestCase
{
    /**
     * Post IDs created during a test, removed in tearDown().
     *
     * @var int[]
     */
    private array $created_post_ids = array();

    protected function tearDown(): void
    {
        foreach ($this->created_post_ids as $post_id) {
            AI_Block_Store::delete($post_id);
        }
        $this->created_post_ids = array();

        parent::tearDown();
    }

    public function test_missing_kind_is_treated_as_a_custom_block(): void
    {
        // Every definition stored before kinds existed has no `kind` field.
        $normalized = AI_Block_Store::normalize_and_validate(
            array(
                'name'        => 'legacy-thing',
                'title'       => 'Legacy Thing',
                'render_html' => '<div>{{title}}</div>',
            )
        );

        $this->assertSame(AI_Block_Store::KIND_CUSTOM_BLOCK, $normalized['kind']);
        $this->assertSame('ai-block/legacy-thing', $normalized['name']);
        $this->assertArrayHasKey('render_html', $normalized);
    }

    public function test_unrecognized_kind_falls_back_to_custom_block(): void
    {
        $normalized = AI_Block_Store::normalize_and_validate(
            array(
                'kind'  => 'block_template',
                'title' => 'Not A Real Kind',
            )
        );

        $this->assertSame(AI_Block_Store::KIND_CUSTOM_BLOCK, $normalized['kind']);
    }

    public function test_block_style_normalizes_to_a_css_only_definition(): void
    {
        $normalized = AI_Block_Store::normalize_and_validate(
            array(
                'kind'         => 'block_style',
                'name'         => 'Gold Pull-Quote',
                'label'        => 'Gold Pull-Quote',
                'target_block' => 'core/quote',
                'css'          => '.is-style-ai-gold-pull-quote { color: gold; }',
                // A style has no template, no attributes and no inspector
                // controls; anything the model emits anyway must be dropped
                // rather than carried into storage.
                'render_html'  => '<div onclick="alert(1)">nope</div>',
                'attributes'   => array('title' => array('type' => 'string')),
                'edit_fields'  => array(array('name' => 'title', 'type' => 'text')),
            )
        );

        $this->assertSame(AI_Block_Store::KIND_BLOCK_STYLE, $normalized['kind']);
        $this->assertSame('ai-gold-pull-quote', $normalized['name']);
        $this->assertSame('core/quote', $normalized['target_block']);
        $this->assertStringContainsString('color: gold', $normalized['css']);

        $this->assertArrayNotHasKey('render_html', $normalized);
        $this->assertArrayNotHasKey('attributes', $normalized);
        $this->assertArrayNotHasKey('edit_fields', $normalized);
    }

    public function test_style_name_is_always_ai_prefixed_once(): void
    {
        $prefixed = AI_Block_Store::normalize_and_validate(
            array('kind' => 'block_style', 'name' => 'ai-already-prefixed', 'target_block' => 'core/quote')
        );
        $bare = AI_Block_Store::normalize_and_validate(
            array('kind' => 'block_style', 'name' => 'bare', 'target_block' => 'core/quote')
        );

        $this->assertSame('ai-already-prefixed', $prefixed['name']);
        $this->assertSame('ai-bare', $bare['name']);
    }

    public function test_style_css_is_sanitized_like_a_custom_block_s(): void
    {
        $normalized = AI_Block_Store::normalize_and_validate(
            array(
                'kind'         => 'block_style',
                'name'         => 'sneaky',
                'target_block' => 'core/quote',
                'css'          => '</style><script>alert(1)</script>.is-style-ai-sneaky{color:red}',
            )
        );

        $this->assertStringNotContainsString('<script', $normalized['css']);
        $this->assertStringNotContainsString('</style', $normalized['css']);
        $this->assertStringContainsString('color:red', $normalized['css']);
    }

    /**
     * @dataProvider provide_invalid_target_blocks
     */
    public function test_invalid_target_block_falls_back_to_core_group(string $target): void
    {
        $normalized = AI_Block_Store::normalize_and_validate(
            array('kind' => 'block_style', 'name' => 'x', 'target_block' => $target)
        );

        $this->assertSame('core/group', $normalized['target_block']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provide_invalid_target_blocks(): array
    {
        return array(
            'no namespace'      => array('quote'),
            'empty'             => array(''),
            'path traversal'    => array('../../evil'),
            'not registered'    => array('vendor/definitely-not-a-real-block'),
            'markup'            => array('<script>/x'),
        );
    }

    public function test_variation_attributes_are_values_not_a_schema(): void
    {
        $normalized = AI_Block_Store::normalize_and_validate(
            array(
                'kind'              => 'block_variation',
                'name'              => 'two-col-feature',
                'title'             => 'Two Column Feature',
                'target_block'      => 'core/columns',
                'attributes'        => array(
                    'align'             => 'wide',
                    'isStackedOnMobile' => false,
                    'verticalAlignment' => 'center',
                    'style'             => array('color' => array('background' => '#111')),
                    // core/columns declares no such attribute, so this is
                    // silently inert if kept -- see filter_to_block_attributes().
                    'notARealAttribute' => 'nope',
                ),
                'inner_block_names' => array('core/column', 'core/column'),
            )
        );

        $this->assertSame(AI_Block_Store::KIND_BLOCK_VARIATION, $normalized['kind']);
        $this->assertSame('ai-two-col-feature', $normalized['name']);
        $this->assertSame('core/columns', $normalized['target_block']);

        // Concrete values survive as themselves, with their types intact --
        // running these through the custom-block attribute *schema* sanitizer
        // would have discarded every one of them.
        $this->assertSame('wide', $normalized['attributes']['align']);
        $this->assertFalse($normalized['attributes']['isStackedOnMobile']);
        $this->assertSame('center', $normalized['attributes']['verticalAlignment']);
        $this->assertSame('#111', $normalized['attributes']['style']['color']['background']);

        // Attributes the target block doesn't declare are dropped: they read
        // as configuration and do nothing.
        $this->assertArrayNotHasKey('notARealAttribute', $normalized['attributes']);

        $this->assertSame(array('core/column', 'core/column'), $normalized['inner_block_names']);
    }

    public function test_variation_attributes_survive_when_the_target_block_is_unknown(): void
    {
        // sanitize_target_block() falls back to core/group for an unrecognized
        // target. Attribute filtering must not then throw away everything the
        // author asked for on the strength of a fallback nobody chose.
        $normalized = AI_Block_Store::normalize_and_validate(array(
            'kind'         => 'block_variation',
            'name'         => 'unknown-target',
            'target_block' => 'core/group',
            'attributes'   => array('align' => 'wide'),
        ));

        $this->assertSame('wide', $normalized['attributes']['align']);
    }

    public function test_variation_drops_unregistered_inner_blocks_rather_than_substituting_them(): void
    {
        $normalized = AI_Block_Store::normalize_and_validate(
            array(
                'kind'              => 'block_variation',
                'name'              => 'mixed',
                'target_block'      => 'core/columns',
                'inner_block_names' => array('core/column', 'vendor/not-real', 'core/paragraph'),
            )
        );

        $this->assertSame(
            array('core/column', 'core/paragraph'),
            $normalized['inner_block_names'],
            'An unrecognized inner block should be dropped, not silently turned into a Group.'
        );
    }

    public function test_variation_inner_block_list_is_length_capped(): void
    {
        $normalized = AI_Block_Store::normalize_and_validate(
            array(
                'kind'              => 'block_variation',
                'name'              => 'long',
                'target_block'      => 'core/group',
                'inner_block_names' => array_fill(0, 50, 'core/paragraph'),
            )
        );

        $this->assertCount(10, $normalized['inner_block_names']);
    }

    public function test_a_style_and_a_custom_block_with_the_same_slug_are_stored_separately(): void
    {
        $block = AI_Block_Store::save(
            array(
                'name'        => 'callout',
                'title'       => 'Callout Block',
                'render_html' => '<div>Callout</div>',
            )
        );
        $this->assertNotWPError($block);
        $this->created_post_ids[] = $block['id'];

        $style = AI_Block_Store::save(
            array(
                'kind'         => 'block_style',
                'name'         => 'callout',
                'label'        => 'Callout Style',
                'target_block' => 'core/group',
                'css'          => '.is-style-ai-callout { border: 1px solid; }',
            )
        );
        $this->assertNotWPError($style);
        $this->created_post_ids[] = $style['id'];

        $this->assertNotSame(
            $block['id'],
            $style['id'],
            'Saving a style must not overwrite the custom block that happens to share its slug.'
        );

        // And the custom block is still intact and still a custom block.
        $reloaded = AI_Block_Store::get('callout');
        $this->assertNotNull($reloaded);
        $this->assertSame(AI_Block_Store::KIND_CUSTOM_BLOCK, $reloaded['kind']);
        $this->assertSame('ai-block/callout', $reloaded['name']);
    }

    public function test_saving_the_same_style_twice_updates_rather_than_duplicates(): void
    {
        $first = AI_Block_Store::save(
            array(
                'kind'         => 'block_style',
                'name'         => 'repeatable',
                'label'        => 'First Label',
                'target_block' => 'core/quote',
                'css'          => '.is-style-ai-repeatable { color: red; }',
            )
        );
        $this->assertNotWPError($first);
        $this->created_post_ids[] = $first['id'];

        $second = AI_Block_Store::save(
            array(
                'kind'         => 'block_style',
                'name'         => 'repeatable',
                'label'        => 'Second Label',
                'target_block' => 'core/quote',
                'css'          => '.is-style-ai-repeatable { color: blue; }',
            )
        );
        $this->assertNotWPError($second);

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame('Second Label', $second['label']);
    }

    public function test_block_pattern_round_trips_valid_block_markup(): void
    {
        $markup = '<!-- wp:group --><div class="wp-block-group">'
            . '<!-- wp:heading --><h2>Hello</h2><!-- /wp:heading -->'
            . '<!-- wp:paragraph --><p>Body copy.</p><!-- /wp:paragraph -->'
            . '</div><!-- /wp:group -->';

        $normalized = AI_Block_Store::normalize_and_validate(array(
            'kind'           => 'block_pattern',
            'name'           => 'hero-with-cta',
            'title'          => 'Hero With CTA',
            'keywords'       => array('hero', 'banner'),
            'viewport_width' => 1400,
            'content'        => $markup,
        ));

        $this->assertSame(AI_Block_Store::KIND_BLOCK_PATTERN, $normalized['kind']);
        $this->assertSame('ai-hero-with-cta', $normalized['name']);
        $this->assertSame(array('hero', 'banner'), $normalized['keywords']);
        $this->assertSame(1400, $normalized['viewport_width']);

        // Delimiters must survive: they are HTML comments, so any string-level
        // kses pass over the raw markup would have destroyed the pattern.
        $this->assertStringContainsString('<!-- wp:group -->', $normalized['content']);
        $this->assertStringContainsString('<!-- wp:heading -->', $normalized['content']);
        $this->assertStringContainsString('<h2>Hello</h2>', $normalized['content']);
        $this->assertStringContainsString('<!-- /wp:group -->', $normalized['content']);

        // And it must still parse back into the same shape.
        $reparsed = parse_blocks($normalized['content']);
        $this->assertSame('core/group', $reparsed[0]['blockName']);
        $this->assertCount(2, $reparsed[0]['innerBlocks']);
    }

    public function test_pattern_drops_blocks_this_site_does_not_have(): void
    {
        // A reference to an unregistered block renders as a broken-block
        // warning in the editor, so it is removed rather than preserved.
        $markup = '<!-- wp:group --><div class="wp-block-group">'
            . '<!-- wp:heading --><h2>Kept</h2><!-- /wp:heading -->'
            . '<!-- wp:vendor/not-real --><p>Dropped</p><!-- /wp:vendor/not-real -->'
            . '<!-- wp:paragraph --><p>Also kept</p><!-- /wp:paragraph -->'
            . '</div><!-- /wp:group -->';

        $normalized = AI_Block_Store::normalize_and_validate(array(
            'kind'    => 'block_pattern',
            'name'    => 'mixed-blocks',
            'content' => $markup,
        ));

        $this->assertStringNotContainsString('vendor/not-real', $normalized['content']);
        $this->assertStringNotContainsString('Dropped', $normalized['content']);

        // The surviving siblings must still be intact and correctly paired --
        // dropping an inner block also means dropping its innerContent
        // placeholder, or serialization pairs every later block with the
        // wrong slot.
        $reparsed = parse_blocks($normalized['content']);
        $inner    = $reparsed[0]['innerBlocks'];
        $this->assertCount(2, $inner);
        $this->assertSame('core/heading', $inner[0]['blockName']);
        $this->assertSame('core/paragraph', $inner[1]['blockName']);
        $this->assertStringContainsString('Kept', $inner[0]['innerHTML']);
        $this->assertStringContainsString('Also kept', $inner[1]['innerHTML']);
    }

    public function test_pattern_markup_is_sanitized_for_a_user_without_unfiltered_html(): void
    {
        // A pattern's markup lands in post content, so it sits behind the same
        // trust boundary WordPress already applies there.
        $deny_unfiltered_html = static function (array $allcaps): array {
            $allcaps['unfiltered_html'] = false;
            return $allcaps;
        };
        add_filter('user_has_cap', $deny_unfiltered_html);

        try {
            $normalized = AI_Block_Store::normalize_and_validate(array(
                'kind'    => 'block_pattern',
                'name'    => 'xss-attempt',
                'content' => '<!-- wp:paragraph --><p onclick="alert(1)">Hi</p>'
                    . '<script>alert(2)</script><!-- /wp:paragraph -->',
            ));
        } finally {
            remove_filter('user_has_cap', $deny_unfiltered_html);
        }

        $this->assertStringNotContainsString('<script', $normalized['content']);
        $this->assertStringNotContainsString('onclick', $normalized['content']);
        $this->assertStringContainsString('Hi', $normalized['content']);
    }

    public function test_pattern_viewport_width_is_clamped(): void
    {
        foreach (array(0 => 320, 50 => 320, 99999 => 2400, 'nonsense' => 1200) as $input => $expected) {
            $normalized = AI_Block_Store::normalize_and_validate(array(
                'kind'           => 'block_pattern',
                'name'           => 'clamp',
                'viewport_width' => $input,
            ));

            $this->assertSame($expected, $normalized['viewport_width']);
        }
    }

    public function test_pattern_carries_none_of_the_other_kinds_fields(): void
    {
        $normalized = AI_Block_Store::normalize_and_validate(array(
            'kind'         => 'block_pattern',
            'name'         => 'lean',
            'content'      => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
            // None of these mean anything for a pattern.
            'render_html'  => '<div>nope</div>',
            'css'          => '.nope {}',
            'target_block' => 'core/quote',
            'attributes'   => array('title' => array('type' => 'string')),
        ));

        $this->assertArrayNotHasKey('render_html', $normalized);
        $this->assertArrayNotHasKey('css', $normalized);
        $this->assertArrayNotHasKey('target_block', $normalized);
        $this->assertArrayNotHasKey('attributes', $normalized);
    }

    public function test_a_pattern_and_a_style_with_the_same_slug_are_stored_separately(): void
    {
        $style = AI_Block_Store::save(array(
            'kind'         => 'block_style',
            'name'         => 'shared-slug',
            'label'        => 'Shared Slug Style',
            'target_block' => 'core/quote',
        ));
        $this->assertNotWPError($style);
        $this->created_post_ids[] = $style['id'];

        $pattern = AI_Block_Store::save(array(
            'kind'    => 'block_pattern',
            'name'    => 'shared-slug',
            'title'   => 'Shared Slug Pattern',
            'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
        ));
        $this->assertNotWPError($pattern);
        $this->created_post_ids[] = $pattern['id'];

        $this->assertNotSame($style['id'], $pattern['id']);
    }

    public function test_by_kind_partitions_the_library(): void
    {
        $block = AI_Block_Store::save(
            array('name' => 'kind-test-block', 'title' => 'Kind Test Block', 'render_html' => '<p>x</p>')
        );
        $this->assertNotWPError($block);
        $this->created_post_ids[] = $block['id'];

        $style = AI_Block_Store::save(
            array(
                'kind'         => 'block_style',
                'name'         => 'kind-test-style',
                'label'        => 'Kind Test Style',
                'target_block' => 'core/quote',
            )
        );
        $this->assertNotWPError($style);
        $this->created_post_ids[] = $style['id'];

        $block_names = wp_list_pluck(AI_Block_Store::by_kind(AI_Block_Store::KIND_CUSTOM_BLOCK), 'name');
        $style_names = wp_list_pluck(AI_Block_Store::by_kind(AI_Block_Store::KIND_BLOCK_STYLE), 'name');

        $this->assertContains('ai-block/kind-test-block', $block_names);
        $this->assertNotContains('ai-block/kind-test-block', $style_names);

        $this->assertContains('ai-kind-test-style', $style_names);
        $this->assertNotContains('ai-kind-test-style', $block_names);
    }

    /**
     * Asserts a value is not a WP_Error, surfacing the error message when it is.
     *
     * @param mixed $value Value under test.
     */
    private function assertNotWPError($value): void
    {
        if (is_wp_error($value)) {
            $this->fail('Unexpected WP_Error: ' . $value->get_error_message());
        }
        $this->assertIsArray($value);
    }
}
