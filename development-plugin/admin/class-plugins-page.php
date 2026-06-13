<?php
/**
 * Adds action links to the plugin row on the Plugins page.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Admin;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;

/**
 * Adds custom action links to the development plugin row on the Plugins admin page.
 */
class Plugins_Page {
	public function __construct(
		protected BH_WP_Mailboxes_Settings_Interface $mailboxes_settings
	) {
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		$plugin_basename = defined( 'BH_WP_MAILBOXES_DEVELOPMENT_PLUGIN_BASENAME' )
			? BH_WP_MAILBOXES_DEVELOPMENT_PLUGIN_BASENAME
			: 'development-plugin/development-plugin.php';
		add_filter( "plugin_action_links_{$plugin_basename}", $this->display_plugin_action_links( ... ), 10, 4 );
	}

	/**
	 * Adds a Mailboxes link to the plugin's action links.
	 *
	 * @hooked plugin_action_links_{$basename}
	 * @see \WP_Plugins_List_Table::display_rows()
	 *
	 * @param array<int|string, string>  $action_links      The existing plugin links (usually "Deactivate").
	 * @param string                     $_plugin_basename  The plugin's directory/filename.php.
	 * @param array<string, string|bool> $_plugin_data      Associative array including PluginURI, slug, Author, Version. See `get_plugin_data()`.
	 * @param string                     $_context          The plugin context.
	 *
	 * @return array<int|string, string> The links to display below the plugin name on plugins.php.
	 */
	public function display_plugin_action_links( array $action_links, string $_plugin_basename, $_plugin_data, $_context ): array {

		$cpt            = $this->mailboxes_settings->get_emails_cpt_underscored_20();
		$list_page_link = admin_url( 'edit.php?post_type=' . $cpt );

		array_unshift( $action_links, '<a href="' . $list_page_link . '">' . __( 'Mailboxes', 'bh-wp-logger' ) . '</a>' );

		return $action_links;
	}
}
