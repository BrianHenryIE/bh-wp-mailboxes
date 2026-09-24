<?php

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Credentials_Store_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Check_Mailbox_Result;
use BrianHenryIE\WP_Mailboxes\API\Factories\New_Email_Factory;
use BrianHenryIE\WP_Mailboxes\Models\BH_WP_Mailboxes_Settings_Fixture;
use Illuminate\Support\Collection;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Fixture;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Gmail_Email_Connection;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Google_API_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Connection;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use BrianHenryIE\WP_Private_Uploads\API\API as Private_Uploads;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Mockery;
use Psr\Log\LoggerInterface;
use WP_Mock;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\IMessage;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\API
 */
class API_Unit_Test extends Unit_Testcase {

	/**
	 * What the credentials store answers for any account (set per test with {@see store_credentials()}).
	 *
	 * @var mixed
	 */
	protected mixed $stored_credentials = null;

	/**
	 * The account the store was last asked about, for argument assertions.
	 *
	 * @var ?BH_Email_Account
	 */
	protected ?BH_Email_Account $credentials_requested_for = null;

	protected function get_api(
		?BH_WP_Mailboxes_Settings_Interface $settings = null,
		?Email_WP_Post_Repository $email_repository = null,
		?Email_Account_WP_Post_Repository $email_account_repository = null,
		?Private_Uploads $private_uploads = null,
		?LoggerInterface $logger = null,
		?Credentials_Store_Interface $credentials_store = null,
	): API {
		return new API(
			$settings ?? BH_WP_Mailboxes_Settings_Fixture::make(),
			$email_repository ?? \Mockery::mock( Email_WP_Post_Repository::class ),
			$email_account_repository ?? \Mockery::mock( Email_Account_WP_Post_Repository::class ),
			new New_Email_Factory(),
			$private_uploads ?? \Mockery::mock( Private_Uploads::class ),
			$logger ?? $this->logger,
			$credentials_store ?? $this->make_credentials_store(),
		);
	}

	/**
	 * A credentials store that answers {@see $stored_credentials} for every account and records the account asked for.
	 */
	protected function make_credentials_store(): Credentials_Store_Interface {
		$store = Mockery::mock( Credentials_Store_Interface::class );
		$store->allows( 'is_available' )->andReturn( true );
		$store->allows( 'get' )->andReturnUsing(
			function ( BH_Email_Account $account ) {
				$this->credentials_requested_for = $account;
				return $this->stored_credentials;
			}
		);
		$store->allows( 'save' );
		$store->allows( 'delete' );
		return $store;
	}

	/**
	 * What the credentials store returns for every account in this test (the previous filter's reply).
	 *
	 * @param mixed $credentials Usually an Account_Credentials_Interface mock, or null.
	 */
	protected function store_credentials( mixed $credentials ): void {
		$this->stored_credentials = $credentials;
	}

