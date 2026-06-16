<?php
/**
 * Tests BH_Email_Account_Factory::from_wp_post() — hydrating a BH_Email_Account from a WP_Post and
 * its meta, including the required-field and datetime validation that throws.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories\Factories;

use BrianHenryIE\WP_Mailboxes\BH_Email_Account_CPT;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Mockery;
use WP_Post;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\Repositories\Factories\BH_Email_Account_Factory
 */
class BH_Email_Account_Factory_WPUnit_Test extends WPUnit_Testcase {

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
	 * The three required metas. Backslash-free provider string avoids WP meta unslashing — the factory
	 * does not validate it is a real class.
	 *
	 * @return array<string,string>
	 */
	private function required_meta(): array {
		return array(
			'provider_type_class' => 'Some_Provider_Class',
			'email_address'       => 'inbox@example.com',
			'display_name'        => 'Test Inbox',
		);
	}

	/**
	 * Every meta field is hydrated onto the BH_Email_Account, with the post id/type/status from the post.
	 *
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
			)
		);

		$account = ( new BH_Email_Account_Factory( $this->logger ) )->from_wp_post( $post );

		$this->assertSame( $post->ID, $account->get_post_id() );
		$this->assertSame( $this->post_type, $account->post_type );
		$this->assertSame( 'bh_email_ac_active', $account->local_status );
		$this->assertTrue( $account->is_active() );
		$this->assertSame( 'Some_Provider_Class', $account->provider_type_class );
		$this->assertSame( 'inbox@example.com', $account->email_address );
		$this->assertSame( 'Test Inbox', $account->display_name );
		$this->assertSame( '/@example.com$/', $account->from_address_regex_filter );
		$this->assertSame( 'mark_read', $account->after_download_remote_email_action );
		$this->assertSame( 14, $account->delete_local_emails_after_n_days );
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
		unset( $meta['provider_type_class'] );
		$post = $this->make_account_post( $meta );

		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/provider_type_class/' );

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
			$this->assertStringContainsString( 'provider_type_class', $exception->getMessage() );
			$this->assertStringContainsString( 'email_address', $exception->getMessage() );
			$this->assertStringContainsString( 'display_name', $exception->getMessage() );
		}
	}

	/**
	 * A datetime meta that is not ATOM-formatted throws rather than passing a bad value to the model.
	 *
	 * @covers ::from_wp_post
	 */
	public function test_from_wp_post_throws_on_invalid_datetime(): void {

		$post = $this->make_account_post(
			$this->required_meta() + array( 'last_successful_login_time' => 'not-a-valid-datetime' )
		);

		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/last_successful_login_time/' );

		( new BH_Email_Account_Factory( $this->logger ) )->from_wp_post( $post );
	}
}
