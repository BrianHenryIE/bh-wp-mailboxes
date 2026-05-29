<?php
/**
 * Convenience, demo and test-helper functions for bh-wp-mailboxes.
 *
 * This is a separate WordPress plugin, activated only during development and end-to-end testing.
 * It is never included in the release archive. It adds REST endpoints and authentication shortcuts
 * used to arrange/assert Playwright tests, and wp-env fixes — none of which should ever exist in
 * production. It runs only when the library's test-plugin harness is active.
 *
 * @package brianhenryie/bh-wp-mailboxes
 *
 * @wordpress-plugin
 * Plugin Name:       BH WP Mailboxes Development Plugin
 * Plugin URI:        http://github.com/BrianHenryIE/bh-wp-mailboxes/
 * Description:       Convenience, demo and test helper functions. Activate only in dev/test.
 * Version:           1.0.0
 * Requires PHP:      8.4
 * Author:            BrianHenryIE
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin;

use Alley_Interactive\Autoloader\Autoloader;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Rest\Mailboxes;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	return;
}

// Only run when the test-plugin harness (which boots the library) is active.
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if ( ! is_plugin_active( 'bh-wp-mailboxes-test-plugin/bh-wp-mailboxes-test-plugin.php' ) ) {
	return;
}

// The library's prefixed Autoloader is already loaded by the test-plugin's vendor autoload.
Autoloader::generate(
	'BrianHenryIE\\WP_Mailboxes_Development_Plugin',
	__DIR__,
)->register();

// wp-env fixes (cron / self-referential URLs).
( new WP_Env() )->register_hooks();

// Authentication shortcuts for e2e arrangement (login-as-user, treat REST callers as admin).
( new Authentication() )->register_hooks();

// Custom REST endpoints for arranging/asserting e2e tests.
( new Mailboxes() )->register_hooks();
