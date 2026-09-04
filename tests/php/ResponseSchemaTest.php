<?php
/**
 * Tests for the JSON Schemas sent to the model with each request.
 *
 * These schemas do two jobs: they constrain providers that support structured
 * output, and they are what schema_problems() checks a response against to
 * decide whether a repair turn is needed. Both jobs make them a contract with
 * AI_Block_Store's normalizers — a schema that requires a field the normalizer
 * ignores would send every response for a pointless repair, and a schema that
 * permits a shape the normalizer discards would let content vanish silently.
 * These tests pin the two together.
 *
 * The methods under test are private (they are prompt-construction details,
 * not API), so they are reached by reflection rather than by widening the
 * class's surface for the benefit of its tests.
 *
 * @package AI_Block_Creator
 */

declare(strict_types=1);

use AI_Block_Creator\AI_Block_REST_Controller;
use AI_Block_Creator\AI_Block_Store;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AI_Block_Creator\AI_Block_REST_Controller
 */
final class ResponseSchemaTest extends TestCase
{
    private AI_Block_REST_Controller $controller;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(AI_Block_REST_Controller::class)) {
            $this->markTestSkipped('The REST controller is not loaded in this WordPress install.');
        }

        $this->controller = new AI_Block_REST_Controller();
    }

    /**
     * Calls a private method on the controller.
     *
     * @param string       $method Method name.
     * @param array<mixed> $args   Arguments.
     * @return mixed
     */
    private function call(string $method, array $args = [])
    {
        $reflection = new ReflectionMethod(AI_Block_REST_Controller::class, $method);

        // This plugin supports PHP 7.4, where a private method cannot be
        // invoked via reflection without setAccessible(true) -- but 8.1 made
        // that a no-op and 8.5 deprecates it, so calling it unconditionally
        // raises a deprecation on modern runtimes. Version-gated to satisfy
        // both ends of the supported range.
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }

        return $reflection->invokeArgs($this->controller, $args);
    }

    /**
     * A representative, well-formed model response for each kind — the shape
     * the stage-two prompts actually ask for.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function provide_valid_responses(): array
    {
        return array(
            'custom_block'    => array(
                AI_Block_Store::KIND_CUSTOM_BLOCK,
                array(
                    'name'        => 'ai-block/testimonial',
                    'title'       => 'Testimonial',
                    'description' => 'A quote with an author.',
                    'icon'        => 'testimonial',
                    'attributes'  => array('quote' => array('type' => 'string', 'default' => 'Hi')),
                    'edit_fields' => array(array('name' => 'quote', 'label' => 'Quote', 'type' => 'text')),
                    'render_html' => '<blockquote>{{quote}}</blockquote>',
                    'css'         => '.ai-block-testimonial { color: red; }',
                ),
            ),
            'block_style'     => array(
                AI_Block_Store::KIND_BLOCK_STYLE,
                array(
                    'name'        => 'ai-gold-pullquote',
                    'label'       => 'Gold Pull-Quote',
                    'description' => 'Gold accents.',
                    'css'         => '.is-style-ai-gold-pullquote { color: gold; }',
                ),
            ),
            'block_variation' => array(
                AI_Block_Store::KIND_BLOCK_VARIATION,
                array(
                    'name'              => 'ai-two-col',
                    'title'             => 'Two Column',
                    'description'       => 'Image left.',
                    'icon'              => 'columns',
                    'attributes'        => array('align' => 'wide'),
                    'inner_block_names' => array('core/column'),
                    'css'               => '',
                ),
            ),
            'block_pattern'   => array(
                AI_Block_Store::KIND_BLOCK_PATTERN,
                array(
                    'name'           => 'ai-hero',
                    'title'          => 'Hero',
                    'description'    => 'A hero section.',
                    'keywords'       => array('hero'),
                    'viewport_width' => 1200,
                    'content'        => '<!-- wp:heading --><h2>Hi</h2><!-- /wp:heading -->',
                ),
            ),
        );
    }

    /**
     * @dataProvider provide_valid_responses
     *
     * @param string               $kind     Definition kind.
     * @param array<string, mixed> $response Well-formed model response.
     */
    public function test_a_well_formed_response_satisfies_its_own_schema(string $kind, array $response): void
    {
        // If this fails, every generation of that kind pays for a repair turn
        // it doesn't need.
        $schema   = $this->call('build_response_schema', array($kind, 'core/columns'));
        $problems = $this->call('schema_problems', array($response, $schema));

        $this->assertSame(array(), $problems);
    }

    /**
     * @dataProvider provide_valid_responses
     *
     * @param string               $kind     Definition kind.
     * @param array<string, mixed> $response Well-formed model response.
     */
    public function test_a_schema_valid_response_survives_normalization_intact(string $kind, array $response): void
    {
        // The other half of the contract: a response the schema accepts must
        // also be one the normalizer keeps, or the schema is permitting shapes
        // that silently vanish on the way to storage.
        $response['kind'] = $kind;
        if (AI_Block_Store::KIND_BLOCK_VARIATION === $kind) {
            $response['target_block'] = 'core/columns';
        }
        if (AI_Block_Store::KIND_BLOCK_STYLE === $kind) {
            $response['target_block'] = 'core/quote';
        }

        $normalized = AI_Block_Store::normalize_and_validate($response);

        $this->assertSame($kind, $normalized['kind']);
        $this->assertNotEmpty($normalized['name']);

        switch ($kind) {
            case AI_Block_Store::KIND_BLOCK_STYLE:
                $this->assertNotEmpty($normalized['css']);
                break;
            case AI_Block_Store::KIND_BLOCK_PATTERN:
                $this->assertNotEmpty($normalized['content']);
                break;
            case AI_Block_Store::KIND_BLOCK_VARIATION:
                $this->assertSame('wide', $normalized['attributes']['align']);
                break;
            default:
                $this->assertNotEmpty($normalized['render_html']);
        }
    }

    public function test_a_missing_required_field_is_reported(): void
    {
        $schema   = $this->call('build_response_schema', array(AI_Block_Store::KIND_BLOCK_STYLE, ''));
        $problems = $this->call('schema_problems', array(array('name' => 'ai-x'), $schema));

        // The validator reports the first violation rather than all of them,
        // so this asserts that a required field was flagged, not which.
        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('required', implode(' ', $problems));
    }

    public function test_unparseable_output_is_reported_even_without_a_schema(): void
    {
        // null means extract_json_from_response() couldn't find any JSON, which
        // is a repairable problem regardless of whether a schema was in play.
        $this->assertNotEmpty($this->call('schema_problems', array(null, null)));
        $this->assertSame(array(), $this->call('schema_problems', array(array('anything' => 1), null)));
    }

    public function test_extra_keys_do_not_trigger_a_repair(): void
    {
        // Normalizers drop unknown keys anyway; spending a round-trip to
        // remove one would be pure waste.
        $schema   = $this->call('build_response_schema', array(AI_Block_Store::KIND_BLOCK_STYLE, ''));
        $problems = $this->call('schema_problems', array(
            array(
                'name'      => 'ai-x',
                'label'     => 'X',
                'css'       => '.is-style-ai-x {}',
                'confidence' => 0.9,
            ),
            $schema,
        ));

        $this->assertSame(array(), $problems);
    }

    public function test_planner_schema_constrains_kind_to_the_known_set(): void
    {
        $schema = $this->call('build_planner_response_schema');

        $this->assertSame(
            AI_Block_Store::ALLOWED_KINDS,
            $schema['properties']['kind']['enum']
        );

        $valid = $this->call('schema_problems', array(
            array('kind' => 'block_style', 'target_block' => 'core/quote', 'rationale' => 'Because.'),
            $schema,
        ));
        $this->assertSame(array(), $valid);

        $invalid = $this->call('schema_problems', array(
            array('kind' => 'block_template', 'target_block' => '', 'rationale' => 'Because.'),
            $schema,
        ));
        $this->assertNotEmpty($invalid);
    }

    public function test_variation_schema_is_bounded_by_the_target_blocks_own_attributes(): void
    {
        // This is the block.json schema doing real work: the model is told,
        // structurally, exactly which attribute names core/media-text has.
        $schema     = $this->call('build_response_schema', array(AI_Block_Store::KIND_BLOCK_VARIATION, 'core/media-text'));
        $properties = $schema['properties']['attributes']['properties'] ?? array();

        $this->assertArrayHasKey('mediaPosition', $properties);
        $this->assertArrayHasKey('verticalAlignment', $properties);
        $this->assertArrayNotHasKey('notARealAttribute', $properties);
        $this->assertFalse($schema['properties']['attributes']['additionalProperties']);
    }

    public function test_variation_schema_degrades_to_an_open_object_for_an_unknown_block(): void
    {
        $schema = $this->call('build_response_schema', array(AI_Block_Store::KIND_BLOCK_VARIATION, 'vendor/not-real'));

        $this->assertSame(array('type' => 'object'), $schema['properties']['attributes']);
    }

    public function test_a_filter_cannot_offer_the_model_a_block_this_site_lacks(): void
    {
        // The filters exist so a site can point the AI at its own blocks. They
        // are intersected with the registry *after* running, so an unregistered
        // name can't reach the prompt -- the planner would pick it and the
        // choice would only be caught later, where it silently becomes the
        // fallback target instead.
        $add_blocks = static function ( array $blocks ): array {
            $blocks[] = 'core/separator';           // real, and not in the default list for patterns
            $blocks[] = 'vendor/not-a-real-block';  // not registered anywhere
            return $blocks;
        };

        add_filter('ai_block_creator_target_block_candidates', $add_blocks);
        add_filter('ai_block_creator_pattern_block_allowlist', $add_blocks);

        try {
            $targets  = $this->call('candidate_target_blocks');
            $patterns = $this->call('pattern_block_allowlist');
        } finally {
            remove_filter('ai_block_creator_target_block_candidates', $add_blocks);
            remove_filter('ai_block_creator_pattern_block_allowlist', $add_blocks);
        }

        foreach (array($targets, $patterns) as $list) {
            $this->assertContains('core/separator', $list);
            $this->assertNotContains('vendor/not-a-real-block', $list);
        }
    }

    public function test_block_attributes_are_described_to_the_model_in_the_prompt(): void
    {
        // Providers without structured-output support only get the prompt, so
        // the same block.json facts have to reach them that way too.
        $described = $this->call('describe_block_attributes', array('core/media-text'));

        $this->assertStringContainsString('mediaPosition', $described);
        $this->assertStringContainsString('ONLY attributes', $described);
        $this->assertSame('', $this->call('describe_block_attributes', array('vendor/not-real')));
    }
}
