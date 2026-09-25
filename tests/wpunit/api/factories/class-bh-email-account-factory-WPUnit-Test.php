<?php
/**
 * Tests BH_Email_Account_Factory::from_wp_post() — hydrating a BH_Email_Account from a WP_Post and
 * its meta, including the required-field and datetime validation that throws.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Factories;

use BrianHenryIE\WP_Mailboxes\BH_Email_Account_CPT;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Mockery;
use WP_Post;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Account_Factory
 */
class BH_Email_Account_Factory_WPUnit_Test extends WPUnit_Testcase {

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
	 * Create an account post with the given meta and return its WP_Post.
	 *
	 * @param array<string,string> $meta        Post meta key/value pairs.
	 * @param string               $post_status The post status (account local status).
	 */
	private function make_account_post( array $meta, string $post_status = 'bh_email_ac_active' ): WP_Post {
		$post_id = wp_insert_post(
			array(
				'post_type'   => $this->post_type,
				'post_status' => $post_status,
				'post_title'  => 'Account',
			)
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		return $post;
	}

	/**
	 * The three required metas. Backslash-free connection string avoids WP meta unslashing — the factory
	 * does not validate it is a real class.
	 *
	 * @return array<string,string>
	 */
	private function required_meta(): array {
		return array(
			'connection_type_class' => 'Some_Connection_Class',
			'email_address'         => 'inbox@example.com',
			'display_name'          => 'Test Inbox',
		);
	}

	/**
	 * Every meta field is hydrated onto the BH_Email_Account, with the post id/type/status from the post.
	 *
	 * @covers ::__construct
	 * @covers ::from_wp_post
	 * @covers ::get_array_from_post_meta
	 */
	public function test_from_wp_post_hydrates_all_fields(): void {

		$post = $this->make_account_post(
			$this->required_meta() + array(
				'from_address_regex_filter'          => '/@example.com$/',
				'body_identifier_regex_filter'       => '/order #\\d+/',
				'after_download_remote_email_action' => 'mark_read',
				'delete_local_emails_after_n_days'   => '14',
				'total_emails_downloaded_count'      => '250',
				'total_emails_saved_count'           => '75',
			)
		);

		$account = ( new BH_Email_Account_Factory( $this->logger ) )->from_wp_post( $post );

		$this->assertSame( $post->ID, $account->get_post_id() );
		$this->assertSame( $this->post_type, $account->post_type );
		$this->assertSame( 'bh_email_ac_active', $account->local_status );
		$this->assertTrue( $account->is_active() );
		$this->assertSame( 'Some_Connection_Class', $account->connection_type_class );
		$this->assertSame( 'inbox@example.com', $account->email_address );
		$this->assertSame( 'Test Inbox', $account->display_name );
		$this->assertSame( '/@example.com$/', $account->from_address_regex_filter );
		$this->assertSame( 'mark_read', $account->after_download_remote_email_action );
		$this->assertSame( 14, $account->delete_local_emails_after_n_days );
		$this->assertSame( 250, $account->total_emails_downloaded_count );
		$this->assertSame( 75, $account->total_emails_saved_count );
	}

	/**
	 * Accounts saved before the lifetime totals existed have no meta for them: they read as zero, not null.
	 *
	 * @covers ::from_wp_post
	 */
	public function test_from_wp_post_defaults_lifetime_totals_to_zero(): void {

		$account = ( new BH_Email_Account_Factory( $this->logger ) )->from_wp_post(
			$this->make_account_post( $this->required_meta() )
		);

		$this->assertSame( 0, $account->total_emails_downloaded_count );
		$this->assertSame( 0, $account->total_emails_saved_count );
	}


	/**
	 * With only the required meta present, the optional fields default to null.
	 *
	 * @covers ::from_wp_post
	 */
	public function test_from_wp_post_defaults_optional_fields_to_null(): void {

		$account = ( new BH_Email_Account_Factory( $this->logger ) )->from_wp_post(
			$this->make_account_post( $this->required_meta() )
		);

		$this->assertNull( $account->from_address_regex_filter );
		$this->assertNull( $account->body_identifier_regex_filter );
		$this->assertNull( $account->after_download_remote_email_action );
		$this->assertNull( $account->delete_local_emails_after_n_days );
		$this->assertNull( $account->last_checked_time );
		$this->assertNull( $account->last_successful_login_time );
		$this->assertNull( $account->last_failed_login_time );
	}

	/**
	 * ATOM-formatted datetime metas are parsed into DateTimeInterface objects.
	 *
	 * @covers ::from_wp_post
	 */
	public function test_from_wp_post_parses_atom_datetimes(): void {

		$checked = new DateTimeImmutable( '2026-01-15T10:20:30+00:00' );

		$account = ( new BH_Email_Account_Factory( $this->logger ) )->from_wp_post(
			$this->make_account_post(
				$this->required_meta() + array(
					'last_checked_time' => $checked->format( DateTimeInterface::ATOM ),
				)
			)
		);

		$this->assertInstanceOf( DateTimeInterface::class, $account->last_checked_time );
		$this->assertSame( $checked->getTimestamp(), $account->last_checked_time->getTimestamp() );
	}

	/**
	 * A missing required meta throws, and the exception names the offending key.
	 *
	 * @covers ::from_wp_post
	 * @covers ::get_array_from_post_meta
	 */
	public function test_from_wp_post_throws_when_required_meta_missing(): void {

		$meta = $this->required_meta();
		unset( $meta['connection_type_class'] );
		$post = $this->make_account_post( $meta );

		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/connection_type_class/' );

		( new BH_Email_Account_Factory( $this->logger ) )->from_wp_post( $post );
	}

	/**
	 * An account post with no meta lists every missing required key in the exception.
	 *
	 * @covers ::from_wp_post
	 */
	public function test_from_wp_post_throws_listing_all_missing_required(): void {

		$post = $this->make_account_post( array() );

		try {
			( new BH_Email_Account_Factory( $this->logger ) )->from_wp_post( $post );
			$this->fail( 'Expected an exception for missing required meta.' );
		} catch ( Exception $exception ) {
			$this->assertStringContainsString( 'connection_type_class', $exception->getMessage() );
			$this->assertStringContainsString( 'email_address', $exception->getMessage() );
			$this->assertStringContainsString( 'display_name', $exception->getMessage() );
		}
	}

	/**
	 * A datetime meta that is not ATOM-formatted is dropped with a warning; the account still hydrates.
	 *
	 * @covers ::from_wp_post
	 */
	public function test_from_wp_post_ignores_invalid_datetime(): void {

		$post = $this->make_account_post(
			$this->required_meta() + array(
				'last_successful_login_time' => 'not-a-valid-datetime',
				'last_checked_time'          => '2026-01-15T10:20:30+00:00',
			)
		);

		$account = ( new BH_Email_Account_Factory( $this->logger ) )->from_wp_post( $post );

		$this->assertNull( $account->last_successful_login_time );
		$this->assertInstanceOf( DateTimeInterface::class, $account->last_checked_time, 'Other timestamps are unaffected.' );
		$this->assertTrue( $this->logger->hasWarningThatContains( 'Discarding unusable last_successful_login_time "not-a-valid-datetime"' ) );
		$this->assertSame( '', get_post_meta( $post->ID, 'last_successful_login_time', true ), 'The bad meta is deleted.' );

		$this->logger->reset();
		( new BH_Email_Account_Factory( $this->logger ) )->from_wp_post( $post );
		$this->assertFalse( $this->logger->hasWarningRecords(), 'The warning does not repeat on the next load.' );
	}

	/**
	 * A non-numeric integer meta is logged, deleted from the database and read as null; the next load is silent.
	 *
	 * @covers ::from_wp_post
	 * @covers ::replace_unusable_meta
	 */
	public function test_from_wp_post_discards_non_numeric_int_meta(): void {

		$post = $this->make_account_post( $this->required_meta() + array( 'delete_local_emails_after_n_days' => 'seven' ) );

		$account = ( new BH_Email_Account_Factory( $this->logger ) )->from_wp_post( $post );

		$this->assertNull( $account->delete_local_emails_after_n_days );
		$this->assertTrue( $this->logger->hasWarningThatContains( 'Discarding unusable delete_local_emails_after_n_days "seven" on email account post ' . $post->ID . '; the meta has been deleted.' ) );
		$this->assertSame( '', get_post_meta( $post->ID, 'delete_local_emails_after_n_days', true ) );

		$this->logger->reset();
		( new BH_Email_Account_Factory( $this->logger ) )->from_wp_post( $post );
		$this->assertFalse( $this->logger->hasWarningRecords() );
	}

	/**
	 * Non-numeric or negative lifetime totals are logged, overwritten with zero and read as zero; the next load is silent.
	 *
	 * @covers ::from_wp_post
	 * @covers ::replace_unusable_meta
	 */
	public function test_from_wp_post_replaces_unusable_totals_with_zero(): void {

		$post = $this->make_account_post(
			$this->required_meta() + array(
				'total_emails_downloaded_count' => 'lots',
				'total_emails_saved_count'      => '-3',
			)
		);

		$account = ( new BH_Email_Account_Factory( $this->logger ) )->from_wp_post( $post );

		$this->assertSame( 0, $account->total_emails_downloaded_count );
		$this->assertSame( 0, $account->total_emails_saved_count );
		$this->assertTrue( $this->logger->hasWarningThatContains( 'Discarding unusable total_emails_downloaded_count "lots"' ) );
		$this->assertTrue( $this->logger->hasWarningThatContains( 'Discarding unusable total_emails_saved_count "-3" on email account post ' . $post->ID . '; replaced with 0.' ) );
		$this->assertSame( '0', get_post_meta( $post->ID, 'total_emails_downloaded_count', true ) );
		$this->assertSame( '0', get_post_meta( $post->ID, 'total_emails_saved_count', true ) );

		$this->logger->reset();
		( new BH_Email_Account_Factory( $this->logger ) )->from_wp_post( $post );
		$this->assertFalse( $this->logger->hasWarningRecords() );
	}

	/**
	 * Valid numbers and absent meta are never rewritten or logged.
	 *
	 * @covers ::from_wp_post
	 */
	public function test_from_wp_post_leaves_valid_numbers_alone(): void {

		$post = $this->make_account_post(
			$this->required_meta() + array(
				'delete_local_emails_after_n_days' => '14',
				'total_emails_downloaded_count'    => '250',
			)
		);

		$account = ( new BH_Email_Account_Factory( $this->logger ) )->from_wp_post( $post );

		$this->assertSame( 14, $account->delete_local_emails_after_n_days );
		$this->assertSame( 250, $account->total_emails_downloaded_count );
		$this->assertSame( 0, $account->total_emails_saved_count, 'Absent meta reads as zero.' );
		$this->assertSame( '', get_post_meta( $post->ID, 'total_emails_saved_count', true ), 'Absent meta is not written.' );
		$this->assertFalse( $this->logger->hasWarningRecords() );
	}

	/**
	 * Create an account post with an explicit slug (the URL-encoded address, as the repository writes it).
	 *
	 * @param array<string,string> $meta      Post meta key/value pairs.
	 * @param string               $post_name The slug.
	 */
	private function make_account_post_with_slug( array $meta, string $post_name ): WP_Post {
		$post_id = wp_insert_post(
			array(
				'post_type'   => $this->post_type,
				'post_status' => 'bh_email_ac_active',
				'post_title'  => 'Account',
				'post_name'   => $post_name,
			)
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		return $post;
	}

	/**
	 * A missing display name alone defaults to the (present) email address meta.
	 *
	 * @covers ::backfill_identity_meta
	 */
	public function test_from_wp_post_defaults_missing_display_name_to_address(): void {

		$meta = $this->required_meta();
		unset( $meta['display_name'] );

		$account = ( new BH_Email_Account_Factory( $this->logger ) )->from_wp_post( $this->make_account_post( $meta ) );

		$this->assertSame( 'inbox@example.com', $account->display_name );
		$this->assertSame( 'inbox@example.com', get_post_meta( $account->get_post_id(), 'display_name', true ) );
		$this->assertFalse( $this->logger->hasWarningThatContains( 'had no email_address meta' ), 'The address was present, so only the name is backfilled.' );
	}

	/**
	 * Present meta is never overwritten by the backfill.
	 *
	 * @covers ::backfill_identity_meta
	 */
	public function test_from_wp_post_does_not_overwrite_present_identity_meta(): void {

		$post = $this->make_account_post_with_slug( $this->required_meta(), rawurlencode( 'other@example.com' ) );

		$account = ( new BH_Email_Account_Factory( $this->logger ) )->from_wp_post( $post );

		$this->assertSame( 'inbox@example.com', $account->email_address );
		$this->assertSame( 'Test Inbox', $account->display_name );
		$this->assertFalse( $this->logger->hasWarningRecords() );
	}

	/**
	 * A missing email address cannot be defaulted (the slug is a lossy encoding), so the account still
	 * fails to hydrate, naming both missing keys.
	 *
	 * @covers ::backfill_identity_meta
	 */
	public function test_from_wp_post_still_throws_when_email_address_missing(): void {

		$post = $this->make_account_post_with_slug( array( 'connection_type_class' => 'Some_Connection_Class' ), rawurlencode( 'inbox@example.com' ) );

		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/email_address, display_name/' );

		( new BH_Email_Account_Factory( $this->logger ) )->from_wp_post( $post );
	}
}
