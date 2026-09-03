<?php
/**
 * Tests for AI_Block_Renderer::render_template().
 *
 * Covers the exact cases from plans/done/code-review-2026-09-03.md that
 * originally shipped broken (BUG-1, BUG-2, BUG-4) and were verified ad hoc
 * against a real wp-load.php bootstrap during that fix — this formalizes
 * that verification as a real, repeatable test suite instead.
 *
 * @package AI_Block_Creator
 */

declare(strict_types=1);

use AI_Block_Creator\AI_Block_Renderer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AI_Block_Creator\AI_Block_Renderer
 */
final class RendererTest extends TestCase
{
    public function test_empty_template_returns_empty_string(): void
    {
        $this->assertSame('', AI_Block_Renderer::render_template('', array()));
    }

    public function test_escapes_plain_variable_interpolation_as_html_text(): void
    {
        $html = AI_Block_Renderer::render_template(
            '<h3>{{title}}</h3>',
            array('title' => '<b>Hi</b> & bye')
        );

        $this->assertSame('<h3>&lt;b&gt;Hi&lt;/b&gt; &amp; bye</h3>', $html);
    }

    public function test_triple_brace_raw_output_has_no_stray_braces(): void
    {
        // BUG-1: the {{var}} pass previously ran before {{{raw}}}, consuming
        // the inner placeholder and leaving literal "{escaped}" behind.
        $html = AI_Block_Renderer::render_template(
            '<div>{{{html}}}</div>',
            array('html' => '<em>rich</em>')
        );

        $this->assertStringNotContainsString('{', $html);
        $this->assertStringNotContainsString('}', $html);
        $this->assertStringContainsString('<em>rich</em>', $html);
    }

    public function test_triple_brace_output_is_always_kses_filtered(): void
    {
        // render_template() runs {{{x}}} through wp_kses_post() unconditionally
        // -- not gated on the current user's capability, because the renderer
        // also runs for anonymous front-end visitors who have no capabilities
        // at all. (The separate, capability-gated sanitization of the whole
        // render_html template happens once, at save time, in
        // AI_Block_Store::sanitize_render_html() -- see BlockStoreTest.)
        $html = AI_Block_Renderer::render_template(
            '<div>{{{html}}}</div>',
            array('html' => '<script>alert(1)</script><b onclick="x()">bold</b>')
        );

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringContainsString('<b>bold</b>', $html);
    }

    public function test_boolean_conditionals(): void
    {
        $template = '{{#if isFeatured}}YES{{/if}}{{^if isFeatured}}NO{{/if}}';

        $this->assertSame('NO', AI_Block_Renderer::render_template($template, array('isFeatured' => false)));
        $this->assertSame('YES', AI_Block_Renderer::render_template($template, array('isFeatured' => true)));
    }

    public function test_nested_conditionals_resolve_correctly(): void
    {
        // BUG-2: a non-greedy regex previously paired the first {{#if}} with
        // the first {{/if}} regardless of nesting, breaking this case.
        $template = 'before{{#if a}}A{{#if b}}B{{/if}}C{{/if}}after';

        $this->assertSame(
            'beforeABCafter',
            AI_Block_Renderer::render_template($template, array('a' => true, 'b' => true))
        );
        $this->assertSame(
            'beforeACafter',
            AI_Block_Renderer::render_template($template, array('a' => true, 'b' => false))
        );
        $this->assertSame(
            'beforeafter',
            AI_Block_Renderer::render_template($template, array('a' => false, 'b' => true))
        );
    }

    public function test_list_repeater_from_newline_delimited_string(): void
    {
        $template = '<ul>{{#list items}}<li>{{item}}</li>{{/list}}</ul>';

        $html = AI_Block_Renderer::render_template($template, array('items' => "a\n<i>b</i>\n"));

        $this->assertSame('<ul><li>a</li><li>&lt;i&gt;b&lt;/i&gt;</li></ul>', $html);
    }

    public function test_list_repeater_from_array(): void
    {
        $template = '<ul>{{#list items}}<li>{{item}}</li>{{/list}}</ul>';

        $html = AI_Block_Renderer::render_template($template, array('items' => array('x', 'y')));

        $this->assertSame('<ul><li>x</li><li>y</li></ul>', $html);
    }

    public function test_list_body_still_resolves_other_outer_attributes(): void
    {
        // {{#list}} only special-cases {{item}}; it doesn't isolate its body
        // from the later top-level {{key}} substitution pass, so any other
        // real attribute name used inside a list item template still
        // resolves from the outer attributes. Verified to match the JS
        // renderer's behavior — see src/runtime/test/dynamic-block-factory.test.js.
        $template = '{{#list items}}<li>{{item}} {{title}}</li>{{/list}}';

        $html = AI_Block_Renderer::render_template($template, array(
            'items' => "a\nb",
            'title' => 'Widget',
        ));

        $this->assertSame('<li>a Widget</li><li>b Widget</li>', $html);
    }

    public function test_href_attribute_is_url_escaped_and_blocks_javascript_scheme(): void
    {
        // BUG-4 (server side was already correct; this locks it in).
        $html = AI_Block_Renderer::render_template(
            '<a href="{{url}}">x</a>',
            array('url' => 'javascript:alert(1)')
        );

        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_style_attribute_is_css_filtered_and_strips_expression(): void
    {
        $html = AI_Block_Renderer::render_template(
            '<div style="color:{{c}}">x</div>',
            array('c' => 'red;background:expression(alert(1))')
        );

        $this->assertStringNotContainsString('expression(', $html);
        $this->assertStringContainsString('color:red', $html);
    }

    public function test_style_attribute_preserves_custom_properties(): void
    {
        $html = AI_Block_Renderer::render_template(
            '<div style="--accent:{{c}};color:blue">x</div>',
            array('c' => '#4f46e5')
        );

        $this->assertStringContainsString('--accent:#4f46e5', $html);
    }

    public function test_booleans_stringify_as_one_and_zero(): void
    {
        $this->assertSame('1', AI_Block_Renderer::render_template('{{flag}}', array('flag' => true)));
        $this->assertSame('0', AI_Block_Renderer::render_template('{{flag}}', array('flag' => false)));
    }

    public function test_arrays_stringify_as_comma_separated_list(): void
    {
        $html = AI_Block_Renderer::render_template('{{items}}', array('items' => array('a', 'b')));
        $this->assertSame('a, b', $html);
    }

    public function test_interpolates_multiple_variables_within_same_style_attribute(): void
    {
        $html = AI_Block_Renderer::render_template(
            '<div style="color: {{color}}; background: {{bg}};">x</div>',
            array('color' => 'red', 'bg' => 'blue')
        );

        $this->assertSame('<div style="color: red; background: blue;">x</div>', $html);
    }

    public function test_interpolates_multiple_variables_within_url_attribute(): void
    {
        $html = AI_Block_Renderer::render_template(
            '<a href="https://example.com/{{path}}?plan={{plan}}">link</a>',
            array('path' => 'pricing', 'plan' => 'pro')
        );

        $this->assertSame('<a href="https://example.com/pricing?plan=pro">link</a>', $html);
    }

    public function test_missing_attribute_renders_empty_string_not_the_placeholder(): void
    {
        $this->assertSame('', AI_Block_Renderer::render_template('{{missing}}', array()));
    }
}
