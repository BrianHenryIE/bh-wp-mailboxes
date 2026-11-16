<?php
/**
 * WPUnit tests for BH_WP_Mailboxes_Hooks CPT registration.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\Models\Private_Uploads_Fixture;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Mockery;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\WP_Includes\BH_WP_Mailboxes_Hooks
 */
class BH_WP_Mailboxes_Hooks_WPUnit_Test extends WPUnit_Testcase {

	/**
	 * Both the emails CPT and the accounts CPT must be registered on init.
	 *
	 * Regression: define_cpt_hooks() created two BH_Email_CPT instances, so the accounts CPT was never
	 * registered. Its posts still existed, so capability checks against them (e.g. the dashboard Activity
	 * widget's recent comments) emitted "post type … is not registered" notices.
	 *
	 * @covers ::define_cpt_hooks
	 */
	public function test_emails_and_accounts_cpts_are_registered_on_init(): void {

		$emails_cpt   = 'test_hooks_email';
		$accounts_cpt = 'test_hooks_account';

		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class )->shouldIgnoreMissing();
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( $emails_cpt );
		$settings->allows( 'get_emails_cpt_friendly_name' )->andReturn( 'Test Hooks Emails' );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( $accounts_cpt );
		$settings->allows( 'get_email_accounts_cpt_friendly_name' )->andReturn( 'Test Hooks Accounts' );

		$api = Mockery::mock( API_Interface::class )->shouldIgnoreMissing();

		remove_all_actions( 'init' );

		// Constructing the hooks registers the CPT registration callbacks on `init`.
		new BH_WP_Mailboxes_Hooks( $api, $settings, $this->logger );

		do_action( 'init' );

