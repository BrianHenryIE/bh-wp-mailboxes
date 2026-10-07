<?php
/**
 * Behavioural contract for the per-account email counts, independent of how they are cached.
 *
 * After every way an email post can change within one request, the repository's counts must equal
 * an uncached count read straight from the posts table. The tests register no cache-invalidation
 * hooks and know nothing about cache keys, so the same file passes whether the counts are queried
 * every time, cached with hook-based invalidation, or cached on the posts "last changed" token.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories;

use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Mockery;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository
 */
class Email_Status_Counts_Consistency_WPUnit_Test extends WPUnit_Testcase {

	private const POST_TYPE = 'test_counts_emails';

	/**
	 * Mailbox settings naming the test CPT.
	 *
	 * @var BH_WP_Mailboxes_Settings_Interface
	 */
	protected BH_WP_Mailboxes_Settings_Interface $settings;

	/**
	 * The repository under test.
	 *
	 * @var Email_WP_Post_Repository
	 */
	protected Email_WP_Post_Repository $sut;

	protected function setUp(): void {
		parent::setUp();

		$this->settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$this->settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( self::POST_TYPE );
		$this->settings->allows( 'get_emails_cpt_friendly_name' )->andReturn( 'Counts Emails' );
		$this->settings->allows( 'get_rest_namespace' )->andReturn( null );
		$this->settings->allows( 'get_plugin_slug' )->andReturn( 'test-plugin' );

		$cpt = new BH_Email_CPT( $this->settings, $this->logger );
		$cpt->register_cpt();
		$cpt->register_post_statuses();
		// As in production: an untrashed email returns to its previous status, not WordPress's default draft.
		add_filter( 'wp_untrash_post_status', $cpt->restore_status_on_untrash( ... ), 10, 3 );

		$this->sut = new Email_WP_Post_Repository( self::POST_TYPE, new BH_Email_Factory( $this->logger ), $this->logger );
	}

