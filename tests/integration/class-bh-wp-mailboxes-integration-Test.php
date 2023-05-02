<?php
/**
 * Class Plugin_Test. Tests the root plugin setup.
 *
 * @package brianhenryie/bh-wp-mailboxes
 * @author     BrianHenryIE <BrianHenryIE@gmail.com>
 */

namespace BrianHenryIE\WP_Mailboxes;

use BrianHenryIE\WP_Mailboxes\API\API;

/**
 * Verifies the plugin has been instantiated and added to PHP's $GLOBALS variable.
 */
class Plugin_Integration_Test extends \Codeception\TestCase\WPTestCase {

	/**
	 * Test the main plugin object is added to PHP's GLOBALS and that it is the correct class.
	 */
	public function test_plugin_instantiated() {

		$this->assertArrayHasKey( 'bh_wp_mailboxes_test_plugin', $GLOBALS );

		$this->assertInstanceOf( API::class, $GLOBALS['bh_wp_mailboxes_test_plugin'] );
	}

}
