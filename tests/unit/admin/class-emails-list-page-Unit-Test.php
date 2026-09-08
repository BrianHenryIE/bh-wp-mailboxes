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
	 * Verifies enqueue_scripts() enqueues the modal's script (via Email_Account_Modal) and then the
	 * accounts table script, which depends on the modal's handle. Verifies both .js files exist.
	 *
	 * @covers ::enqueue_scripts
	 * @see wp_enqueue_script()
	 */
	public function test_enqueue_scripts() {

		global $plugin_root_dir;

		// Called for each script and each stylesheet.
		\WP_Mock::userFunction(
			'plugin_dir_url',
			array(
				'return' => $plugin_root_dir . '/admin/',
				'times'  => 4,
			)
		);

		$emails_cpt_underscored = 'test_cpt';

		$modal_handle = 'bh-wp-mailboxes-account-modal-test-accounts-cpt';
		$modal_src    = $plugin_root_dir . '/admin/js/account-modal.js';
		$table_handle = 'bh-wp-mailboxes-admin-test-accounts-cpt';
		$table_src    = $plugin_root_dir . '/admin/js/bh-wp-mailboxes.js';
		$ver          = BH_WP_Mailboxes::get_version();
		$in_footer    = true;

		\WP_Mock::userFunction(
			'wp_enqueue_script',
			array(
				'times' => 1,
				'args'  => array( $modal_handle, $modal_src, array( 'jquery' ), $ver, $in_footer ),
			)
		);
		\WP_Mock::userFunction(
			'wp_enqueue_script',
			array(
				'times' => 1,
				'args'  => array( $table_handle, $table_src, array( 'jquery', $modal_handle ), $ver, $in_footer ),
			)
		);

		// The scoped AJAX action names + remote-action nonce are localised for the JS (see Email_Account_Modal::enqueue_assets()).
		\WP_Mock::userFunction( 'wp_create_nonce', array( 'return' => 'test-nonce' ) );
		\WP_Mock::userFunction( 'wp_localize_script' );
		\WP_Mock::userFunction( 'wp_script_is', array( 'return' => false ) );
		\WP_Mock::userFunction( 'wp_enqueue_style', array( 'times' => 2 ) );

		// New-row highlight CSS is injected inline.
		\WP_Mock::userFunction( 'wp_add_inline_style' );

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
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( $emails_cpt_underscored );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( 'test_accounts_cpt' );
		$settings->allows( 'get_email_accounts_cpt_dashed' )->andReturn( 'test-accounts-cpt' );

		$sut = $this->get_sut( settings: $settings );

		$sut->enqueue_scripts();

		$this->assertFileExists( $modal_src );
		$this->assertFileExists( $table_src );
		$this->assertFileExists( $plugin_root_dir . '/admin/css/account-modal.css' );
		$this->assertFileExists( $plugin_root_dir . '/admin/css/accounts-table.css' );
	}
}
