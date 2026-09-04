<?php
/**
 * Integration tests for how each definition kind actually reaches WordPress.
 *
 * DefinitionKindsTest covers validation and storage; this covers the other
 * end — that a stored style really lands in WP_Block_Styles_Registry, that a
 * stored variation really comes back out of the target block type's
 * get_variations(), and that neither is mistakenly registered as a block type
 * of its own. Those are three separate registration APIs, and the whole point
 * of the planning stage is choosing between them, so a definition routed to
 * the wrong one is the failure mode worth guarding.
 *
 * @package AI_Block_Creator
 */

declare(strict_types=1);

use AI_Block_Creator\AI_Block_Store;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AI_Block_Creator\register_ai_block_styles
 * @covers \AI_Block_Creator\filter_ai_block_variations
 * @covers \AI_Block_Creator\register_ai_block_patterns
 * @covers \AI_Block_Creator\register_dynamic_blocks
 */
final class KindRegistrationTest extends TestCase
{
    /**
     * @var int[]
     */
    private array $created_post_ids = array();

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('AI_Block_Creator\\register_ai_block_styles')) {
            $this->markTestSkipped('The plugin bootstrap is not loaded in this WordPress install.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->created_post_ids as $post_id) {
            AI_Block_Store::delete($post_id);
        }
        $this->created_post_ids = array();

        parent::tearDown();
    }

    public function test_a_saved_style_is_registered_against_its_target_block(): void
    {
        $style = AI_Block_Store::save(array(
            'kind'         => 'block_style',
            'name'         => 'registration-test-style',
            'label'        => 'Registration Test Style',
            'target_block' => 'core/quote',
            'css'          => '.is-style-ai-registration-test-style { color: gold; }',
        ));
        $this->assertIsArray($style);
        $this->created_post_ids[] = $style['id'];

        \AI_Block_Creator\register_ai_block_styles();

        $registered = WP_Block_Styles_Registry::get_instance()
            ->get_registered_styles_for_block('core/quote');

        $this->assertArrayHasKey('ai-registration-test-style', $registered);
        $this->assertSame(
            'Registration Test Style',
            $registered['ai-registration-test-style']['label']
        );
        $this->assertStringContainsString(
            'color: gold',
            $registered['ai-registration-test-style']['inline_style']
        );

        WP_Block_Styles_Registry::get_instance()
            ->unregister('core/quote', 'ai-registration-test-style');
    }

    public function test_a_style_is_not_also_registered_as_a_block_type(): void
    {
        $style = AI_Block_Store::save(array(
            'kind'         => 'block_style',
            'name'         => 'not-a-block-type',
            'label'        => 'Not A Block Type',
            'target_block' => 'core/quote',
        ));
        $this->assertIsArray($style);
        $this->created_post_ids[] = $style['id'];

        \AI_Block_Creator\register_dynamic_blocks();

        $registry = WP_Block_Type_Registry::get_instance();
        $this->assertFalse($registry->is_registered('ai-block/ai-not-a-block-type'));
        $this->assertFalse($registry->is_registered('ai-block/not-a-block-type'));

        WP_Block_Styles_Registry::get_instance()
            ->unregister('core/quote', 'ai-not-a-block-type');
    }

    public function test_a_saved_variation_is_appended_to_its_target_block_type(): void
    {
        $variation = AI_Block_Store::save(array(
            'kind'              => 'block_variation',
            'name'              => 'registration-test-variation',
            'title'             => 'Registration Test Variation',
            'description'       => 'Two columns, preset wide.',
            'target_block'      => 'core/columns',
            'attributes'        => array('align' => 'wide'),
            'inner_block_names' => array('core/column', 'core/column'),
        ));
        $this->assertIsArray($variation);
        $this->created_post_ids[] = $variation['id'];

        $block_type = WP_Block_Type_Registry::get_instance()->get_registered('core/columns');
        $this->assertNotNull($block_type);

        $variations = $block_type->get_variations();
        $names      = wp_list_pluck($variations, 'name');

        $this->assertContains('ai-registration-test-variation', $names);

        $found = null;
        foreach ($variations as $candidate) {
            if ('ai-registration-test-variation' === ($candidate['name'] ?? '')) {
                $found = $candidate;
                break;
            }
        }

        $this->assertIsArray($found);
        $this->assertSame('Registration Test Variation', $found['title']);
        $this->assertSame('wide', $found['attributes']['align']);
        // A flat list of names is stored; Gutenberg's innerBlocks template
        // format is a list of [ name, attributes, innerBlocks ] tuples.
        $this->assertSame(
            array(array('core/column'), array('core/column')),
            $found['innerBlocks']
        );
    }

    public function test_a_variation_saved_after_the_first_lookup_still_appears(): void
    {
        // variations_by_target_block() memoizes its grouping per request, so
        // this is the invalidation guard: a variation saved after something
        // already asked for variations must not be hidden behind a stale memo.
        $block_type = WP_Block_Type_Registry::get_instance()->get_registered('core/columns');
        $this->assertNotNull($block_type);

        // Prime the memo.
        $block_type->get_variations();

        $variation = AI_Block_Store::save(array(
            'kind'         => 'block_variation',
            'name'         => 'saved-after-lookup',
            'title'        => 'Saved After Lookup',
            'target_block' => 'core/columns',
        ));
        $this->assertIsArray($variation);
        $this->created_post_ids[] = $variation['id'];

        $this->assertContains(
            'ai-saved-after-lookup',
            wp_list_pluck($block_type->get_variations(), 'name')
        );
    }

    public function test_a_variation_only_attaches_to_its_own_target_block(): void
    {
        $variation = AI_Block_Store::save(array(
            'kind'         => 'block_variation',
            'name'         => 'columns-only-variation',
            'title'        => 'Columns Only Variation',
            'target_block' => 'core/columns',
        ));
        $this->assertIsArray($variation);
        $this->created_post_ids[] = $variation['id'];

        $other = WP_Block_Type_Registry::get_instance()->get_registered('core/quote');
        $this->assertNotNull($other);

        $this->assertNotContains(
            'ai-columns-only-variation',
            wp_list_pluck($other->get_variations(), 'name')
        );
    }

    public function test_a_saved_pattern_is_registered_with_wordpress(): void
    {
        $pattern = AI_Block_Store::save(array(
            'kind'        => 'block_pattern',
            'name'        => 'registration-test-pattern',
            'title'       => 'Registration Test Pattern',
            'description' => 'A heading and a paragraph.',
            'keywords'    => array('hero'),
            'content'     => '<!-- wp:heading --><h2>Hi</h2><!-- /wp:heading -->',
        ));
        $this->assertIsArray($pattern);
        $this->created_post_ids[] = $pattern['id'];

        \AI_Block_Creator\register_ai_block_patterns();

        $registry     = WP_Block_Patterns_Registry::get_instance();
        $pattern_name = AI_Block_Store::PATTERN_NAMESPACE . '/ai-registration-test-pattern';

        $this->assertTrue($registry->is_registered($pattern_name));

        $registered = $registry->get_registered($pattern_name);
        $this->assertSame('Registration Test Pattern', $registered['title']);
        $this->assertStringContainsString('<!-- wp:heading -->', $registered['content']);
        $this->assertContains(AI_Block_Store::PATTERN_NAMESPACE, $registered['categories']);

        $registry->unregister($pattern_name);
    }

    public function test_an_empty_pattern_is_not_registered(): void
    {
        // A contentless pattern would show up in the inserter as a blank,
        // broken-looking entry, so it is skipped rather than advertised.
        $pattern = AI_Block_Store::save(array(
            'kind'    => 'block_pattern',
            'name'    => 'empty-pattern',
            'title'   => 'Empty Pattern',
            'content' => '',
        ));
        $this->assertIsArray($pattern);
        $this->created_post_ids[] = $pattern['id'];

        \AI_Block_Creator\register_ai_block_patterns();

        $this->assertFalse(
            WP_Block_Patterns_Registry::get_instance()->is_registered(
                AI_Block_Store::PATTERN_NAMESPACE . '/ai-empty-pattern'
            )
        );
    }

    public function test_a_pattern_is_not_also_registered_as_a_block_type_or_style(): void
    {
        $pattern = AI_Block_Store::save(array(
            'kind'    => 'block_pattern',
            'name'    => 'pattern-not-a-block',
            'title'   => 'Pattern Not A Block',
            'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
        ));
        $this->assertIsArray($pattern);
        $this->created_post_ids[] = $pattern['id'];

        \AI_Block_Creator\register_dynamic_blocks();
        \AI_Block_Creator\register_ai_block_styles();

        $this->assertFalse(
            WP_Block_Type_Registry::get_instance()->is_registered('ai-block/ai-pattern-not-a-block')
        );
        $this->assertArrayNotHasKey(
            'ai-pattern-not-a-block',
            WP_Block_Styles_Registry::get_instance()->get_registered_styles_for_block('core/group')
        );

        WP_Block_Patterns_Registry::get_instance()->unregister(
            AI_Block_Store::PATTERN_NAMESPACE . '/ai-pattern-not-a-block'
        );
    }

    public function test_variation_css_is_registered_as_an_enqueueable_handle(): void
    {
        // A variation's CSS has to be attached to the *target* block, since
        // that is the block that will actually be on the page. Without this,
        // the CSS is stored, looks right in the preview, and never loads.
        $variation = AI_Block_Store::save(array(
            'kind'         => 'block_variation',
            'name'         => 'css-handle-test',
            'title'        => 'CSS Handle Test',
            'target_block' => 'core/quote',
            'css'          => '.ai-variation-css-handle-test { color: rebeccapurple; }',
        ));
        $this->assertIsArray($variation);
        $this->created_post_ids[] = $variation['id'];

        \AI_Block_Creator\register_ai_variation_styles();

        $handle = 'ai-block-style-variation-ai-css-handle-test';
        $this->assertTrue(wp_style_is($handle, 'registered'));

        $inline = wp_styles()->get_data($handle, 'after');
        $this->assertNotEmpty($inline);
        $this->assertStringContainsString('rebeccapurple', implode('', (array) $inline));
    }

    public function test_a_custom_block_is_still_registered_as_a_block_type(): void
    {
        $block = AI_Block_Store::save(array(
            'name'        => 'registration-test-block',
            'title'       => 'Registration Test Block',
            'render_html' => '<div>{{title}}</div>',
            'attributes'  => array('title' => array('type' => 'string', 'default' => 'Hi')),
        ));
        $this->assertIsArray($block);
        $this->created_post_ids[] = $block['id'];

        \AI_Block_Creator\register_dynamic_blocks();

        $registry = WP_Block_Type_Registry::get_instance();
        $this->assertTrue($registry->is_registered('ai-block/registration-test-block'));

        $registry->unregister('ai-block/registration-test-block');
    }
}
