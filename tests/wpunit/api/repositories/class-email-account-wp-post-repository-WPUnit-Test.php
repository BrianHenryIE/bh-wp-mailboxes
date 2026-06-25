<?php
/**
 * WPUnit tests for Email_Account_WP_Post_Repository: save/retrieve/query/update round-trips and the
 * email-address immutability rule.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories;

use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Account_Factory;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account_CPT;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository
 */
class Email_Account_WP_Post_Repository_WPUnit_Test extends WPUnit_Testcase {

	/** @var string Account CPT slug used throughout this suite. */
	private string $post_type = 'test_account_cpt';

	protected function setUp(): void {
		parent::setUp();

		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( $this->post_type );
		$settings->allows( 'get_email_accounts_cpt_friendly_name' )->andReturn( 'Test Accounts' );

		$cpt = new BH_Email_Account_CPT( $settings, $this->logger );
		$cpt->register_cpt();
		$cpt->register_post_statuses();
	}

	/**
	 * Instantiate the repository under test.
	 */
	private function make_sut(): Email_Account_WP_Post_Repository {
		return new Email_Account_WP_Post_Repository(
			$this->post_type,
			new BH_Email_Account_Factory( $this->logger ),
			$this->logger,
		);
	}

	/**
	 * Save a fully-specified account.
	 *
	 * NB: the regex filter here is intentionally backslash-free — WordPress unslashes meta on save,
	 * so a value like `/@example\.com$/` would lose its backslash (a separate known issue).
	 *
	 * @param Email_Account_WP_Post_Repository $sut           The repository under test.
	 * @param string                           $email_address The account email address.
	 */
	private function save_account( Email_Account_WP_Post_Repository $sut, string $email_address = 'inbox@example.com' ) {
		return $sut->save_new(
			email_address: $email_address,
			display_name: 'Test Inbox',
			provider_type_class: 'SomeConnection',
			from_address_regex_filter: '/@example.com$/',
			body_identifier_regex_filter: null,
			after_download_remote_email_action: 'mark_read',
			delete_local_emails_after_n_days: 30,
		);
	}

	/**
	 * Saving an account persists every field; find_by_post_id() rehydrates it. New accounts are active.
	 *
	 * @covers ::save_new
	 * @covers ::find_by_post_id
	 */
	public function test_save_new_round_trips_via_find_by_post_id(): void {
		$sut = $this->make_sut();

		$saved = $this->save_account( $sut );

		$reloaded = $sut->find_by_post_id( $saved->get_post_id() );

		$this->assertSame( 'inbox@example.com', $reloaded->email_address );
		$this->assertSame( 'Test Inbox', $reloaded->display_name );
		$this->assertSame( 'SomeConnection', $reloaded->provider_type_class );
		$this->assertSame( '/@example.com$/', $reloaded->from_address_regex_filter );
		$this->assertSame( 'mark_read', $reloaded->after_download_remote_email_action );
		$this->assertSame( 30, $reloaded->delete_local_emails_after_n_days );
		$this->assertTrue( $reloaded->is_active(), 'A newly-saved account should be active.' );
	}

	/**
	 * All saved accounts are returned by get_all().
	 *
	 * @covers ::get_all
	 */
	public function test_get_all_returns_all_saved_accounts(): void {
		$sut = $this->make_sut();

		$this->save_account( $sut, 'one@example.com' );
		$this->save_account( $sut, 'two@example.com' );

		$addresses = array_map( fn( $account ) => $account->email_address, $sut->get_all() );

		$this->assertCount( 2, $addresses );
		$this->assertContains( 'one@example.com', $addresses );
		$this->assertContains( 'two@example.com', $addresses );
	}

	/**
	 * Returns the matching account via its slug, or null when none exists.
	 *
	 * @covers ::find_by_email_address
	 */
	public function test_find_by_email_address(): void {
		$sut = $this->make_sut();

		$saved = $this->save_account( $sut, 'lookup@example.com' );

		$found = $sut->find_by_email_address( 'lookup@example.com' );
		$this->assertNotNull( $found );
		$this->assertSame( $saved->get_post_id(), $found->get_post_id() );

		$this->assertNull( $sut->find_by_email_address( 'absent@example.com' ) );
	}