	/**
	 * The most simple test... no mailboxes configured.
	 * Verify the response is formatted as expected.
	 *
	 * @covers ::check_email
	 * @covers ::__construct
	 */
	public function test_check_email_no_mailboxes_configured(): void {

		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class )->shouldIgnoreMissing();

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array() );

		$sut = $this->get_api( settings: $settings, email_account_repository: $email_account_repository );

		$result = $sut->check_email();

		$this->assertInstanceOf( Check_Mailbox_Result::class, $result );
		$this->assertTrue( $result->success );
		$this->assertSame( array(), $result->get_emails() );
	}


	/**
	 * Test recent failed login returns early.
	 *
	 * @covers ::check_email
	 * @covers ::__construct
	 */
	public function test_check_email_recent_failed_login(): void {

		$email_account_fixture = BH_Email_Account_Fixture::make(
			last_failed_login_time: new \DateTimeImmutable(),
		);

		$credentials = Mockery::mock( Account_Credentials_Interface::class );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account_fixture ) );

		$sut = $this->get_api( email_account_repository: $email_account_repository );

		WP_Mock::userFunction(
			'get_option',
			array(
				'args'   => array( 'plugin-slug_mailbox_last_fetched_Dummy Account', null ),
				'return' => new DateTime()->format( DateTime::ATOM ),
			)
		);

		WP_Mock::userFunction(
			'get_option',
			array(
				'args'   => array( 'plugin-slug_mailbox_last_failure_Dummy Account', null ),
				'return' => new DateTime()->format( DateTime::ATOM ),
			)
		);

		$this->store_credentials( $credentials );

		WP_Mock::userFunction( 'wp_doing_cron' )->andReturnTrue();

		$result = $sut->check_email();

		$this->assertTrue( $this->logger->hasInfoThatContains( 'Too soon after failed login' ) );

		// Rate-limiting is a deliberate skip, not a failure: the overall check still succeeds.
		$this->assertTrue( $result->success );
		$this->assertCount( 1, $result->get_skipped() );
		$this->assertSame( array(), $result->get_failures() );
		$this->assertTrue( $result->account_results[0]->skipped );
		$this->assertStringContainsString( 'less than four hours ago', (string) $result->account_results[0]->message );
	}

	/**
	 * @covers ::get_last_fetched_times
	 */
	public function test_get_last_fetched_times(): void {

		$datetime = new \DateTimeImmutable();

		$email_account_fixture = BH_Email_Account_Fixture::make(
			email_address: 'brianhenryie@gmail.com',
			last_successful_login_time: $datetime,
		);

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account_fixture ) );

		$sut = $this->get_api( email_account_repository: $email_account_repository );

		$result = $sut->get_last_fetched_times();

		$this->assertArrayHasKey( 'brianhenryie@gmail.com', $result );
		// Assert there is less than one second difference between the two. (because saving as ATOM time loses the microseconds).

		/** @var DateTime $result_datetime */
		$result_datetime = $result['brianhenryie@gmail.com'];
		$difference      = $result_datetime->format( 'U' ) - $datetime->format( 'U' );
		$this->assertEquals( 0, $difference );
	}

	/**
	 * Getter test... pretty straightforward.
	 *
	 * @covers ::get_settings
	 */
	public function test_get_settings(): void {

		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class )->shouldIgnoreMissing();

		$sut = $this->get_api( settings: $settings );

		$result = $sut->get_settings();

		$this->assertInstanceOf( BH_WP_Mailboxes_Settings_Interface::class, $result );
		$this->assertEquals( $settings, $result );
	}

	/**
	 * Inactive accounts must be silently skipped.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_skips_inactive_account(): void {

		$email_account = BH_Email_Account_Fixture::make( local_status: 'bh_email_ac_inactive' );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );

		$sut = $this->get_api( email_account_repository: $email_account_repository );

		$result = $sut->check_email();

		$this->assertTrue( $this->logger->hasDebugThatContains( 'Skipping inactive email account' ) );
		$this->assertSame( array(), $result->get_emails() );

		$this->assertTrue( $result->success );
		$this->assertCount( 1, $result->get_skipped() );
		$this->assertSame( 'The account is disabled.', $result->account_results[0]->message );
	}

	/**
	 * When the credentials filter returns null the account must be skipped with a warning.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_skips_account_when_no_credentials(): void {

		$email_account = BH_Email_Account_Fixture::make();

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );

		$this->store_credentials( null );

		$sut    = $this->get_api( email_account_repository: $email_account_repository );
		$result = $sut->check_email();

		$this->assertTrue( $this->logger->hasWarningThatContains( 'No credentials found' ) );

		// Missing credentials on an account that should be fetched is a failure the user must fix.
		$this->assertFalse( $result->success );
		$this->assertCount( 1, $result->get_failures() );
		$this->assertTrue( $result->account_results[0]->is_failure() );
		$this->assertSame( 'No credentials are saved for this account.', $result->account_results[0]->message );
	}

	/**
	 * When no known connection class matches, a warning is logged and the account is skipped.
	 *
	 * @covers ::check_email
	 * @covers ::get_connection_for_email_account
	 */
	public function test_check_email_skips_account_when_no_fetcher_found(): void {

		$email_account = BH_Email_Account_Fixture::make( connection_type_class: 'Unknown\\Connection\\Class' );

		$credentials = Mockery::mock( Account_Credentials_Interface::class );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );

		$this->store_credentials( $credentials );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( null );

		$sut    = $this->get_api( email_account_repository: $email_account_repository );
		$result = $sut->check_email();

		$this->assertTrue( $this->logger->hasWarningThatContains( 'No fetcher found' ) );

		$this->assertFalse( $result->success );
		$this->assertCount( 1, $result->get_failures() );
		$this->assertSame( 'No connection is available for this account type (Unknown\\Connection\\Class).', $result->account_results[0]->message );
	}

	/**
	 * An old failed login (> 4 hours ago) must not block the fetch.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_does_not_skip_when_failed_login_is_old(): void {

		$five_hours_ago   = new DateTimeImmutable( '-5 hours' );
		$email_account    = BH_Email_Account_Fixture::make( last_failed_login_time: $five_hours_ago );
		$credentials      = Mockery::mock( Account_Credentials_Interface::class );
		$connection       = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$settings         = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'               => 'test-plugin',
				'get_emails_cpt_underscored_20' => 'test_email',
			)
		)->shouldIgnoreMissing();

		$connection->expects( 'retrieve_emails' )->andReturn( new Collection() );
		$email_repository->expects( 'save_all' )->andReturn( array() );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 )->once();

		$this->store_credentials( $credentials );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_email', $email_account )
				->reply( $connection );

		$sut    = $this->get_api( settings: $settings, email_repository: $email_repository, email_account_repository: $email_account_repository );
		$result = $sut->check_email();

		$this->assertFalse( $this->logger->hasInfoThatContains( 'Too soon after failed login' ) );
		$this->assertTrue( $result->success );
	}

	/**
	 * A fetch exception must be logged as an error; remaining accounts continue to be processed.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_logs_error_on_fetch_exception(): void {

		$email_account = BH_Email_Account_Fixture::make();
		$credentials   = Mockery::mock( Account_Credentials_Interface::class );
		$connection    = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$settings      = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'               => 'test-plugin',
				'get_emails_cpt_underscored_20' => 'test_emails',
			)
		)->shouldIgnoreMissing();

		$connection->expects( 'retrieve_emails' )->andThrow( new \Exception( 'Connection refused' ) );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 )->once();

		$this->store_credentials( $credentials );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( $connection );

		$sut    = $this->get_api( settings: $settings, email_account_repository: $email_account_repository );
		$result = $sut->check_email();

		$this->assertTrue( $this->logger->hasErrorThatContains( 'Error fetching emails' ) );

		// The connection's own message is surfaced so the user can tell a bad password from a bad hostname.
		$this->assertFalse( $result->success );
		$this->assertCount( 1, $result->get_failures() );
		$this->assertFalse( $result->account_results[0]->skipped );
		$this->assertSame( 'Could not fetch emails: Connection refused', $result->account_results[0]->message );
	}

	/**
	 * One failing account must mark the whole check as failed without hiding the other accounts' results.
	 *
	 * @covers ::check_email
	 * @covers ::check_email_for_account
	 */
	public function test_check_email_reports_one_failure_among_several_accounts(): void {

		$good_account = BH_Email_Account_Fixture::make( email_address: 'good@example.com', display_name: 'Good' );
		$bad_account  = BH_Email_Account_Fixture::make( email_address: 'bad@example.com', display_name: 'Bad' );
		$credentials  = Mockery::mock( Account_Credentials_Interface::class );
		$settings     = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'               => 'test-plugin',
				'get_emails_cpt_underscored_20' => 'test_emails',
			)
		)->shouldIgnoreMissing();

		$good_connection = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$good_connection->expects( 'retrieve_emails' )->andReturn( new Collection() );
		$bad_connection = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$bad_connection->expects( 'retrieve_emails' )->andThrow( new \Exception( 'AUTHENTICATIONFAILED' ) );

		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$email_repository->expects( 'save_all' )->andReturn( array() );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $good_account, $bad_account ) );
		$email_account_repository->allows( 'update' )->andReturnArg( 0 );

		$this->store_credentials( $credentials );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $good_account )
				->reply( $good_connection );
		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $bad_account )
				->reply( $bad_connection );

		$sut    = $this->get_api( settings: $settings, email_repository: $email_repository, email_account_repository: $email_account_repository );
		$result = $sut->check_email();

		$this->assertFalse( $result->success );
		$this->assertCount( 2, $result->account_results );
		$this->assertTrue( $result->account_results[0]->success );
		$this->assertNull( $result->account_results[0]->message );
		$this->assertCount( 1, $result->get_failures() );
		$this->assertSame( $bad_account, $result->get_failures()[0]->bh_account );
		$this->assertSame( 'Could not fetch emails: AUTHENTICATIONFAILED', $result->get_failures()[0]->message );
	}

	/**
	 * A post-download action that fails does not fail the check (the emails are already saved), but the
	 * problem is reported as a warning on the result.
	 *
	 * @covers ::fetch_for_account
	 */
	public function test_check_email_reports_failed_post_download_action_as_warning(): void {

		$email_account = BH_Email_Account_Fixture::make( after_download_remote_email_action: 'mark_read' );
		$credentials   = Mockery::mock( Account_Credentials_Interface::class );
		$connection    = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$settings      = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'               => 'test-plugin',
				'get_emails_cpt_underscored_20' => 'test_emails',
			)
		)->shouldIgnoreMissing();

		$connection->expects( 'retrieve_emails' )->andReturn( new Collection() );

		$saved_bh_email = BH_Email_Fixture::new( post_id: 42, post_type: 'test_emails', email_account_local_id: 2, imessage: Mockery::mock( \ZBateson\MailMimeParser\IMessage::class ), message_id: 'm@example.org', subject: 'Hi', from_email: 'a@example.org' );

		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$email_repository->expects( 'save_all' )->andReturn( array( $saved_bh_email ) );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );
		$email_account_repository->allows( 'update' )->andReturnArg( 0 );

		$this->store_credentials( $credentials );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( $connection );

		// mark_email_read() needs the email's account; the repository failing to find it makes the action throw.
		$email_account_repository->allows( 'find_by_post_id' )->andThrow( new \Exception( 'Post not found.' ) );
		WP_Mock::passthruFunction( 'esc_html' );

		$sut = $this->get_api( settings: $settings, email_repository: $email_repository, email_account_repository: $email_account_repository );

		$result = $sut->check_email();

		$this->assertTrue( $result->success );
		$this->assertTrue( $this->logger->hasWarningThatContains( 'Post-download action "mark_read" failed' ) );
		$this->assertSame(
			array( 'Post-download action "mark_read" failed for email 42: failed to get BH_Email_Account for email test_emails' ),
			$result->account_results[0]->warnings
		);
	}

	/**
	 * A receive-only connection (no {@see Supports_Fetching}) cannot be polled, so the fetch is skipped.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_skips_connection_without_fetching_support(): void {

		$email_account = BH_Email_Account_Fixture::make();
		// A receive-only connection implements Email_Connection_Interface but NOT Supports_Fetching.
		$connection = Mockery::mock( Email_Connection_Interface::class );
		$settings   = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'               => 'test-plugin',
				'get_emails_cpt_underscored_20' => 'test_emails',
			)
		)->shouldIgnoreMissing();

		// retrieve_emails must never be called on a non-fetching connection.
		$connection->shouldNotReceive( 'retrieve_emails' );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( $connection );

		$sut    = $this->get_api( settings: $settings, email_account_repository: $email_account_repository );
		$result = $sut->check_email();

		$this->assertTrue( $result->success );
		$this->assertSame( array(), $result->get_emails() );
		$this->assertTrue( $this->logger->hasDebugThatContains( 'does not support fetching' ) );
		$this->assertCount( 1, $result->get_skipped() );
		$this->assertStringContainsString( 'nothing to fetch', (string) $result->account_results[0]->message );
	}

	/**
	 * A fetch exception must record `last_failed_login_time` on the account so the four-hour
	 * backoff engages on subsequent cron runs.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_records_failed_login_time_on_fetch_exception(): void {

		$email_account = BH_Email_Account_Fixture::make();
		$credentials   = Mockery::mock( Account_Credentials_Interface::class );
		$connection    = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$settings      = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'               => 'test-plugin',
				'get_emails_cpt_underscored_20' => 'test_emails',
			)
		)->shouldIgnoreMissing();

		$connection->expects( 'retrieve_emails' )->andThrow( new \Exception( 'AUTHENTICATIONFAILED' ) );

		/**
		 * On a fetch failure `update()` is called once; the mock declares the full signature, so the
		 * named `last_failed_login_time` argument binds to its declared position (index 10).
		 */
		$captured_update_args = array();

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );
		$email_account_repository->expects( 'update' )->andReturnUsing(
			function ( ...$args ) use ( &$captured_update_args, $email_account ) {
				$captured_update_args[] = $args;
				return $email_account;
			}
		)->once();

		$this->store_credentials( $credentials );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( $connection );

		$sut = $this->get_api( settings: $settings, email_account_repository: $email_account_repository );
		$sut->check_email();

		$this->assertNotEmpty( $captured_update_args, 'Email_Account_WP_Post_Repository::update() was not called.' );
		$this->assertSame( $email_account, $captured_update_args[0][0] );

		$last_failed_login_time = $captured_update_args[0][10] ?? null;
		$this->assertInstanceOf( DateTimeInterface::class, $last_failed_login_time );
		$this->assertEqualsWithDelta( time(), $last_failed_login_time->getTimestamp(), 60 );
	}

	/**
	 * On first run (no recorded last_successful_login_time) the fetcher must be asked for
	 * one week of email, not everything in the mailbox.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_first_run_uses_one_week_lookback(): void {

		$email_account    = BH_Email_Account_Fixture::make( last_successful_login_time: null );
		$credentials      = Mockery::mock( Account_Credentials_Interface::class );
		$connection       = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$settings         = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'               => 'test-plugin',
				'get_emails_cpt_underscored_20' => 'test_emails',
			)
		)->shouldIgnoreMissing();

		$captured_since_datetime = null;

		$connection->expects( 'retrieve_emails' )->andReturnUsing(
			function ( DateTimeInterface $since_datetime ) use ( &$captured_since_datetime ) {
				$captured_since_datetime = $since_datetime;
				return new Collection();
			}
		);
		$email_repository->expects( 'save_all' )->andReturn( array() );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 )->once();

		$this->store_credentials( $credentials );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( $connection );

		$sut = $this->get_api( settings: $settings, email_repository: $email_repository, email_account_repository: $email_account_repository );
		$sut->check_email();

		$this->assertInstanceOf( DateTimeInterface::class, $captured_since_datetime );

		$one_week_ago = ( new DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->sub( new \DateInterval( 'P1W' ) );
		$this->assertEqualsWithDelta( $one_week_ago->getTimestamp(), $captured_since_datetime->getTimestamp(), 60 );
	}

	/**
	 * When the account has a recorded last_successful_login_time, that exact time must be used
	 * as the fetch lookback, not the one-week default.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_uses_last_successful_login_time_as_since_datetime(): void {

		$last_successful_login_time = new DateTimeImmutable( '-2 days' );

		$email_account    = BH_Email_Account_Fixture::make( last_successful_login_time: $last_successful_login_time );
		$credentials      = Mockery::mock( Account_Credentials_Interface::class );
		$connection       = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$settings         = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'               => 'test-plugin',
				'get_emails_cpt_underscored_20' => 'test_emails',
			)
		)->shouldIgnoreMissing();

		$captured_since_datetime = null;

		$connection->expects( 'retrieve_emails' )->andReturnUsing(
			function ( DateTimeInterface $since_datetime ) use ( &$captured_since_datetime ) {
				$captured_since_datetime = $since_datetime;
				return new Collection();
			}
		);
		$email_repository->expects( 'save_all' )->andReturn( array() );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 )->once();

		$this->store_credentials( $credentials );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( $connection );

		$sut = $this->get_api( settings: $settings, email_repository: $email_repository, email_account_repository: $email_account_repository );
		$sut->check_email();

		$this->assertSame( $last_successful_login_time, $captured_since_datetime );
	}

	/**
	 * Emails whose account + Message-ID are already saved locally must be dropped before
	 * save_all() is called (dedup), while unseen emails pass through.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_drops_already_saved_emails_before_saving(): void {

		$email_account = BH_Email_Account_Fixture::make( email_address: 'test@example.org' );
		$credentials   = Mockery::mock( Account_Credentials_Interface::class );
		$connection    = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$settings      = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'               => 'test-plugin',
				'get_emails_cpt_underscored_20' => 'test_emails',
			)
		)->shouldIgnoreMissing();

		$already_saved_message = Mockery::mock( \ZBateson\MailMimeParser\IMessage::class );
		$already_saved_message->allows( 'getMessageId' )->andReturn( 'already-saved@example.org' );
		$already_saved_fetched_email = new Fetched_Email(
			message: $already_saved_message,
			coordinates: new Remote_Email_Coordinates( message_id: 'already-saved@example.org' ),
			is_remote_read: false,
		);

		$unseen_message = Mockery::mock( \ZBateson\MailMimeParser\IMessage::class );
		$unseen_message->allows( 'getMessageId' )->andReturn( 'unseen@example.org' );
		$unseen_fetched_email = new Fetched_Email(
			message: $unseen_message,
			coordinates: new Remote_Email_Coordinates( message_id: 'unseen@example.org' ),
			is_remote_read: false,
		);

				$connection->expects( 'retrieve_emails' )->andReturn( new Collection( array( $already_saved_fetched_email, $unseen_fetched_email ) ) );

		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$email_repository->expects( 'is_post_for_message_id' )->with( 'test@example.org', 'already-saved@example.org' )->andReturnTrue();
		$email_repository->expects( 'is_post_for_message_id' )->with( 'test@example.org', 'unseen@example.org' )->andReturnFalse();
		$email_repository->expects( 'save_all' )->andReturnUsing(
			function ( Collection $emails_to_save ) use ( $unseen_fetched_email ) {
				$this->assertCount( 1, $emails_to_save );
				$this->assertSame( $unseen_fetched_email, $emails_to_save->first() );
				return array();
			}
		);

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 )->once();

		$this->store_credentials( $credentials );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( $connection );

		$sut = $this->get_api( settings: $settings, email_repository: $email_repository, email_account_repository: $email_account_repository );
		$sut->check_email();
	}

	/**
	 * The newly saved emails returned by save_all() must be passed to both post-fetch actions
	 * and returned in the check_email() result.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_passes_saved_emails_to_both_actions(): void {

		$email_account = BH_Email_Account_Fixture::make();
		$credentials   = Mockery::mock( Account_Credentials_Interface::class );
		$connection    = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$settings      = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'               => 'test-plugin',
				'get_emails_cpt_underscored_20' => 'test_emails',
			)
		)->shouldIgnoreMissing();

		$saved_bh_email = new BH_Email(
			post_id: 99,
			post_type: 'bh_email',
			email_account_local_id: 77,
			imessage: Mockery::mock( \ZBateson\MailMimeParser\IMessage::class ),
			message_id: 'saved@example.org',
			subject: 'Test subject',
			from_email: 'sender@example.org',
		);

		$connection->expects( 'retrieve_emails' )->andReturn( new Collection() );

		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$email_repository->expects( 'save_all' )->andReturn( array( $saved_bh_email ) );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 )->once();

		$this->store_credentials( $credentials );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( $connection );

		$sut    = $this->get_api( settings: $settings, email_repository: $email_repository, email_account_repository: $email_account_repository );
		$result = $sut->check_email();

		$this->assertSame( $saved_bh_email, $result->get_emails()[0]->get_email() );
	}

	/**
	 * An explicitly provided $since datetime must be passed through to the fetcher unchanged.
	 *
	 * @covers ::check_email_for_account
	 */
	public function test_check_email_for_account_uses_explicit_since_datetime(): void {

		$since_datetime = new DateTimeImmutable( '-3 days' );

		$email_account    = BH_Email_Account_Fixture::make();
		$credentials      = Mockery::mock( Account_Credentials_Interface::class );
		$connection       = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );

		$captured_since_datetime = null;

		$connection->expects( 'retrieve_emails' )->andReturnUsing(
			function ( DateTimeInterface $retrieve_since ) use ( &$captured_since_datetime ) {
				$captured_since_datetime = $retrieve_since;
				return new Collection();
			}
		);
		$email_repository->expects( 'save_all' )->andReturn( array() );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 )->once();

		$this->store_credentials( $credentials );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( $connection );

		$sut    = $this->get_api( email_repository: $email_repository, email_account_repository: $email_account_repository );
		$result = $sut->check_email_for_account( $email_account, $since_datetime );

		$this->assertSame( $since_datetime, $captured_since_datetime );
		$this->assertTrue( $result->success );
		$this->assertSame( array(), $result->bh_emails );
	}

	/**
	 * With no $since argument and no recorded last_successful_login_time, the lookback must
	 * default to one week.
	 *
	 * @covers ::check_email_for_account
	 */
	public function test_check_email_for_account_defaults_to_one_week_lookback_on_first_run(): void {

		$email_account    = BH_Email_Account_Fixture::make( last_successful_login_time: null );
		$credentials      = Mockery::mock( Account_Credentials_Interface::class );
		$connection       = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );

		$captured_since_datetime = null;

		$connection->expects( 'retrieve_emails' )->andReturnUsing(
			function ( DateTimeInterface $retrieve_since ) use ( &$captured_since_datetime ) {
				$captured_since_datetime = $retrieve_since;
				return new Collection();
			}
		);
		$email_repository->expects( 'save_all' )->andReturn( array() );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 )->once();

		$this->store_credentials( $credentials );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( $connection );

		$sut = $this->get_api( email_repository: $email_repository, email_account_repository: $email_account_repository );
		$sut->check_email_for_account( $email_account );

		$this->assertInstanceOf( DateTimeInterface::class, $captured_since_datetime );

		$one_week_ago = ( new DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->sub( new \DateInterval( 'P1W' ) );
		$this->assertEqualsWithDelta( $one_week_ago->getTimestamp(), $captured_since_datetime->getTimestamp(), 60 );
	}

	/**
	 * After a successful fetch, both post-fetch actions must fire.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_fires_saved_and_complete_actions(): void {

		$email_account    = BH_Email_Account_Fixture::make();
		$credentials      = Mockery::mock( Account_Credentials_Interface::class );
		$connection       = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$settings         = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'               => 'test-plugin',
				'get_emails_cpt_underscored_20' => 'test_email',
			)
		)->shouldIgnoreMissing();

		$connection->expects( 'retrieve_emails' )->andReturn( new Collection() );
		$email_repository->expects( 'save_all' )->andReturn( array() );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 )->once();

		$this->store_credentials( $credentials );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_email', $email_account )
				->reply( $connection );

		$sut = $this->get_api( settings: $settings, email_repository: $email_repository, email_account_repository: $email_account_repository );
		$sut->check_email();
	}

	/**
	 * A filter-supplied fetcher takes priority over the built-in class map.
	 *
	 * @covers ::get_connection_for_email_account
	 */
	public function test_get_connection_for_email_account_returns_custom_fetcher_via_filter(): void {

		$email_account  = BH_Email_Account_Fixture::make();
		$custom_fetcher = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( $custom_fetcher );

		$sut    = $this->get_api();
		$result = $sut->get_connection_for_email_account( $email_account );

		$this->assertSame( $custom_fetcher, $result );
	}

	/**
	 * An IMAP connection_type_class produces an ImapEngine fetcher.
	 *
	 * @covers ::get_connection_for_email_account
	 */
	public function test_get_connection_for_email_account_returns_imap_fetcher(): void {

		$email_account = BH_Email_Account_Fixture::make( connection_type_class: ImapEngine_Imap_Email_Connection::class );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( null );

		$sut    = $this->get_api();
		$result = $sut->get_connection_for_email_account( $email_account );

		$this->assertInstanceOf( ImapEngine_Imap_Email_Connection::class, $result );
	}

	/**
	 * A Gmail connection_type_class produces a Gmail fetcher.
	 *
	 * @covers ::get_connection_for_email_account
	 */
	public function test_get_connection_for_email_account_returns_gmail_fetcher(): void {

		$email_account = BH_Email_Account_Fixture::make( connection_type_class: Google_API_Credentials_Interface::class );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( null );

		$sut    = $this->get_api();
		$result = $sut->get_connection_for_email_account( $email_account );

		$this->assertInstanceOf( Gmail_Email_Connection::class, $result );
	}

	/**
	 * An unrecognised connection class logs a warning and returns null.
	 *
	 * @covers ::get_connection_for_email_account
	 */
	public function test_get_connection_for_email_account_logs_warning_for_unknown_class(): void {

		$email_account = BH_Email_Account_Fixture::make( connection_type_class: 'Unknown\\Connection\\Class' );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( null );

		$sut    = $this->get_api();
		$result = $sut->get_connection_for_email_account( $email_account );

		$this->assertNull( $result );
		$this->assertTrue( $this->logger->hasWarningThatContains( 'No email fetcher found for connection type' ) );
	}

	/**
	 * Connecting without an exception is reported as success.
	 *
	 * @covers ::test_connection
	 */
	public function test_test_connection_returns_success(): void {

		$email_account = BH_Email_Account_Fixture::make();
		$credentials   = Mockery::mock( Account_Credentials_Interface::class );

		$connection = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );

		$connection->expects( 'test_connection' )->andReturn( true );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( $connection );

		$result = $this->get_api()->test_connection( $email_account, $credentials );

		$this->assertTrue( $result->success );
	}

	/**
	 * A connection exception is reported as a failure with its message.
	 *
	 * @covers ::test_connection
	 */
	public function test_test_connection_reports_failure_message(): void {

		$email_account = BH_Email_Account_Fixture::make();
		$credentials   = Mockery::mock( Account_Credentials_Interface::class );

		$connection = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$connection->allows( 'set_credentials' );
		$connection->allows( 'test_connection' )->andThrow( new \Exception( 'AUTHENTICATIONFAILED' ) );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( $connection );

		$result = $this->get_api()->test_connection( $email_account, $credentials );

		$this->assertFalse( $result->success );
		$this->assertSame( 'AUTHENTICATIONFAILED', $result->message );
	}

	/**
	 * When no credentials are passed explicitly and the connection implements Requires_Credentials,
	 * test_connection() must read the account's saved credentials from the credentials store.
	 *
	 * @covers ::test_connection
	 */
	public function test_test_connection_resolves_credentials_from_the_store(): void {

		$email_account        = BH_Email_Account_Fixture::make();
		$resolved_credentials = Mockery::mock( Account_Credentials_Interface::class );

		$connection = Mockery::mock(
			Email_Connection_Interface::class,
			Supports_Fetching::class,
			Requires_Credentials::class
		);
		$connection->expects( 'set_credentials' )->with( $resolved_credentials );
		$connection->expects( 'test_connection' )->andReturn( true );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( $connection );

		$this->store_credentials( $resolved_credentials );

		// Note: credentials are intentionally NOT passed, forcing resolution through the store.
		$result = $this->get_api()->test_connection( $email_account );

		$this->assertTrue( $result->success );
		$this->assertSame( $email_account, $this->credentials_requested_for );
	}

	/**
	 * A remote action that fails on the mail server records an error note on the email and rethrows, so a
	 * caller (the REST route) reports the failure instead of a quiet success.
	 *
	 * @covers ::perform_remote_email_action
	 * @covers ::mark_email_read
	 * @covers ::mark_email_unread
	 * @covers ::delete_email_on_server
	 *
	 * @dataProvider remote_action_failures
	 *
	 * @param string $method            The API method.
	 * @param string $connection_method The connection method that fails.
	 * @param string $note              The error note recorded.
	 */
	public function test_failed_remote_action_records_a_note_and_rethrows( string $method, string $connection_method, string $note ): void {
		$email_account = BH_Email_Account_Fixture::make();
		$email         = BH_Email_Fixture::new(
			post_id: 42,
			post_type: 'test_emails',
			email_account_local_id: $email_account->get_post_id(),
			imessage: Mockery::mock( \ZBateson\MailMimeParser\IMessage::class ),
			message_id: 'm@example.org',
			subject: 'Hi',
			from_email: 'a@example.org',
			remote_coordinates: new Remote_Email_Coordinates( message_id: 'm@example.org', remote_uid: '7' ),
		);

		$connection = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$connection->expects( $connection_method )->once()->andThrow( new \RuntimeException( 'IMAP said no.' ) );
		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( $connection );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->allows( 'find_by_post_id' )->andReturn( $email_account );

		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$email_repository->allows( 'find_by_post_id' )->with( 42 )->andReturn( $email );
		$email_repository->expects( 'update' )->never();
		$email_repository->expects( 'log' )->with( $email, $note, false, array(), 'error' )->once();

		$sut = $this->get_api( email_repository: $email_repository, email_account_repository: $email_account_repository );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'IMAP said no.' );

		$sut->$method( $email );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function remote_action_failures(): array {
		return array(
			'mark read'        => array( 'mark_email_read', 'set_is_marked_read', 'Failed to mark as read on server.' ),
			'mark unread'      => array( 'mark_email_unread', 'set_is_marked_read', 'Failed to mark as unread on server.' ),
			'delete on server' => array( 'delete_email_on_server', 'do_delete_on_server', 'Failed to delete email on server.' ),
		);
	}

	// ── from-address / body-identifier regex filters ─────────────────────────────

	/**
	 * A fetched email whose parsed message reports the given sender and bodies.
	 *
	 * @param string  $message_id The Message-ID.
	 * @param ?string $from       The sender address; null for a message with no From header.
	 * @param ?string $text       The plain-text body, or null when the message has none.
	 * @param ?string $html       The HTML body, or null when the message has none.
	 */
	private function make_fetched_email( string $message_id, ?string $from, ?string $text = null, ?string $html = null ): Fetched_Email {
		$message = Mockery::mock( IMessage::class );
		$message->allows( 'getMessageId' )->andReturn( $message_id );
		$message->allows( 'getTextContent' )->andReturn( $text );
		$message->allows( 'getHtmlContent' )->andReturn( $html );

		if ( is_null( $from ) ) {
			$message->allows( 'getHeader' )->with( 'From' )->andReturnNull();
		} else {
			$from_header = Mockery::mock( AddressHeader::class );
			$from_header->allows( 'getEmail' )->andReturn( $from );
			$message->allows( 'getHeader' )->with( 'From' )->andReturn( $from_header );
		}

		return new Fetched_Email(
			message: $message,
			coordinates: new Remote_Email_Coordinates( message_id: $message_id ),
			is_remote_read: false,
		);
	}

	/**
	 * Run check_email() for one account whose connection returns the given emails, and return what
	 * reached save_all() (as Message-IDs) alongside the account's result.
	 *
	 * @param BH_Email_Account $email_account The account (carrying the regex filters under test).
	 * @param Fetched_Email[]  $fetched       What the connection returns.
	 *
	 * @return array{saved: string[], result: \BrianHenryIE\WP_Mailboxes\API\Model\Result\Check_Email_Account_Result}
	 */
	private function check_with_filters( BH_Email_Account $email_account, array $fetched ): array {
		$credentials = Mockery::mock( Account_Credentials_Interface::class );
		$connection  = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$settings    = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'               => 'test-plugin',
				'get_emails_cpt_underscored_20' => 'test_emails',
			)
		)->shouldIgnoreMissing();

		$connection->expects( 'retrieve_emails' )->andReturn( new Collection( $fetched ) );

		$saved_message_ids = null;

		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$email_repository->allows( 'is_post_for_message_id' )->andReturnFalse();
		$email_repository->expects( 'save_all' )->once()->andReturnUsing(
			function ( Collection $emails_to_save ) use ( &$saved_message_ids ) {
				$saved_message_ids = $emails_to_save->map( fn( Fetched_Email $email ) => $email->message->getMessageId() )->all();
				return array();
			}
		);

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );
		$email_account_repository->allows( 'update' )->andReturnArg( 0 );

		$this->store_credentials( $credentials );

		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
				->with( null, 'test-plugin', 'test_emails', $email_account )
				->reply( $connection );

		$sut    = $this->get_api( settings: $settings, email_repository: $email_repository, email_account_repository: $email_account_repository );
		$result = $sut->check_email();

		$this->assertIsArray( $saved_message_ids, 'save_all() was not called.' );

		return array(
			'saved'  => $saved_message_ids,
			'result' => $result->account_results[0],
		);
	}

	/**
	 * With no filters configured, every fetched email is saved.
	 *
	 * @covers ::filter_by_account_regexes
	 * @covers ::validate_regex
	 */
	public function test_no_regex_filters_saves_every_email(): void {
		$account = BH_Email_Account_Fixture::make( from_address_regex_filter: null, body_identifier_regex_filter: null );

		$outcome = $this->check_with_filters(
			$account,
			array(
				$this->make_fetched_email( 'a@example.org', 'anyone@example.com', 'anything' ),
				$this->make_fetched_email( 'b@example.org', null, null ),
			)
		);

		$this->assertSame( array( 'a@example.org', 'b@example.org' ), $outcome['saved'] );
		$this->assertSame( array(), $outcome['result']->warnings );
	}

	/**
	 * Blank filters are treated the same as unset ones.
	 *
	 * @covers ::validate_regex
	 */
	public function test_blank_regex_filters_are_ignored(): void {
		$account = BH_Email_Account_Fixture::make( from_address_regex_filter: '  ', body_identifier_regex_filter: '' );

		$outcome = $this->check_with_filters(
			$account,
			array( $this->make_fetched_email( 'a@example.org', null, null ) )
		);

		$this->assertSame( array( 'a@example.org' ), $outcome['saved'] );
		$this->assertSame( array(), $outcome['result']->warnings );
	}

	/**
	 * Only emails whose sender address matches the from-address regex are saved.
	 *
	 * @covers ::filter_by_account_regexes
	 */
	public function test_from_address_regex_keeps_only_matching_senders(): void {
		$account = BH_Email_Account_Fixture::make( from_address_regex_filter: '/@venmo\.com$/i' );

		$outcome = $this->check_with_filters(
			$account,
			array(
				$this->make_fetched_email( 'match@example.org', 'venmo@venmo.com', 'body' ),
				$this->make_fetched_email( 'upper@example.org', 'Receipts@VENMO.COM', 'body' ),
				$this->make_fetched_email( 'other@example.org', 'someone@example.com', 'body' ),
				$this->make_fetched_email( 'spoof@example.org', 'venmo.com@example.com', 'body' ),
			)
		);

		$this->assertSame( array( 'match@example.org', 'upper@example.org' ), $outcome['saved'] );
		$this->assertTrue( $this->logger->hasDebugThatContains( 'Not saving email other@example.org: sender "someone@example.com" does not match the from address filter' ) );
		$this->assertTrue( $this->logger->hasInfoThatContains( "2 of 4 new emails did not match the account's filters and were not saved." ) );
	}

	/**
	 * An email with no From header cannot match a from-address regex, so it is not saved.
	 *
	 * @covers ::filter_by_account_regexes
	 */
	public function test_from_address_regex_drops_email_without_from_header(): void {
		$account = BH_Email_Account_Fixture::make( from_address_regex_filter: '/./' );

		$outcome = $this->check_with_filters(
			$account,
			array( $this->make_fetched_email( 'headless@example.org', null, 'body' ) )
		);

		$this->assertSame( array(), $outcome['saved'] );
		$this->assertTrue( $this->logger->hasDebugThatContains( 'sender "" does not match' ) );
	}

	/**
	 * The body regex is matched against the plain-text body.
	 *
	 * @covers ::filter_by_account_regexes
	 */
	public function test_body_regex_keeps_only_matching_plain_text_bodies(): void {
		$account = BH_Email_Account_Fixture::make( body_identifier_regex_filter: '/You paid \$\d+\.\d{2}/' );

		$outcome = $this->check_with_filters(
			$account,
			array(
				$this->make_fetched_email( 'receipt@example.org', 'a@example.com', 'Hi Brian, You paid $12.50 to Bob.' ),
				$this->make_fetched_email( 'newsletter@example.org', 'a@example.com', 'This week in payments.' ),
			)
		);

		$this->assertSame( array( 'receipt@example.org' ), $outcome['saved'] );
		$this->assertTrue( $this->logger->hasDebugThatContains( 'Not saving email newsletter@example.org: body does not match the body identifier filter' ) );
	}

	/**
	 * When the message has no plain-text part, the body regex is matched against the HTML body.
	 *
	 * @covers ::filter_by_account_regexes
	 */
	public function test_body_regex_falls_back_to_html_body(): void {
		$account = BH_Email_Account_Fixture::make( body_identifier_regex_filter: '/Order #\d+/' );

		$outcome = $this->check_with_filters(
			$account,
			array(
				$this->make_fetched_email( 'html-only@example.org', 'a@example.com', null, '<p>Order #123 confirmed</p>' ),
				$this->make_fetched_email( 'html-miss@example.org', 'a@example.com', null, '<p>Nothing here</p>' ),
			)
		);

		$this->assertSame( array( 'html-only@example.org' ), $outcome['saved'] );
	}

	/**
	 * A match in either body part is enough: plain text that misses does not veto HTML that matches.
	 *
	 * @covers ::filter_by_account_regexes
	 */
	public function test_body_regex_matches_when_only_html_part_matches(): void {
		$account = BH_Email_Account_Fixture::make( body_identifier_regex_filter: '/data-order="\d+"/' );

		$outcome = $this->check_with_filters(
			$account,
			array( $this->make_fetched_email( 'both@example.org', 'a@example.com', 'Plain text without the attribute.', '<div data-order="9">…</div>' ) )
		);

		$this->assertSame( array( 'both@example.org' ), $outcome['saved'] );
	}

	/**
	 * A message with neither a plain-text nor an HTML body cannot match a body regex.
	 *
	 * @covers ::filter_by_account_regexes
	 */
	public function test_body_regex_drops_email_without_body(): void {
		$account = BH_Email_Account_Fixture::make( body_identifier_regex_filter: '/.*/' );

		$outcome = $this->check_with_filters(
			$account,
			array( $this->make_fetched_email( 'empty@example.org', 'a@example.com', null, null ) )
		);

		$this->assertSame( array(), $outcome['saved'] );
	}

	/**
	 * When both filters are set an email must satisfy both to be saved.
	 *
	 * @covers ::filter_by_account_regexes
	 */
	public function test_both_regex_filters_must_match(): void {
		$account = BH_Email_Account_Fixture::make(
			from_address_regex_filter: '/^receipts@shop\.example$/',
			body_identifier_regex_filter: '/Order #\d+/'
		);

		$outcome = $this->check_with_filters(
			$account,
			array(
				$this->make_fetched_email( 'both@example.org', 'receipts@shop.example', 'Order #1' ),
				$this->make_fetched_email( 'from-only@example.org', 'receipts@shop.example', 'Your password reset' ),
				$this->make_fetched_email( 'body-only@example.org', 'phisher@evil.example', 'Order #2' ),
				$this->make_fetched_email( 'neither@example.org', 'phisher@evil.example', 'Hello' ),
			)
		);

		$this->assertSame( array( 'both@example.org' ), $outcome['saved'] );
		$this->assertTrue( $this->logger->hasInfoThatContains( "3 of 4 new emails did not match the account's filters" ) );
	}

	/**
	 * The collection passed to save_all() is re-indexed so its keys are contiguous after filtering.
	 *
	 * @covers ::filter_by_account_regexes
	 */
	public function test_filtered_collection_is_reindexed(): void {
		$account = BH_Email_Account_Fixture::make( from_address_regex_filter: '/keep/' );

		$credentials = Mockery::mock( Account_Credentials_Interface::class );
		$connection  = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$settings    = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'               => 'test-plugin',
				'get_emails_cpt_underscored_20' => 'test_emails',
			)
		)->shouldIgnoreMissing();

		$connection->expects( 'retrieve_emails' )->andReturn(
			new Collection(
				array(
					$this->make_fetched_email( 'drop@example.org', 'drop@example.com' ),
					$this->make_fetched_email( 'keep@example.org', 'keep@example.com' ),
				)
			)
		);

		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$email_repository->allows( 'is_post_for_message_id' )->andReturnFalse();
		$email_repository->expects( 'save_all' )->andReturnUsing(
			function ( Collection $emails_to_save ) {
				$this->assertSame( array( 0 ), $emails_to_save->keys()->all() );
				return array();
			}
		);

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $account ) );
		$email_account_repository->allows( 'update' )->andReturnArg( 0 );

		$this->store_credentials( $credentials );
		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )->with( null, 'test-plugin', 'test_emails', $account )->reply( $connection );

		$this->get_api( settings: $settings, email_repository: $email_repository, email_account_repository: $email_account_repository )->check_email();
	}

	/**
	 * An invalid from-address regex is ignored (the emails are kept) and reported as a warning on the
	 * result and in the log, rather than silently discarding every email.
	 *
	 * @covers ::validate_regex
	 */
	public function test_invalid_from_regex_is_ignored_and_reported(): void {
		$account = BH_Email_Account_Fixture::make( from_address_regex_filter: '/unclosed(' );

		$outcome = $this->check_with_filters(
			$account,
			array( $this->make_fetched_email( 'a@example.org', 'anyone@example.com', 'body' ) )
		);

		$this->assertSame( array( 'a@example.org' ), $outcome['saved'] );
		$this->assertCount( 1, $outcome['result']->warnings );
		$this->assertStringContainsString( 'The from address filter "/unclosed(" is not a valid regular expression and was ignored', $outcome['result']->warnings[0] );
		$this->assertTrue( $this->logger->hasWarningThatContains( 'The from address filter "/unclosed(" is not a valid regular expression' ) );
	}

	/**
	 * An invalid body regex is ignored while a valid from-address regex on the same account still applies.
	 *
	 * @covers ::validate_regex
	 * @covers ::filter_by_account_regexes
	 */
	public function test_invalid_body_regex_is_ignored_but_valid_from_regex_still_applies(): void {
		$account = BH_Email_Account_Fixture::make(
			from_address_regex_filter: '/@shop\.example$/',
			body_identifier_regex_filter: 'no delimiters'
		);

		$outcome = $this->check_with_filters(
			$account,
			array(
				$this->make_fetched_email( 'shop@example.org', 'orders@shop.example', 'no delimiters' ),
				$this->make_fetched_email( 'other@example.org', 'someone@example.com', 'no delimiters' ),
			)
		);

		$this->assertSame( array( 'shop@example.org' ), $outcome['saved'] );
		$this->assertCount( 1, $outcome['result']->warnings );
		$this->assertStringContainsString( 'The body identifier filter "no delimiters" is not a valid regular expression', $outcome['result']->warnings[0] );
	}

	/**
	 * A regex that does not compile must not leave PHP's warning unhandled (it would fail the test) and
	 * must not leave a custom error handler installed afterwards.
	 *
	 * @covers ::validate_regex
	 */
	public function test_invalid_regex_restores_error_handler(): void {
		$account = BH_Email_Account_Fixture::make( from_address_regex_filter: '/[/' );

		$before = set_error_handler( null ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		restore_error_handler();

		$this->check_with_filters( $account, array( $this->make_fetched_email( 'a@example.org', 'a@example.com' ) ) );

		$after = set_error_handler( null ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		restore_error_handler();

		$this->assertSame( $before, $after );
	}

	/**
	 * Filtering happens after the already-saved dedupe, so a filter is never evaluated against an email
	 * that would not be saved anyway.
	 *
	 * @covers ::filter_by_account_regexes
	 */
	public function test_regex_filters_are_not_evaluated_for_already_saved_emails(): void {
		$account = BH_Email_Account_Fixture::make( email_address: 'test@example.org', from_address_regex_filter: '/./' );

		$already_saved = Mockery::mock( IMessage::class );
		$already_saved->allows( 'getMessageId' )->andReturn( 'saved@example.org' );
		$already_saved->expects( 'getHeader' )->never();
		$already_saved_fetched = new Fetched_Email( message: $already_saved, coordinates: new Remote_Email_Coordinates( message_id: 'saved@example.org' ) );

		$credentials = Mockery::mock( Account_Credentials_Interface::class );
		$connection  = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$settings    = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'               => 'test-plugin',
				'get_emails_cpt_underscored_20' => 'test_emails',
			)
		)->shouldIgnoreMissing();
		$connection->expects( 'retrieve_emails' )->andReturn( new Collection( array( $already_saved_fetched ) ) );

		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$email_repository->expects( 'is_post_for_message_id' )->with( 'test@example.org', 'saved@example.org' )->andReturnTrue();
		$email_repository->expects( 'save_all' )->andReturnUsing(
			function ( Collection $emails_to_save ) {
				$this->assertCount( 0, $emails_to_save );
				return array();
			}
		);

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $account ) );
		$email_account_repository->allows( 'update' )->andReturnArg( 0 );

		$this->store_credentials( $credentials );
		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )->with( null, 'test-plugin', 'test_emails', $account )->reply( $connection );

		$this->get_api( settings: $settings, email_repository: $email_repository, email_account_repository: $email_account_repository )->check_email();
	}
}
