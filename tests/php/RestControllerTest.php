<?php
/**
 * REST-boundary integration tests for AI_Block_REST_Controller.
 *
 * Unlike BlockStoreTest.php (which calls AI_Block_Store directly),
 * these dispatch real WP_REST_Request objects through rest_do_request(),
 * exercising route registration, args validation, and JSON body parsing
 * exactly as a live request would. This is the layer that let a client/
 * server contract drift ship unnoticed: the client began sending the block
 * definition as the raw request body while the route still required a
 * `block_definition` wrapper param, and every save/insert 400'd — see
 * plans/done/review-2026-09-03-external-changes.md.
 *
 * @package AI_Block_Creator
 */

declare(strict_types=1);

use AI_Block_Creator\AI_Block_Store;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AI_Block_Creator\AI_Block_REST_Controller
 */
final class RestControllerTest extends TestCase
{
    /**
     * Post IDs created during a test, deleted in tearDown() as a backstop
     * in case a test's own DELETE request assertion fails first.
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

    /**
     * Dispatches a POST /blocks request with the given body encoded as JSON,
     * matching exactly how the client's apiFetch({ data: ... }) call sends it.
     *
     * @param array<string, mixed> $body Request body.
     * @return WP_REST_Response
     */
    private function post_blocks(array $body): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ai-block-creator/v1/blocks');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));

