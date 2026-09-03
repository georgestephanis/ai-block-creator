<?php
/**
 * Uninstall handler: removes all AI block definitions created by this plugin.
 *
 * @package AI_Block_Creator
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-ai-block-store.php';

// Closure keeps the loop variable out of the global scope.
( static function (): void {
	$post_ids = get_posts(
		array(
			'post_type'      => \AI_Block_Creator\AI_Block_Store::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	foreach ( $post_ids as $post_id ) {
		wp_delete_post( $post_id, true );
	}
} )();
