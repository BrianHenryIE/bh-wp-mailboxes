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
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use Mockery;
use stdClass;

/**
 *
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Admin\Emails_List_Page
 */
class Emails_List_Page_Unit_Test extends Unit_Testcase {

	protected function get_sut(
		?Email_WP_Post_Repository $email_wp_post_repository = null,
		?API_Interface $api = null,
		?BH_WP_Mailboxes_Settings_Interface $settings = null,
	): Emails_List_Page {
		return new Emails_List_Page(
			$email_wp_post_repository ?? Mockery::mock( Email_WP_Post_Repository::class ),
			$api ?? Mockery::mock( API_Interface::class ),
			$settings ?? Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class ),
			$this->logger
		);
	}

	/**
	 * @covers ::table_head
	 */
	public function test_column_added(): void {

		$sut     = $this->get_sut();
		$columns = $sut->table_head( array( 'cb' => '' ) );

		$this->assertArrayHasKey( 'from', $columns );
	}

	/**
	 * Verifies enqueue_styles() calls wp_enqueue_style() with appropriate parameters.
	 * Verifies the .css file exists.
	 *
	 * @covers ::enqueue_styles
	 * @see wp_enqueue_style()
	 */
	public function test_enqueue_styles() {

		$this->markTestIncomplete( 'No styles enqueued yet.' );

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
		$settings = $this->makeEmpty( Email_Account_Settings_Interface::class );
		$logger   = new ColorLogger();

		$sut = new Emails_List_Page( $api, $settings, $logger );

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

		global $plugin_root_dir;

		\WP_Mock::userFunction(
			'plugin_dir_url',
			array(
				'return' => $plugin_root_dir . '/admin/',
				'times'  => 1,
			)
		);

		$emails_cpt_dashed      = 'test-cpt';
		$emails_cpt_underscored = 'test_cpt';

		$handle    = "{$emails_cpt_dashed}-list-page-script";
		$src       = $plugin_root_dir . '/admin/js/bh-wp-mailboxes.js';
		$deps      = array( 'jquery' );
		$ver       = BH_WP_Mailboxes::get_version();
		$in_footer = true;

		\WP_Mock::userFunction(
			'wp_enqueue_script',
			array(
				'times' => 1,
				'args'  => array( $handle, $src, $deps, $ver, $in_footer ),
			)
		);

		$wp_screen            = new stdClass();
		$wp_screen->post_type = $emails_cpt_underscored;

		\WP_Mock::userFunction(
			'get_current_screen',
			array(
				'times'  => 1,
				'return' => $wp_screen,
			)
		);

		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->expects( 'get_emails_cpt_underscored_20' )->andReturn( $emails_cpt_underscored );
		$settings->expects( 'get_emails_cpt_dashed' )->andReturn( $emails_cpt_dashed );

		$sut = $this->get_sut( settings: $settings );

		$sut->enqueue_scripts();

		$this->assertFileExists( $src );
	}
}