	/**
	 * The account slug is URL-encoded so distinguishing characters survive sanitisation.
	 *
	 * @covers ::save_new
	 */
	public function test_save_new_url_encodes_the_slug(): void {
		$sut = $this->make_sut();

		$saved = $this->save_account( $sut, 'a.b+tag@example.com' );

		$slug = get_post( $saved->get_post_id() )->post_name;
		$this->assertStringContainsString( '%40', $slug, 'The @ should be URL-encoded in the slug.' );
		$this->assertStringContainsString( '%2b', $slug, 'The + should be URL-encoded in the slug.' );
		// The raw address is retained in meta.
		$this->assertSame( 'a.b+tag@example.com', $saved->email_address );
	}

	/**
	 * Saving a duplicate address throws and creates no second account: save_new() enforces address
	 * uniqueness in code, so no "-2"-suffixed slug arises.
	 *
	 * @covers ::save_new
	 * @covers ::find_by_email_address
	 */
	public function test_save_new_throws_for_duplicate_email_address(): void {
		$sut = $this->make_sut();

		$first = $this->save_account( $sut, 'dupe@example.com' );

		$threw = false;
		try {
			$this->save_account( $sut, 'dupe@example.com' );
		} catch ( \Exception $e ) {
			$threw = true;
		}

		$this->assertTrue( $threw, 'Saving a duplicate address must throw.' );

		// No second (e.g. "-2"-suffixed) account was created.
		$all = $sut->get_all();
		$this->assertCount( 1, $all );
		$this->assertSame( $first->get_post_id(), $all[0]->get_post_id() );
	}

	/**
	 * Filtering by email address returns only the matching account, even when others exist.
	 *
	 * Regression: the query mapped email_address to `post_name`/`meta_input`, which WP_Query ignores, so
	 * every account was returned once any existed (and add_email_account()'s dedup check false-positived).
	 *
	 * @covers ::query
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Queries\WP_Post_Query_Abstract::to_wp_query_args
	 */
	public function test_query_by_email_address_returns_only_matching_account(): void {
		$sut = $this->make_sut();

		$this->save_account( $sut, 'one@example.com' );
		$this->save_account( $sut, 'two@example.com' );

		$result = $sut->query( email_address: 'two@example.com' );

		$this->assertCount( 1, $result );
		$this->assertSame( 'two@example.com', $result[0]->email_address );
	}

	/**
	 * A query for an address that does not exist returns nothing, even when other accounts exist.
	 *
	 * @covers ::query
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Queries\WP_Post_Query_Abstract::to_wp_query_args
	 */
	public function test_query_by_unknown_email_address_returns_empty(): void {
		$sut = $this->make_sut();

		$this->save_account( $sut, 'one@example.com' );

		$result = $sut->query( email_address: 'absent@example.com' );

		$this->assertCount( 0, $result );
	}

	/**
	 * Updating an account cannot change its email address — update() has no such parameter, so a
	 * config update leaves the address unchanged while applying the other change.
	 *
	 * @covers ::update
	 */
	public function test_update_cannot_change_email_address(): void {
		$sut = $this->make_sut();

		$saved = $this->save_account( $sut, 'immutable@example.com' );

		$sut->update( $saved, display_name: 'Renamed Inbox' );

		$reloaded = $sut->find_by_post_id( $saved->get_post_id() );
		$this->assertSame( 'immutable@example.com', $reloaded->email_address, 'The email address must not change.' );
		$this->assertSame( 'Renamed Inbox', $reloaded->display_name );
	}

	/**
	 * Updating the status changes the account from active to inactive.
	 *
	 * @covers ::update
	 */
	public function test_update_changes_status(): void {
		$sut = $this->make_sut();

		$saved = $this->save_account( $sut );
		$this->assertTrue( $saved->is_active() );

		$sut->update( $saved, status: 'bh_email_ac_inactive' );

		$reloaded = $sut->find_by_post_id( $saved->get_post_id() );
		$this->assertFalse( $reloaded->is_active(), 'The account should be inactive after the status update.' );
	}
}