	/**
	 * The oracle: per-status counts read directly from the posts table, bypassing any cache.
	 *
	 * @param int $account_post_id The account (the emails' post_parent).
	 *
	 * @return array{new:int, processed:int, saved:int, other:int}
	 */
	private function raw_counts( int $account_post_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The oracle must not be cached.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_status, COUNT(*) AS count FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'trash' AND post_parent = %d GROUP BY post_status",
				self::POST_TYPE,
				$account_post_id
			),
			ARRAY_A
		);

		$counts = array(
			'new'       => 0,
			'processed' => 0,
			'saved'     => 0,
			'other'     => 0,
		);
		foreach ( $rows as $row ) {
			$key = match ( $row['post_status'] ) {
				'bh_email_new'       => 'new',
				'bh_email_processed' => 'processed',
				'bh_email_saved'     => 'saved',
				default              => 'other',
			};
			$counts[ $key ] += (int) $row['count'];
		}
		return $counts;
	}

	/**
	 * Everything a caller can observe must match the oracle at this point in the request.
	 *
	 * @param BH_Email_Account $account The account.
	 * @param string           $step    Which lifecycle step just happened, for the failure message.
	 */
	private function assert_matches_oracle( BH_Email_Account $account, string $step ): void {
		$expected = $this->raw_counts( $account->get_post_id() );
		$actual   = $this->sut->count_by_status_for_account_email( $account );

		$this->assertSame(
			$expected,
			array(
				'new'       => $actual->new_count,
				'processed' => $actual->processed_count,
				'saved'     => $actual->saved_count,
				'other'     => $actual->other_count,
			),
			"Per-status counts differ from the database after: {$step}."
		);
		$this->assertSame( array_sum( $expected ), $actual->total(), "total() differs from the database after: {$step}." );
		$this->assertSame( array_sum( $expected ), $this->sut->count_for_account_email( $account ), "count_for_account_email() differs from the database after: {$step}." );
	}

	/**
	 * Insert an email post for the account directly, as the test factory does.
	 *
	 * @param int    $account_post_id The account.
	 * @param string $status          The post status.
	 * @param string $post_type       The post type (defaults to the emails CPT).
	 */
	private function insert( int $account_post_id, string $status, string $post_type = self::POST_TYPE ): int {
		return $this->factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_status' => $status,
				'post_parent' => $account_post_id,
			)
		);
	}

	/**
	 * Every way an email can change within a single request is reflected in the very next count.
	 *
	 * @covers ::count_by_status_for_account_email
	 * @covers ::count_for_account_email
	 */
	public function test_counts_match_the_database_after_every_lifecycle_step(): void {
		$account = BH_Email_Account_Fixture::make( post_id: 800, post_type: 'test_counts_accounts', email_address: 'counts@example.com' );

		$this->assert_matches_oracle( $account, 'nothing saved yet' );

		$direct_id = $this->insert( 800, 'bh_email_new' );
		$this->assert_matches_oracle( $account, 'a post inserted directly in bh_email_new' );

		// The repository's own write path, with a real parsed message.
		$parsed = ( new MailMimeParser() )->parse( (string) file_get_contents( codecept_root_dir( 'tests/_data/wpunit/test_save_new.eml' ) ), true );
		$saved  = $this->sut->save_new(
			new Fetched_Email( $parsed, new Remote_Email_Coordinates( message_id: $parsed->getMessageId() ?? '' ), false ),
			$this->settings,
			$account
		);
		$this->assert_matches_oracle( $account, 'save_new()' );

		wp_update_post(
			array(
				'ID'          => $direct_id,
				'post_status' => 'bh_email_processed',
			)
		);
		$this->assert_matches_oracle( $account, 'status changed to bh_email_processed' );

		wp_update_post(
			array(
				'ID'          => $direct_id,
				'post_status' => 'bh_email_saved',
			)
		);
		$this->assert_matches_oracle( $account, 'status changed to bh_email_saved' );

		update_post_meta( $direct_id, 'some_meta', 'changed' );
		$this->assert_matches_oracle( $account, 'a meta-only change (counts unchanged)' );

		wp_trash_post( $direct_id );
		$this->assert_matches_oracle( $account, 'wp_trash_post()' );

		wp_untrash_post( $direct_id );
		$this->assertSame( 'bh_email_saved', get_post_status( $direct_id ), 'Restored to its previous status (as the production filter does).' );
		$this->assert_matches_oracle( $account, 'wp_untrash_post()' );

		wp_delete_post( $saved->get_post_id(), true );
		$this->assert_matches_oracle( $account, 'wp_delete_post( …, true )' );

		$this->insert( 800, 'publish' );
		$this->assert_matches_oracle( $account, 'an email in a non-plugin status (counted as other)' );

		$this->insert( 800, 'publish', 'post' );
		$this->assert_matches_oracle( $account, 'a post of another type with the same post_parent (not counted)' );

		// What WP-CLI or another plugin might do: a direct table write, followed by the cache clear core
		// requires after any direct write (without it, core's own WP_Query cache would be stale too).
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Deliberately bypassing the WordPress API.
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'bh_email_new' ), array( 'ID' => $direct_id ) );
		clean_post_cache( $direct_id );
		$this->assert_matches_oracle( $account, 'a direct $wpdb->update() followed by clean_post_cache()' );
	}

	/**
	 * Two accounts' counts are independent: mutating one never changes the other's.
	 *
	 * @covers ::count_by_status_for_account_email
	 */
	public function test_accounts_are_counted_independently(): void {
		$first  = BH_Email_Account_Fixture::make( post_id: 810, post_type: 'test_counts_accounts', email_address: 'first@example.com' );
		$second = BH_Email_Account_Fixture::make( post_id: 811, post_type: 'test_counts_accounts', email_address: 'second@example.com' );

		$this->insert( 810, 'bh_email_new' );
		$this->insert( 811, 'bh_email_new' );
		$this->insert( 811, 'bh_email_processed' );

		$this->assert_matches_oracle( $first, 'initial' );
		$this->assert_matches_oracle( $second, 'initial' );
		$this->assertSame( 1, $this->sut->count_by_status_for_account_email( $first )->total() );
		$this->assertSame( 2, $this->sut->count_by_status_for_account_email( $second )->total() );

		$second_email = $this->insert( 811, 'bh_email_saved' );
		$this->assertSame( 1, $this->sut->count_by_status_for_account_email( $first )->total(), 'Unaffected by another account\'s new email.' );
		$this->assertSame( 3, $this->sut->count_by_status_for_account_email( $second )->total() );

		wp_trash_post( $second_email );
		$this->assertSame( 1, $this->sut->count_by_status_for_account_email( $first )->total(), 'Unaffected by another account\'s trash.' );
		$this->assertSame( 2, $this->sut->count_by_status_for_account_email( $second )->total() );
		$this->assert_matches_oracle( $first, 'after the other account changed' );
		$this->assert_matches_oracle( $second, 'after its own changes' );
	}
}
