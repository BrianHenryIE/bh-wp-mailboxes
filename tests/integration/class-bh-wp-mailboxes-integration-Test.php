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

		$this->assertTrue( defined( 'BH_WP_MAILBOXES_DEVELOPMENT_PLUGIN_BASENAME' ), 'Plugin constant BH_WP_MAILBOXES_DEVELOPMENT_PLUGIN_BASENAME not defined, plugin likely not loaded' );
	}
}
