<?php

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin;

class Mappings {

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'wp_plugin_paths' ) );
		add_filter( 'plugins_url', array( $this, 'plugins_url_fix' ), 10, 3 );
	}

	public function wp_plugin_paths(): void {

		/**
		 * Fix for mapped directories. I.e. vendor is not under `wp-content/plugins/development-plugins`.
		 *
		 * @see plugin_basename()
		 */
		global $wp_plugin_paths;
		$plugin_path = '/var/www/html/wp-content/uploads/bh-wp-mailboxes/';
		$wp_plugin_paths[ WP_PLUGIN_DIR . '/development-plugin/' ] = $plugin_path;
	}

	/**
	 * Partial fix for symlinks.
	 *
	 * In wp-env: vendor is mapped to wp-content/plugins/vendor.
	 * TODO: address the same issue in integration tests.
	 *
	 * /var/www/html/wp-content/uploads/bh-wp-mailboxes/vendor/brianhenryie/bh-wp-private-uploads/includes/admin/class-admin-assets.php
	 * http://localhost:8888/wp-content/plugins/development-plugin/vendor/brianhenryie/bh-wp-private-uploads/includes/admin/assets/bh-wp-private-uploads-admin.js
	 * http://localhost:8888/wp-content/uploads/bh-wp-mailboxes/vendor/brianhenryie/bh-wp-private-uploads/includes/admin/assets/bh-wp-private-uploads-admin.js
	 *
	 * @hooked plugins_url
	 */
	public function plugins_url_fix( $url, $_path, $_plugin ) {
		$url = str_replace( 'wp-content/plugins/var/www/html/', '', $url );
		$url = str_replace( 'plugins/development-plugin/vendor', 'uploads/bh-wp-mailboxes/vendor', $url );
		$url = str_replace( 'plugins/development-plugin/includes', 'uploads/bh-wp-mailboxes/includes', $url );
		return $url;
	}
}
