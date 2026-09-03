<?php
/**
 * PHPUnit bootstrap.
 *
 * Boots a real WordPress install (via wp-load.php) rather than the WP core
 * PHPUnit test suite's synthetic install-and-rollback harness. This plugin's
 * PHP is largely pure logic wrapped around a handful of WordPress functions
 * (esc_html(), esc_url(), safecss_filter_attr(), wp_kses_post(), sanitize_*,
 * get_posts()/wp_insert_post()/wp_delete_post()) rather than anything that
 * needs the full WP_UnitTestCase factory/fixture machinery, so a real,
 * already-configured WordPress install is both simpler to set up and closer
 * to the environment these functions actually run in. This mirrors exactly
 * how the fixes in plans/code-review-2026-09-03.md were originally verified
 * (via `wp eval-file` against this same install) — these tests formalize
 * that verification instead of re-deriving it ad hoc.
 *
 * Tests that touch the database (AI_Block_Store::save()/delete()) clean up
 * everything they create in tearDown(); there is no per-test transaction
 * rollback here; be careful not to rely on one.
 *
 * Point WP_ROOT at a WordPress install if this plugin isn't checked out at
 * the conventional wp-content/plugins/<slug>/ depth (e.g. a CI checkout of
 * just the plugin repo, or a symlinked dev install):
 *
 *   WP_ROOT=/path/to/wordpress vendor/bin/phpunit
 *
 * @package AI_Block_Creator
 */

declare(strict_types=1);

$wp_root = getenv('WP_ROOT');
if (!$wp_root) {
    // tests/php/bootstrap.php -> plugin root -> wp-content/plugins -> wp-content -> WP root.
    $wp_root = dirname(__DIR__, 5);
}

$wp_load = rtrim($wp_root, '/') . '/wp-load.php';

if (!file_exists($wp_load)) {
    fwrite(STDERR, "Could not find wp-load.php at \"$wp_load\".\n");
    fwrite(STDERR, "Set the WP_ROOT environment variable to your WordPress install's root directory.\n");
    exit(1);
}

// Match the plugin's normal request context: REST/front-end code, not CLI/cron shortcuts.
if (!defined('WP_USE_THEMES')) {
    define('WP_USE_THEMES', false);
}

require_once $wp_load;

$plugin_root = dirname(__DIR__, 2);

require_once $plugin_root . '/includes/class-ai-block-store.php';
require_once $plugin_root . '/includes/class-ai-block-renderer.php';

require_once $plugin_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

/**
 * AI_Block_Store's capability checks (unfiltered_html) only make sense
 * against an authenticated user, matching the REST request context they
 * actually run in — the REST permission_callback has already required an
 * authenticated, capable user by the time AI_Block_Store is ever called in
 * production. Without this, current_user_can() always returns false (a
 * logged-out visitor has no capabilities), which makes it impossible to
 * test the unfiltered_html-granted code paths at all. Tests that need the
 * non-privileged path use the `user_has_cap` filter to simulate it instead
 * of logging out.
 */
$wp_admin_user_id = get_users(
    array(
        'role'   => 'administrator',
        'number' => 1,
        'fields' => 'ID',
    )
);
if (!empty($wp_admin_user_id)) {
    wp_set_current_user((int) $wp_admin_user_id[0]);
}
