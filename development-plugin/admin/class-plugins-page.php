<?php

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Admin;

class Plugins_Page {

	public function register_hooks() {
		$plugin_basename = BH_WP_MAILBOXES_DEVELOPMENT_PLUGIN_BASENAME;
		add_filter( "plugin_action_links_{$plugin_basename}", $this->display_plugin_action_links( ... ), 10, 4 );
	}

	/**
	 * @hooked plugin_action_links_{plugin basename}
	 * @see \WP_Plugins_List_Table::display_rows()
	 *
	 * @hooked plugin_action_links_{$basename}
	 *
	 * @param array<int|string, string>  $action_links The existing plugin links (usually "Deactivate").
	 * @param string                     $_plugin_basename The plugin's directory/filename.php.
	 * @param array<string, string|bool> $_plugin_data Associative array including PluginURI, slug, Author, Version. See `get_plugin_data()`.
	 * @param string                     $_context     The plugin context. By default this can include 'all', 'active', 'inactive',
	 *                                                'recently_activated', 'upgrade', 'mustuse', 'dropins', and 'search'.
	 *
	 * @return array<int|string, string> The links to display below the plugin name on plugins.php.
	 */
	public function display_plugin_action_links( array $action_links, string $_plugin_basename, $_plugin_data, $_context ): array {

		$list_page_link = admin_url( 'edit.php?post_type=bh_wp_mailboxes_cpt' );

		array_unshift( $action_links, '<a href="' . $list_page_link . '">' . __( 'Mailboxes', 'bh-wp-logger' ) . '</a>' );

		return $action_links;
	}
}
