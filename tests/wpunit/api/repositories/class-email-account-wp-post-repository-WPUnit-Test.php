<?php
/**
 * WPUnit tests for Email_Account_WP_Post_Repository: save/retrieve/query/update round-trips and the
 * email-address immutability rule.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories;

use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
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
		$settings->allows( 'get_rest_namespace' )->andReturn( null );

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
			connection_type_class: 'SomeConnection',
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
		$this->assertSame( 'SomeConnection', $reloaded->connection_type_class );
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
	 * Regression: the query did not set posts_per_page, so WP_Query's default page size (10) silently
	 * dropped accounts once more than ten existed.
	 *
	 * @covers ::get_all
	 */
	public function test_get_all_returns_more_than_ten_accounts(): void {
		$sut = $this->make_sut();

		for ( $i = 1; $i <= 12; $i++ ) {
			$this->save_account( $sut, "account-{$i}@example.com" );
		}

		$this->assertCount( 12, $sut->get_all() );
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

	/**
	 * A new account starts with both lifetime totals at zero, stored explicitly as meta.
	 *
	 * @covers ::save_new
	 */
	public function test_save_new_initialises_lifetime_totals_to_zero(): void {
		$sut = $this->make_sut();

		$saved = $this->save_account( $sut );

		$this->assertSame( 0, $saved->total_emails_downloaded_count );
		$this->assertSame( 0, $saved->total_emails_saved_count );
		$this->assertSame( '0', get_post_meta( $saved->get_post_id(), 'total_emails_downloaded_count', true ) );
		$this->assertSame( '0', get_post_meta( $saved->get_post_id(), 'total_emails_saved_count', true ) );
	}

	/**
	 * The lifetime totals can be raised and persist.
	 *
	 * @covers ::update
	 */
	public function test_update_raises_lifetime_totals(): void {
		$sut = $this->make_sut();

		$saved = $this->save_account( $sut );

		$updated = $sut->update( $saved, total_emails_downloaded_count: 12, total_emails_saved_count: 7 );
		$this->assertSame( 12, $updated->total_emails_downloaded_count );
		$this->assertSame( 7, $updated->total_emails_saved_count );

		$updated = $sut->update( $updated, total_emails_downloaded_count: 30 );
		$this->assertSame( 30, $updated->total_emails_downloaded_count );
		$this->assertSame( 7, $updated->total_emails_saved_count, 'A total not passed is left unchanged.' );

		$reloaded = $sut->find_by_post_id( $saved->get_post_id() );
		$this->assertSame( 30, $reloaded->total_emails_downloaded_count );
		$this->assertSame( 7, $reloaded->total_emails_saved_count );
	}

	/**
	 * The lifetime totals are a historical record: an attempt to lower one is ignored and logged.
	 *
	 * @covers ::update
	 */
	public function test_update_refuses_to_lower_lifetime_totals(): void {
		$sut = $this->make_sut();

		$saved   = $this->save_account( $sut );
		$updated = $sut->update( $saved, total_emails_downloaded_count: 20, total_emails_saved_count: 10 );

		$updated = $sut->update( $updated, total_emails_downloaded_count: 5, total_emails_saved_count: 10, status: 'bh_email_ac_inactive' );

		$this->assertSame( 20, $updated->total_emails_downloaded_count, 'The lower value is ignored.' );
		$this->assertSame( 10, $updated->total_emails_saved_count );
		$this->assertFalse( $updated->is_active(), 'The rest of the update still applies.' );
		$this->assertTrue( $this->logger->hasWarningThatContains( 'Ignoring attempt to lower total_emails_downloaded_count from 20 to 5' ) );
	}

	/**
	 * Create an account post the way the repository would, then strip the given meta to simulate a
	 * post written by an older version or by a consumer directly.
	 *
	 * @param Email_Account_WP_Post_Repository $sut           The repository.
	 * @param string                           $email_address The account address.
	 * @param string[]                         $strip         Meta keys to delete.
	 */
	private function save_account_missing_meta( Email_Account_WP_Post_Repository $sut, string $email_address, array $strip ): int {
		$saved = $this->save_account( $sut, $email_address );
		foreach ( $strip as $key ) {
			delete_post_meta( $saved->get_post_id(), $key );
		}
		return $saved->get_post_id();
	}

	/**
	 * Looking an account up by address heals a post missing its email_address meta (the slug matched
	 * that exact address) and defaults the display name, instead of throwing.
	 *
	 * @covers ::find_by_email_address
	 */
	public function test_find_by_email_address_heals_missing_identity_meta(): void {
		$sut = $this->make_sut();

		$post_id = $this->save_account_missing_meta( $sut, 'legacy@example.com', array( 'email_address', 'display_name' ) );
		$this->assertSame( '', get_post_meta( $post_id, 'email_address', true ) );

		$account = $sut->find_by_email_address( 'legacy@example.com' );

		$this->assertInstanceOf( BH_Email_Account::class, $account );
		$this->assertSame( $post_id, $account->get_post_id() );
		$this->assertSame( 'legacy@example.com', $account->email_address );
		$this->assertSame( 'legacy@example.com', $account->display_name, 'Display name defaults to the address.' );
		$this->assertSame( 'legacy@example.com', get_post_meta( $post_id, 'email_address', true ), 'The address meta is restored.' );
		$this->assertSame( 'legacy@example.com', get_post_meta( $post_id, 'display_name', true ), 'The display name meta is written.' );
		$this->assertTrue( $this->logger->hasWarningThatContains( 'had no email_address meta; restored "legacy@example.com" from the lookup' ) );

		// Once healed, every other path hydrates it too.
		$this->logger->reset();
		$this->assertSame( 'legacy@example.com', $sut->find_by_post_id( $post_id )->email_address );
		$this->assertFalse( $this->logger->hasWarningRecords(), 'No further healing needed.' );
	}

	/**
	 * A post missing its email_address meta that is reached any other way cannot be identified: it is
	 * logged and skipped, and the remaining accounts are still returned.
	 *
	 * @covers ::get_all
	 * @covers ::find_by_post_id
	 */
	public function test_broken_account_post_does_not_take_down_the_others(): void {
		$sut = $this->make_sut();

		$good_id   = $this->save_account( $sut, 'good@example.com' )->get_post_id();
		$broken_id = $this->save_account_missing_meta( $sut, 'broken@example.com', array( 'email_address' ) );

		$all = $sut->get_all();

		$this->assertSame( array( $good_id ), array_map( fn( BH_Email_Account $a ) => $a->get_post_id(), $all ) );
		$this->assertTrue( $this->logger->hasErrorThatContains( 'Error parsing post ' . $broken_id ) );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/email_address/' );
		$sut->find_by_post_id( $broken_id );
	}
}
