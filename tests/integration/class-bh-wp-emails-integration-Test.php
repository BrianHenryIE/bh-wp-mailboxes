<?php
/**
 * Class Plugin_Test. Tests the root plugin setup.
 *
 * @package BH_WP_Emails
 * @author     BrianHenryIE <BrianHenryIE@gmail.com>
 */

namespace BH_WP_Emails;

use BH_WP_Emails\Includes\BH_WP_Emails;

/**
 * Verifies the plugin has been instantiated and added to PHP's $GLOBALS variable.
 */
class Plugin_Integration_Test extends \Codeception\TestCase\WPTestCase {

	/**
	 * Test the main plugin object is added to PHP's GLOBALS and that it is the correct class.
	 */
	public function test_plugin_instantiated() {

		$this->assertArrayHasKey( 'bh_wp_emails', $GLOBALS );

		$this->assertInstanceOf( BH_WP_Emails::class, $GLOBALS['bh_wp_emails'] );
	}

}
