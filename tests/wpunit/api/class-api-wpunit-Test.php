<?php
/**
 * WPUnit tests for API.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Account_Factory;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Factories\New_Email_Factory;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account_CPT;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Fixture;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use BrianHenryIE\WP_Private_Uploads\API\API as Private_Uploads;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\API
 */
class API_WPUnit_Test extends WPUnit_Testcase {

	// -------------------------------------------------------------------------
	// Order notes / log comments
	// -------------------------------------------------------------------------

	/**
	 * Returns an API instance with all it dependencies mocked unless they are specified.
	 *
	 * @param ?BH_WP_Mailboxes_Settings_Interface $settings Plugin slug, cpt name, cron schedules.
	 * @param ?Email_WP_Post_Repository           $email_repository Respository to save emails.
	 * @param ?Email_Account_WP_Post_Repository   $email_account_repository Repository to save email accounts.
	 * @param ?Private_Uploads                    $private_uploads Library to save attachments.
	 */
	protected function get_api(
		?BH_WP_Mailboxes_Settings_Interface $settings = null,
		?Email_WP_Post_Repository $email_repository = null,
		?Email_Account_WP_Post_Repository $email_account_repository = null,
		?Private_Uploads $private_uploads = null,
	): API {
		return new API(
			$settings ?? \Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class ),
			$email_repository ?? \Mockery::mock( Email_WP_Post_Repository::class ),
			$email_account_repository ?? \Mockery::mock( Email_Account_WP_Post_Repository::class ),
			new New_Email_Factory(),
			$private_uploads ?? \Mockery::mock( Private_Uploads::class ),
			$this->logger,
		);
	}

	/**
	 * Requirement 7: insert_email_log_note creates a WP comment with comment_type 'bh_email_log'.
	 *
	 * This confirms that status-change logs are stored in a way that lets them be
	 * rendered like WooCommerce order notes (same pattern: custom comment_type on the post).
	 *
	 * @covers ::insert_email_log_note
	 */
	public function test_insert_email_log_note_creates_comment_with_bh_email_log_type(): void {

		$post_type = 'test_api_email';
		if ( ! post_type_exists( $post_type ) ) {
			register_post_type( $post_type, array( 'public' => false ) );
		}

		$repository = new Email_WP_Post_Repository(
			$post_type,
			new BH_Email_Factory( $this->logger ),
			$this->logger,
		);

		$bh_email = BH_Email_Fixture::make_from_file();
		$post_id  = $bh_email->post_id;

		$api = $this->get_api( email_repository: $repository );

		$api->insert_email_log_note( $post_id, 'Status changed from "bh_email_new" to "bh_email_processed".' );

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'bh_email_log',
			)
		);

		// The email also carries an automatic "downloaded" log entry, so locate the status-change note.
		$status_notes = array_values(
			array_filter( $comments, fn( $comment ) => str_contains( $comment->comment_content, 'bh_email_processed' ) )
		);

		$this->assertCount( 1, $status_notes, 'Exactly one status-change bh_email_log comment should exist' );
		$this->assertSame( 'bh_email_log', $status_notes[0]->comment_type );
		$this->assertStringContainsString( 'bh_email_processed', $status_notes[0]->comment_content );
	}

	/**
	 * The add_email_account() duplicate check must only reject a genuinely duplicate address; a second,
	 * distinct account must be allowed even when one already exists.
	 *
	 * Regression: the dedup query filtered by post_name/meta_input (both ignored by WP_Query), so it
	 * matched every existing account and false-positived "already exists" once any account existed.
	 *
	 * @covers ::add_email_account
	 */
	public function test_add_email_account_allows_distinct_addresses_and_rejects_duplicates(): void {

		$post_type = 'test_api_account';

		$settings = \Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( $post_type );
		$settings->allows( 'get_email_accounts_cpt_friendly_name' )->andReturn( 'Test API Accounts' );

		$cpt = new BH_Email_Account_CPT( $settings, $this->logger );
		$cpt->register_cpt();
		$cpt->register_post_statuses();

		$account_repository = new Email_Account_WP_Post_Repository(
			$post_type,
			new BH_Email_Account_Factory( $this->logger ),
			$this->logger,
		);

		$api = $this->get_api( settings: $settings, email_account_repository: $account_repository );

		$first = $api->add_email_account( 'first@example.com', 'First', 'SomeConnection', null, null, null, null );
		// A second, distinct account must be allowed even though one already exists.
		$second = $api->add_email_account( 'second@example.com', 'Second', 'SomeConnection', null, null, null, null );

		$this->assertSame( 'first@example.com', $first->email_address );
		$this->assertSame( 'second@example.com', $second->email_address );

		// A genuine duplicate is still rejected.
		$this->expectException( \Exception::class );
		$api->add_email_account( 'first@example.com', 'Dup', 'SomeConnection', null, null, null, null );
	}
}
