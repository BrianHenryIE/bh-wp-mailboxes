<?php
/**
 * @package BH_WP_Emails_Unit_Name
 * @author  BrianHenryIE <BrianHenryIE@gmail.com>
 */

namespace BH_WP_Emails\Includes;

use BH_WP_Emails\Admin\Admin;
use BH_WP_Emails\Frontend\Frontend;
use WP_Mock\Matcher\AnyInstance;

/**
 * Class BH_WP_Emails_Unit_Test
 * @coversDefaultClass \BH_WP_Emails\Includes\BH_WP_Emails
 */
class BH_WP_Emails_Unit_Test extends \Codeception\Test\Unit {

	protected function setup(): void {
	    parent::setup();
		\WP_Mock::setUp();
	}

	protected function tearDown(): void {
		parent::tearDown();
		\WP_Mock::tearDown();
	}

	/**
	 * @covers ::set_locale
	 */
	public function test_set_locale_hooked() {

		\WP_Mock::expectActionAdded(
			'init',
			array( new AnyInstance( I18n::class ), 'load_plugin_textdomain' )
		);

		new BH_WP_Emails();
	}

	/**
	 * @covers ::define_admin_hooks
	 */
	public function test_admin_hooks() {

		\WP_Mock::expectActionAdded(
			'admin_enqueue_scripts',
			array( new AnyInstance( Admin::class ), 'enqueue_styles' )
		);

		\WP_Mock::expectActionAdded(
			'admin_enqueue_scripts',
			array( new AnyInstance( Admin::class ), 'enqueue_scripts' )
		);

		new BH_WP_Emails();
	}

	/**
	 * @covers ::define_frontend_hooks
	 */
	public function test_frontend_hooks() {

		\WP_Mock::expectActionAdded(
			'wp_enqueue_scripts',
			array( new AnyInstance( Frontend::class ), 'enqueue_styles' )
		);

		\WP_Mock::expectActionAdded(
			'wp_enqueue_scripts',
			array( new AnyInstance( Frontend::class ), 'enqueue_scripts' )
		);

		new BH_WP_Emails();
	}

}