        return rest_do_request($request);
    }

    public function test_save_accepts_the_definition_as_the_raw_request_body(): void
    {
        // This is the shape AIBlockCreatorModal.js actually sends:
        // apiFetch({ path: '/ai-block-creator/v1/blocks', method: 'POST', data: currentBlock }).
        // A regression here means every Save/Insert in the UI is broken.
        $response = $this->post_blocks(array(
            'name'        => 'ai-block/rest-body-shape-test',
            'title'       => 'REST Body Shape Test',
            'render_html' => '<div>{{title}}</div>',
        ));
        $data = $response->get_data();
        // Tracked for cleanup before any assertion, so a failed assertion
        // below still gets the created post deleted in tearDown() rather
        // than leaking a row into the real database.
        if ( ! empty( $data['block']['id'] ) ) {
            $this->created_post_ids[] = (int) $data['block']['id'];
        }

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['success']);
        $this->assertSame('ai-block/rest-body-shape-test', $data['block']['name']);
    }

    public function test_save_also_accepts_the_legacy_block_definition_wrapper(): void
    {
        // Backward/forward compatibility with any caller still using the
        // older { block_definition: {...} } shape.
        $response = $this->post_blocks(array(
            'block_definition' => array(
                'name'  => 'ai-block/legacy-wrapper-test',
                'title' => 'Legacy Wrapper Test',
            ),
        ));
        $data = $response->get_data();
        if ( ! empty( $data['block']['id'] ) ) {
            $this->created_post_ids[] = (int) $data['block']['id'];
        }

        $this->assertSame(200, $response->get_status());
        $this->assertSame('ai-block/legacy-wrapper-test', $data['block']['name']);
    }

    public function test_save_rejects_an_empty_body(): void
    {
        $response = $this->post_blocks(array());
        $this->assertSame(400, $response->get_status());
    }

    public function test_saved_block_is_retrievable_via_get_blocks(): void
    {
        $saved = $this->post_blocks(array(
            'name'  => 'ai-block/rest-get-round-trip',
            'title' => 'REST GET Round Trip',
        ))->get_data();
        $this->created_post_ids[] = (int) $saved['block']['id'];

        $request = new WP_REST_Request('GET', '/ai-block-creator/v1/blocks');
        $list = rest_do_request($request)->get_data();

        $names = array_column($list, 'name');
        $this->assertContains('ai-block/rest-get-round-trip', $names);
    }

    public function test_delete_removes_a_block_created_via_the_real_save_route(): void
    {
        $saved = $this->post_blocks(array(
            'name'  => 'ai-block/rest-delete-round-trip',
            'title' => 'REST Delete Round Trip',
        ))->get_data();
        $post_id = (int) $saved['block']['id'];
        // Backstop: if the delete assertions below fail, tearDown() still
        // cleans this up (AI_Block_Store::delete() on an already-deleted
        // ID is a harmless no-op error, not a fatal).
        $this->created_post_ids[] = $post_id;

        $delete_request = new WP_REST_Request('DELETE', "/ai-block-creator/v1/blocks/{$post_id}");
        $delete_request->set_param('id', $post_id);
        $delete_response = rest_do_request($delete_request);

        $this->assertSame(200, $delete_response->get_status());
        $this->assertNull(AI_Block_Store::get('ai-block/rest-delete-round-trip'));
    }

    public function test_plan_route_is_registered(): void
    {
        // The planning stage is a separate route so the editor can show (and
        // let an author override) the decision before paying for generation.
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey('/ai-block-creator/v1/plan', $routes);
    }

    public function test_plan_rejects_an_empty_prompt(): void
    {
        $request = new WP_REST_Request('POST', '/ai-block-creator/v1/plan');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(array('prompt' => '   ')));

        $this->assertSame(400, rest_do_request($request)->get_status());
    }

    public function test_generate_rejects_an_unrecognized_kind(): void
    {
        // `kind` is an author override of the planner, so it is enum-validated
        // at the route rather than quietly coerced somewhere downstream.
        $request = new WP_REST_Request('POST', '/ai-block-creator/v1/generate');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(array(
            'prompt' => 'A gold pull quote',
            'kind'   => 'block_pattern',
        )));

        $this->assertSame(400, rest_do_request($request)->get_status());
    }

    public function test_save_round_trips_a_block_style_definition(): void
    {
        $response = $this->post_blocks(array(
            'kind'         => 'block_style',
            'name'         => 'rest-style-round-trip',
            'label'        => 'REST Style Round Trip',
            'target_block' => 'core/quote',
            'css'          => '.is-style-ai-rest-style-round-trip { color: gold; }',
        ));
        $data = $response->get_data();
        if ( ! empty( $data['block']['id'] ) ) {
            $this->created_post_ids[] = (int) $data['block']['id'];
        }

        $this->assertSame(200, $response->get_status());
        $this->assertSame(AI_Block_Store::KIND_BLOCK_STYLE, $data['block']['kind']);
        $this->assertSame('ai-rest-style-round-trip', $data['block']['name']);
        $this->assertSame('core/quote', $data['block']['target_block']);
        $this->assertArrayNotHasKey('render_html', $data['block']);
    }

    public function test_save_round_trips_a_block_variation_definition(): void
    {
        $response = $this->post_blocks(array(
            'kind'              => 'block_variation',
            'name'              => 'rest-variation-round-trip',
            'title'             => 'REST Variation Round Trip',
            'target_block'      => 'core/columns',
            'attributes'        => array('align' => 'wide'),
            'inner_block_names' => array('core/column', 'core/column'),
        ));
        $data = $response->get_data();
        if ( ! empty( $data['block']['id'] ) ) {
            $this->created_post_ids[] = (int) $data['block']['id'];
        }

        $this->assertSame(200, $response->get_status());
        $this->assertSame(AI_Block_Store::KIND_BLOCK_VARIATION, $data['block']['kind']);
        $this->assertSame('wide', $data['block']['attributes']['align']);
        $this->assertSame(array('core/column', 'core/column'), $data['block']['inner_block_names']);
    }

    public function test_delete_refuses_to_touch_a_post_that_is_not_a_block_definition(): void
    {
        // SEC-1 regression guard at the REST layer specifically (BlockStoreTest
        // already covers this at the AI_Block_Store layer).
        $unrelated_post_id = wp_insert_post(array(
            'post_title'  => 'An Ordinary Post',
            'post_type'   => 'post',
            'post_status' => 'publish',
        ));
        $this->assertIsInt($unrelated_post_id);

        $delete_request = new WP_REST_Request('DELETE', "/ai-block-creator/v1/blocks/{$unrelated_post_id}");
        $delete_request->set_param('id', $unrelated_post_id);
        $delete_response = rest_do_request($delete_request);

        $this->assertSame(404, $delete_response->get_status());
        $this->assertNotNull(get_post($unrelated_post_id));

        wp_delete_post($unrelated_post_id, true);
    }
}
