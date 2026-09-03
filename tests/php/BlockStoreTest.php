<?php
/**
 * Tests for AI_Block_Store.
 *
 * Covers the validation/sanitization findings from
 * plans/code-review-2026-09-03.md (SEC-1, SEC-2, BUG-8, BUG-9, BUG-10,
 * BUG-11) that AI_Block_Store::normalize_and_validate() exists to close, and
 * a real save/get/delete round trip against the database. Every test that
 * creates a post deletes it in tearDown() -- there is no per-test
 * transaction rollback here (see tests/php/bootstrap.php), so leaving that
 * out would leak posts into the real database between runs.
 *
 * @package AI_Block_Creator
 */

declare(strict_types=1);

use AI_Block_Creator\AI_Block_Store;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AI_Block_Creator\AI_Block_Store
 */
final class BlockStoreTest extends TestCase
{
    /**
     * Post IDs created during a test, deleted in tearDown().
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

        remove_all_filters('user_has_cap');

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // normalize_and_validate() — pure validation, no database involved.
    // -----------------------------------------------------------------

    public function test_unknown_top_level_keys_are_dropped(): void
    {
        $def = AI_Block_Store::normalize_and_validate(array(
            'title'                  => 'My Block',
            'malicious_extra_field'  => '<script>alert(1)</script>',
            'another_unexpected_key' => array('anything' => 'here'),
        ));

        $this->assertArrayNotHasKey('malicious_extra_field', $def);
        $this->assertArrayNotHasKey('another_unexpected_key', $def);
    }

    public function test_name_is_always_prefixed_and_slugified(): void
    {
        $def = AI_Block_Store::normalize_and_validate(array(
            'title' => 'Fancy Title!',
            'name'  => 'ai-block/My Custom Block!!',
        ));

        $this->assertSame('ai-block/my-custom-block', $def['name']);
    }

    public function test_empty_name_and_title_still_produce_a_valid_slug(): void
    {
        // BUG-10: sanitize_title() on certain input (or nothing at all) can
        // produce an empty slug, which would be invalid as a block name.
        $def = AI_Block_Store::normalize_and_validate(array());

        $this->assertNotSame('', $def['name']);
        $this->assertStringStartsWith('ai-block/', $def['name']);
        $this->assertMatchesRegularExpression('#^ai-block/[a-z0-9-]+$#', $def['name']);
    }

    public function test_attribute_types_are_allowlisted_and_defaults_coerced(): void
    {
        $def = AI_Block_Store::normalize_and_validate(array(
            'title'      => 'Test',
            'attributes' => array(
                'goodBool'   => array('type' => 'boolean', 'default' => 'yes'),
                'goodNumber' => array('type' => 'number', 'default' => '42'),
                'badType'    => array('type' => 'not-a-real-type', 'default' => 'x'),
                'noType'     => array('default' => 'x'),
            ),
        ));

        $this->assertSame('boolean', $def['attributes']['goodBool']['type']);
        $this->assertSame(true, $def['attributes']['goodBool']['default']);

        $this->assertSame('number', $def['attributes']['goodNumber']['type']);
        $this->assertSame(42, $def['attributes']['goodNumber']['default']);

        // An unrecognized type falls back to "string" rather than being
        // passed through -- an invalid type would break register_block_type().
        $this->assertSame('string', $def['attributes']['badType']['type']);
        $this->assertSame('string', $def['attributes']['noType']['type']);
    }

    public function test_edit_fields_are_derived_from_attributes_when_missing(): void
    {
        $def = AI_Block_Store::normalize_and_validate(array(
            'title'      => 'Test',
            'attributes' => array(
                'highlight' => array('type' => 'boolean', 'default' => false),
                'count'     => array('type' => 'number', 'default' => 1),
                'label'     => array('type' => 'string', 'default' => ''),
            ),
        ));

        $by_name = array();
        foreach ($def['edit_fields'] as $field) {
            $by_name[$field['name']] = $field['type'];
        }

        $this->assertSame('toggle', $by_name['highlight']);
        $this->assertSame('number', $by_name['count']);
        $this->assertSame('text', $by_name['label']);
    }

    public function test_edit_fields_referencing_unknown_attributes_are_dropped(): void
    {
        $def = AI_Block_Store::normalize_and_validate(array(
            'title'       => 'Test',
            'attributes'  => array('real' => array('type' => 'string', 'default' => '')),
            'edit_fields' => array(
                array('name' => 'real', 'label' => 'Real', 'type' => 'text'),
                array('name' => 'doesNotExist', 'label' => 'Ghost', 'type' => 'text'),
            ),
        ));

        $names = array_column($def['edit_fields'], 'name');
        $this->assertContains('real', $names);
        $this->assertNotContains('doesNotExist', $names);
    }

    public function test_icon_is_reduced_to_a_safe_key(): void
    {
        $def = AI_Block_Store::normalize_and_validate(array(
            'title' => 'Test',
            'icon'  => '<script>alert(1)</script>',
        ));

        $this->assertSame('scriptalert1script', $def['icon']);
    }

    public function test_category_is_restricted_to_the_known_set(): void
    {
        $def = AI_Block_Store::normalize_and_validate(array(
            'title'    => 'Test',
            'category' => 'not-a-real-category',
        ));

        $this->assertSame('widgets', $def['category']);
    }

    public function test_render_html_is_kses_filtered_for_users_without_unfiltered_html(): void
    {
        $this->withoutUnfilteredHtml(function () {
            $def = AI_Block_Store::normalize_and_validate(array(
                'title'       => 'Test',
                'render_html' => '<div onclick="evil()"><script>alert(1)</script><b>bold</b></div>',
            ));

            $this->assertStringNotContainsString('<script', $def['render_html']);
            $this->assertStringNotContainsString('onclick', $def['render_html']);
            $this->assertStringContainsString('<b>bold</b>', $def['render_html']);
        });
    }

    public function test_render_html_style_and_class_attributes_survive_kses(): void
    {
        // Scoped inline styles/classes are core to how these blocks work
        // (see the AI_Block_Renderer style="--accent: {{x}}" convention), so
        // the kses allowlist explicitly extends core's "post" context to
        // permit them even for non-unfiltered_html savers.
        $this->withoutUnfilteredHtml(function () {
            $def = AI_Block_Store::normalize_and_validate(array(
                'title'       => 'Test',
                'render_html' => '<div class="my-block" style="--accent: #fff;">x</div>',
            ));

            $this->assertStringContainsString('class="my-block"', $def['render_html']);
            // wp_kses normalizes the style attribute's value (via
            // safecss_filter_attr internally), which drops the trailing
            // semicolon -- expected, safe behavior, not a bug.
            $this->assertStringContainsString('style="--accent: #fff"', $def['render_html']);
        });
    }

    public function test_render_html_is_passed_through_verbatim_for_unfiltered_html_users(): void
    {
        // Trusted authors (the default test user has this capability) may
        // save arbitrary markup, same trust boundary WordPress already
        // applies to raw HTML in post content.
        $raw = '<div><script>trusted(1)</script></div>';

        $def = AI_Block_Store::normalize_and_validate(array(
            'title'       => 'Test',
            'render_html' => $raw,
        ));

        $this->assertSame($raw, $def['render_html']);
    }

    public function test_css_strips_style_and_script_breakout_regardless_of_capability(): void
    {
        // Unlike render_html, the </style>/<script> strip in sanitize_css()
        // is unconditional -- there is never a legitimate reason for a CSS
        // string to contain markup, for any user.
        $def = AI_Block_Store::normalize_and_validate(array(
            'title' => 'Test',
            'css'   => '.foo{color:red} </style><script>alert(1)</script>',
        ));

        $this->assertStringNotContainsString('<script', $def['css']);
        $this->assertStringNotContainsString('</style', $def['css']);
    }

    public function test_css_strips_expression_and_import_for_non_unfiltered_html_users(): void
    {
        $this->withoutUnfilteredHtml(function () {
            $def = AI_Block_Store::normalize_and_validate(array(
                'title' => 'Test',
                'css'   => '.foo{width:expression(alert(1))} @import url(evil.css);',
            ));

            $this->assertStringNotContainsString('expression(', $def['css']);
            $this->assertStringNotContainsString('@import', $def['css']);
        });
    }

    // -----------------------------------------------------------------
    // save() / get() / delete() — real database round trip.
    // -----------------------------------------------------------------

    public function test_save_then_get_round_trips_the_definition(): void
    {
        $saved = AI_Block_Store::save(array(
            'title'       => 'PHPUnit Round Trip Block',
            'render_html' => '<div>{{title}}</div>',
        ));

        $this->assertIsArray($saved);
        $this->created_post_ids[] = (int) $saved['id'];

        $fetched = AI_Block_Store::get($saved['name']);

        $this->assertNotNull($fetched);
        $this->assertSame($saved['name'], $fetched['name']);
        $this->assertSame('PHPUnit Round Trip Block', $fetched['title']);
    }

    public function test_saving_twice_with_the_same_name_updates_rather_than_duplicates(): void
    {
        $first = AI_Block_Store::save(array(
            'name'  => 'ai-block/dupe-test',
            'title' => 'First Title',
        ));
        $this->created_post_ids[] = (int) $first['id'];

        $second = AI_Block_Store::save(array(
            'name'  => 'ai-block/dupe-test',
            'title' => 'Second Title',
        ));
        $this->created_post_ids[] = (int) $second['id'];

        $this->assertSame($first['id'], $second['id']);

        $fetched = AI_Block_Store::get('ai-block/dupe-test');
        $this->assertSame('Second Title', $fetched['title']);
    }

    public function test_delete_removes_the_block_definition(): void
    {
        $saved = AI_Block_Store::save(array('title' => 'To Be Deleted'));
        $post_id = (int) $saved['id'];

        $result = AI_Block_Store::delete($post_id);

        $this->assertTrue($result);
        $this->assertNull(AI_Block_Store::get($saved['name']));
    }

    public function test_delete_refuses_a_post_that_is_not_a_block_definition(): void
    {
        // SEC-1: DELETE /blocks/{id} previously force-deleted ANY post by ID
        // with no type check, letting any edit_posts user delete arbitrary
        // site content. AI_Block_Store::delete() is the fix.
        $unrelated_post_id = wp_insert_post(array(
            'post_title'  => 'An Ordinary Post, Not A Block Definition',
            'post_type'   => 'post',
            'post_status' => 'publish',
        ));
        $this->assertIsInt($unrelated_post_id);
        $this->assertGreaterThan(0, $unrelated_post_id);

        $result = AI_Block_Store::delete($unrelated_post_id);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertNotNull(get_post($unrelated_post_id), 'The unrelated post must survive.');

        wp_delete_post($unrelated_post_id, true);
    }

    public function test_delete_with_invalid_id_returns_an_error(): void
    {
        $result = AI_Block_Store::delete(0);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    /**
     * Runs $callback with `unfiltered_html` forced false for the current
     * user via the `user_has_cap` filter, restoring normal behavior
     * afterward regardless of how the callback exits.
     */
    private function withoutUnfilteredHtml(callable $callback): void
    {
        $filter = static function (array $allcaps): array {
            $allcaps['unfiltered_html'] = false;
            return $allcaps;
        };

        add_filter('user_has_cap', $filter);
        try {
            $callback();
        } finally {
            remove_filter('user_has_cap', $filter);
        }
    }
}
