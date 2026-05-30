<?php
/**
 * Tests for Admin.
 *
 * @see Admin
 *
 * @package bh-wp-mailboxes
 * @author Brian Henry <BrianHenryIE@gmail.com>
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;

/**
 *
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Admin\Mailbox_List_Page
 */
class Mailbox_List_Page_Test extends Unit_Testcase {

	protected function setup(): void {
		parent::setup();
		\WP_Mock::setUp();
	}

	protected function tearDown(): void {
		parent::tearDown();
		\WP_Mock::tearDown();
	}

	/**
	 * Verifies enqueue_styles() calls wp_enqueue_style() with appropriate parameters.
	 * Verifies the .css file exists.
	 *
	 * @covers ::enqueue_styles
	 * @see wp_enqueue_style()
	 */
	public function test_enqueue_styles() {

		$this->markTestIncomplete();

		global $plugin_root_dir;

		// Return any old url.
		\WP_Mock::userFunction(
			'plugin_dir_url',
			array(
				'return' => $plugin_root_dir . '/admin/',
			)
		);

		$css_file = $plugin_root_dir . '/admin/css/bh-wp-mailboxes-admin.css';

		\WP_Mock::userFunction(
			'wp_enqueue_style',
			array(
				'times' => 1,
				'args'  => array( $handle, $css_file, array(), $version, 'all' ),
			)
		);

		$api      = $this->makeEmpty( API_Interface::class );
		$settings = $this->makeEmpty( Mailbox_Settings_Interface::class );
		$logger   = new ColorLogger();

		$sut = new Mailbox_List_Page( $api, $settings, $logger );

		$sut->enqueue_styles();

		$this->assertFileExists( $css_file );
	}

	/**
	 * Verifies enqueue_scripts() calls wp_enqueue_script() with appropriate parameters.
	 * Verifies the .js file exists.
	 *
	 * @covers ::enqueue_scripts
	 * @see wp_enqueue_script()
	 */
	public function test_enqueue_scripts() {

		$this->markTestIncomplete();

		global $plugin_root_dir;

		// Return any old url.
		\WP_Mock::userFunction(
			'plugin_dir_url',
			array(
				'return' => $plugin_root_dir . '/admin/',
			)
		);

		$handle    = $plugin_name;
		$src       = $plugin_root_dir . '/admin/js/bh-wp-mailboxes-admin.js';
		$deps      = array( 'jquery' );
		$ver       = $version;
		$in_footer = true;

		\WP_Mock::userFunction(
			'wp_enqueue_script',
			array(
				'times' => 1,
				'args'  => array( $handle, $src, $deps, $ver, $in_footer ),
			)
		);

		$api      = $this->makeEmpty( API_Interface::class );
		$settings = $this->makeEmpty( Mailbox_Settings_Interface::class );
		$logger   = new ColorLogger();

		$sut = new Mailbox_List_Page( $api, $settings, $logger );

		$sut->enqueue_scripts();

		$this->assertFileExists( $src );
	}
}