		$this->assertTrue( post_type_exists( $emails_cpt ), 'The emails CPT should be registered.' );
		$this->assertTrue( post_type_exists( $accounts_cpt ), 'The accounts CPT should be registered.' );
	}

	/**
	 * The thread taxonomy is registered for the emails CPT on `init`, and emptied threads are cleaned up when
	 * term relationships are deleted.
	 *
	 * @covers ::define_cpt_hooks
	 */
	public function test_thread_taxonomy_is_registered_on_init(): void {

		$emails_cpt = 'test_hooks_tx_email';

		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class )->shouldIgnoreMissing();
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( $emails_cpt );
		$settings->allows( 'get_emails_cpt_friendly_name' )->andReturn( 'Test Hooks Tx Emails' );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( 'test_hooks_tx_acct' );
		$settings->allows( 'get_email_accounts_cpt_friendly_name' )->andReturn( 'Test Hooks Tx Accounts' );

		remove_all_actions( 'init' );
		remove_all_actions( 'deleted_term_relationships' );

		new BH_WP_Mailboxes_Hooks( Mockery::mock( API_Interface::class )->shouldIgnoreMissing(), $settings, $this->logger );

		do_action( 'init' );

		$taxonomy = "{$emails_cpt}_thread";
		try {
			$this->assertTrue( taxonomy_exists( $taxonomy ), 'The thread taxonomy should be registered.' );
			$this->assertSame( array( $emails_cpt ), get_taxonomy( $taxonomy )->object_type ?? null );
			$this->assertTrue( has_action( 'deleted_term_relationships' ), 'Emptied threads should be cleaned up.' );
		} finally {
			unregister_taxonomy( $taxonomy );
		}
	}

	/**
	 * The emails and accounts REST routes are registered under the mailbox's namespace on `rest_api_init`,
	 * whether or not a REST namespace is configured (they need a logged-in user, not the ingress setting).
	 *
	 * @covers ::define_rest_hooks
	 * @covers ::register_rest_controllers
	 */
	public function test_rest_controllers_are_registered_on_rest_api_init(): void {

		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class )->shouldIgnoreMissing();
		$settings->allows( 'get_plugin_slug' )->andReturn( 'test-hooks' );
		$settings->allows( 'get_rest_namespace' )->andReturn( null );
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( 'test_hooks_email' );
		$settings->allows( 'get_emails_cpt_dashed' )->andReturn( 'test-hooks-email' );
		$settings->allows( 'get_emails_cpt_friendly_name' )->andReturn( 'Test Hooks Emails' );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( 'test_hooks_account' );
		$settings->allows( 'get_email_accounts_cpt_dashed' )->andReturn( 'test-hooks-account' );
		$settings->allows( 'get_email_accounts_cpt_friendly_name' )->andReturn( 'Test Hooks Accounts' );

		$api = Mockery::mock( API_Interface::class )->shouldIgnoreMissing();

		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_actions( 'rest_api_init' );
		remove_all_filters( 'rest_index' );

		new BH_WP_Mailboxes_Hooks( $api, $settings, $this->logger );

		try {
			$routes = rest_get_server()->get_routes();
		} finally {
			$wp_rest_server = null;
			remove_all_actions( 'rest_api_init' );
			remove_all_filters( 'rest_index' );
		}

		$this->assertArrayHasKey( '/test-hooks/v2/test-hooks-email', $routes );
		$this->assertArrayHasKey( '/test-hooks/v2/test-hooks-email/check', $routes );
		$this->assertArrayHasKey( '/test-hooks/v2/test-hooks-account', $routes );
		$this->assertArrayHasKey( '/test-hooks/v2/test-hooks-account/test-connection', $routes );
	}
	/**
	 * The hooks wire the deletion handler, so trashing, restoring and deleting an email cascades to its saved
	 * attachments whatever path does it (here, core's own functions).
	 *
	 * @covers ::define_deletion_hooks
	 */
	public function test_email_deletion_cascades_to_attachments(): void {

		$emails_cpt = 'test_hooks_del_email';

		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class )->shouldIgnoreMissing();
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( $emails_cpt );
		$settings->allows( 'get_emails_cpt_friendly_name' )->andReturn( 'Test Hooks Del Emails' );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( 'test_hooks_del_acct' );
		$settings->allows( 'get_email_accounts_cpt_friendly_name' )->andReturn( 'Test Hooks Del Accounts' );
		$settings->allows( 'get_rest_namespace' )->andReturn( null );

		foreach ( array( 'trashed_post', 'untrashed_post', 'wp_untrash_post_status', 'before_delete_post', 'init' ) as $hook ) {
			remove_all_actions( $hook );
		}

		new BH_WP_Mailboxes_Hooks( Mockery::mock( API_Interface::class )->shouldIgnoreMissing(), $settings, $this->logger );

		$this->assertTrue( has_action( 'trashed_post' ), 'Trashing cascades.' );
		$this->assertTrue( has_action( 'untrashed_post' ), 'Restoring cascades.' );
		$this->assertTrue( has_filter( 'wp_untrash_post_status' ), 'Restored attachments keep their status.' );
		$this->assertTrue( has_action( 'before_delete_post' ), 'Deleting cascades.' );

		do_action( 'init' );

		/** @var IMessage $message */
		$message = ( new MailMimeParser() )->parse( (string) file_get_contents( (string) codecept_root_dir( 'tests/_data/wpunit/with-attachment.eml' ) ), true );
		$email   = ( new Email_WP_Post_Repository( $emails_cpt, new BH_Email_Factory( $this->logger ), $this->logger ) )->save_new(
			new Fetched_Email( $message, new Remote_Email_Coordinates( message_id: $message->getMessageId() ?? '' ) ),
			$settings,
			BH_Email_Account_Fixture::make(),
			Private_Uploads_Fixture::make( $this->logger ),
		);

		$attachment_id = ( $email->attachment_ids ?? array() )[0] ?? 0;
		$file          = get_attached_file( $attachment_id );
		$this->assertIsString( $file );

		try {
			wp_trash_post( $email->post_id );
			$this->assertSame( 'trash', get_post_status( $attachment_id ) );

			wp_untrash_post( $email->post_id );
			$this->assertSame( 'inherit', get_post_status( $attachment_id ) );

			wp_delete_post( $email->post_id, true );
			$this->assertNull( get_post( $attachment_id ) );
			$this->assertFileDoesNotExist( $file );
		} finally {
			Private_Uploads_Fixture::delete_files( array( $attachment_id ) );
		}
	}
}
