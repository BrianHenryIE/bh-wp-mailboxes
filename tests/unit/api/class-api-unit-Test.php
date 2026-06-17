<?php

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_WP_Mailboxes_Settings;
use Illuminate\Support\Collection;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Check_Email_Result;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Gmail_Email_Provider;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Google_API_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Providers\Imap\ImapEngine_Imap_Email_Provider;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use BrianHenryIE\WP_Private_Uploads\API\API as Private_Uploads;
use Codeception\Stub\Expected;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Mockery;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\API
 */
class API_Unit_Test extends Unit_Testcase {

	protected function get_api(
		?BH_WP_Mailboxes_Settings_Interface $settings = null,
		?Email_WP_Post_Repository $email_repository = null,
		?Email_Account_WP_Post_Repository $email_account_repository = null,
		?Private_Uploads $private_uploads = null,
		?LoggerInterface $logger = null
	): API {
		return new API(
			$settings ?? BH_WP_Mailboxes_Settings::make(),
			$email_repository ?? \Mockery::mock( Email_WP_Post_Repository::class ),
			$email_account_repository ?? \Mockery::mock( Email_Account_WP_Post_Repository::class ),
			$private_uploads ?? \Mockery::mock( Private_Uploads::class ),
			$logger ?? $this->logger,
		);
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

		$this->assertInstanceOf( Check_Email_Result::class, $result );
		$this->assertTrue( $result->success );
		$this->assertSame( array(), $result->new_emails );
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

		\WP_Mock::userFunction(
			'get_option',
			array(
				'args'   => array( 'plugin-slug_mailbox_last_fetched_Dummy Account', null ),
				'return' => new DateTime()->format( DateTime::ATOM ),
			)
		);

		\WP_Mock::userFunction(
			'get_option',
			array(
				'args'   => array( 'plugin-slug_mailbox_last_failure_Dummy Account', null ),
				'return' => new DateTime()->format( DateTime::ATOM ),
			)
		);

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )
				->withAnyArgs()
				->reply( $credentials );

		\WP_Mock::userFunction( 'wp_doing_cron' )->andReturnTrue();

		$sut->check_email();

		$this->assertTrue( $this->logger->hasInfoThatContains( 'Too soon after failed login' ) );
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
		$this->assertSame( array(), $result->new_emails );
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

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )
				->with( null, 'test-plugin', $email_account )
				->reply( null );

		$sut = $this->get_api( email_account_repository: $email_account_repository );
		$sut->check_email();

		$this->assertTrue( $this->logger->hasWarningThatContains( 'No credentials found' ) );
	}

	/**
	 * When no known provider class matches, a warning is logged and the account is skipped.
	 *
	 * @covers ::check_email
	 * @covers ::get_provider_for_email_account
	 */
	public function test_check_email_skips_account_when_no_fetcher_found(): void {

		$email_account = BH_Email_Account_Fixture::make( provider_type_class: 'Unknown\\Provider\\Class' );

		$credentials = Mockery::mock( Account_Credentials_Interface::class );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )
				->with( null, 'test-plugin', $email_account )
				->reply( $credentials );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_provider_for_account' )
				->with( null, 'test-plugin', $email_account )
				->reply( null );

		$sut = $this->get_api( email_account_repository: $email_account_repository );
		$sut->check_email();

		$this->assertTrue( $this->logger->hasWarningThatContains( 'No fetcher found' ) );
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
		$provider         = Mockery::mock( Email_Provider_Interface::class );
		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$settings         = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'               => 'test-plugin',
				'get_emails_cpt_underscored_20' => 'test_email',
			)
		)->shouldIgnoreMissing();

		$provider->expects( 'retrieve_emails' )->andReturn( new Collection() );
		$email_repository->expects( 'save_all' )->andReturn( array() );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 )->once();

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )
				->with( null, 'test-plugin', $email_account )
				->reply( $credentials );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_provider_for_account' )
				->with( null, 'test-plugin', $email_account )
				->reply( $provider );

		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_saved_test-plugin', array() );
		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_complete', array() );

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
		$provider      = Mockery::mock( Email_Provider_Interface::class );
		$settings      = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug' => 'test-plugin',
			)
		)->shouldIgnoreMissing();

		$provider->expects( 'retrieve_emails' )->andThrow( new \Exception( 'Connection refused' ) );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 )->once();

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )
				->with( null, 'test-plugin', $email_account )
				->reply( $credentials );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_provider_for_account' )
				->with( null, 'test-plugin', $email_account )
				->reply( $provider );

		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_saved_test-plugin', array() );
		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_complete', array() );

		$sut = $this->get_api( settings: $settings, email_account_repository: $email_account_repository );
		$sut->check_email();

		$this->assertTrue( $this->logger->hasErrorThatContains( 'Error fetching emails' ) );
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
		$provider      = Mockery::mock( Email_Provider_Interface::class );
		$settings      = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug' => 'test-plugin',
			)
		)->shouldIgnoreMissing();

		$provider->expects( 'retrieve_emails' )->andThrow( new \Exception( 'AUTHENTICATIONFAILED' ) );

		/**
		 * On a fetch failure `update()` is called once; the mock declares the full signature, so the
		 * named `last_failed_login_time` argument binds to its declared position (index 9).
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

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )
				->with( null, 'test-plugin', $email_account )
				->reply( $credentials );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_provider_for_account' )
				->with( null, 'test-plugin', $email_account )
				->reply( $provider );

		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_saved_test-plugin', array() );
		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_complete', array() );

		$sut = $this->get_api( settings: $settings, email_account_repository: $email_account_repository );
		$sut->check_email();

		$this->assertNotEmpty( $captured_update_args, 'Email_Account_WP_Post_Repository::update() was not called.' );
		$this->assertSame( $email_account, $captured_update_args[0][0] );

		$last_failed_login_time = $captured_update_args[0][9] ?? null;
		$this->assertInstanceOf( DateTimeInterface::class, $last_failed_login_time );
		$this->assertEqualsWithDelta( time(), $last_failed_login_time->getTimestamp(), 60 );
	}

	/**
	 * The credentials filter returning something other than an Account_Credentials_Interface
	 * (an unknown credentials type) must skip the account with a warning, same as null.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_skips_account_when_credentials_filter_returns_unknown_type(): void {

		$email_account = BH_Email_Account_Fixture::make();

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )
				->with( null, 'test-plugin', $email_account )
				->reply( new \stdClass() );

		$sut    = $this->get_api( email_account_repository: $email_account_repository );
		$result = $sut->check_email();

		$this->assertTrue( $this->logger->hasWarningThatContains( 'No credentials found' ) );
		$this->assertSame( array(), $result->new_emails );
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
		$provider         = Mockery::mock( Email_Provider_Interface::class );
		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$settings         = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug' => 'test-plugin',
			)
		)->shouldIgnoreMissing();

		$captured_since_datetime = null;

		$provider->expects( 'retrieve_emails' )->andReturnUsing(
			function ( DateTimeInterface $since_datetime ) use ( &$captured_since_datetime ) {
				$captured_since_datetime = $since_datetime;
				return new Collection();
			}
		);
		$email_repository->expects( 'save_all' )->andReturn( array() );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 )->once();

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )
				->with( null, 'test-plugin', $email_account )
				->reply( $credentials );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_provider_for_account' )
				->with( null, 'test-plugin', $email_account )
				->reply( $provider );

		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_saved_test-plugin', array() );
		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_complete', array() );

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
		$provider         = Mockery::mock( Email_Provider_Interface::class );
		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$settings         = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug' => 'test-plugin',
			)
		)->shouldIgnoreMissing();

		$captured_since_datetime = null;

		$provider->expects( 'retrieve_emails' )->andReturnUsing(
			function ( DateTimeInterface $since_datetime ) use ( &$captured_since_datetime ) {
				$captured_since_datetime = $since_datetime;
				return new Collection();
			}
		);
		$email_repository->expects( 'save_all' )->andReturn( array() );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 )->once();

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )
				->with( null, 'test-plugin', $email_account )
				->reply( $credentials );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_provider_for_account' )
				->with( null, 'test-plugin', $email_account )
				->reply( $provider );

		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_saved_test-plugin', array() );
		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_complete', array() );

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
		$provider      = Mockery::mock( Email_Provider_Interface::class );
		$settings      = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug' => 'test-plugin',
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

				$provider->expects( 'retrieve_emails' )->andReturn( new Collection( array( $already_saved_fetched_email, $unseen_fetched_email ) ) );

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

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )
				->with( null, 'test-plugin', $email_account )
				->reply( $credentials );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_provider_for_account' )
				->with( null, 'test-plugin', $email_account )
				->reply( $provider );

		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_saved_test-plugin', array() );
		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_complete', array() );

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
		$provider      = Mockery::mock( Email_Provider_Interface::class );
		$settings      = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug' => 'test-plugin',
			)
		)->shouldIgnoreMissing();

		$saved_bh_email = new BH_Email(
			post_id: 99,
			post_type: 'bh_email',
			imessage: Mockery::mock( \ZBateson\MailMimeParser\IMessage::class ),
			message_id: 'saved@example.org',
			subject: 'Test subject',
			from_email: 'sender@example.org',
		);

				$provider->expects( 'retrieve_emails' )->andReturn( new Collection() );

		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$email_repository->expects( 'save_all' )->andReturn( array( $saved_bh_email ) );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 )->once();

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )
				->with( null, 'test-plugin', $email_account )
				->reply( $credentials );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_provider_for_account' )
				->with( null, 'test-plugin', $email_account )
				->reply( $provider );

		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_saved_test-plugin', array( $saved_bh_email ) );
		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_complete', array( $saved_bh_email ) );

		$sut    = $this->get_api( settings: $settings, email_repository: $email_repository, email_account_repository: $email_account_repository );
		$result = $sut->check_email();

		$this->assertSame( array( $saved_bh_email ), $result->new_emails );
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
		$provider         = Mockery::mock( Email_Provider_Interface::class );
		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );

		$captured_since_datetime = null;

		$provider->expects( 'retrieve_emails' )->andReturnUsing(
			function ( DateTimeInterface $retrieve_since ) use ( &$captured_since_datetime ) {
				$captured_since_datetime = $retrieve_since;
				return new Collection();
			}
		);
		$email_repository->expects( 'save_all' )->andReturn( array() );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 )->once();

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )
				->with( null, 'test-plugin', $email_account )
				->reply( $credentials );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_provider_for_account' )
				->with( null, 'test-plugin', $email_account )
				->reply( $provider );

		$sut    = $this->get_api( email_repository: $email_repository, email_account_repository: $email_account_repository );
		$result = $sut->check_email_for_account( $email_account, $since_datetime );

		$this->assertSame( $since_datetime, $captured_since_datetime );
		$this->assertTrue( $result->success );
		$this->assertSame( array(), $result->new_emails );
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
		$provider         = Mockery::mock( Email_Provider_Interface::class );
		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );

		$captured_since_datetime = null;

		$provider->expects( 'retrieve_emails' )->andReturnUsing(
			function ( DateTimeInterface $retrieve_since ) use ( &$captured_since_datetime ) {
				$captured_since_datetime = $retrieve_since;
				return new Collection();
			}
		);
		$email_repository->expects( 'save_all' )->andReturn( array() );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 )->once();

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )
				->with( null, 'test-plugin', $email_account )
				->reply( $credentials );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_provider_for_account' )
				->with( null, 'test-plugin', $email_account )
				->reply( $provider );

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
		$provider         = Mockery::mock( Email_Provider_Interface::class );
		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$settings         = Mockery::mock(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'               => 'test-plugin',
				'get_emails_cpt_underscored_20' => 'test_email',
			)
		)->shouldIgnoreMissing();

		$provider->expects( 'retrieve_emails' )->andReturn( new Collection() );
		$email_repository->expects( 'save_all' )->andReturn( array() );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 )->once();

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )
				->with( null, 'test-plugin', $email_account )
				->reply( $credentials );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_provider_for_account' )
				->with( null, 'test-plugin', $email_account )
				->reply( $provider );

		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_saved_test-plugin', array() );
		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_complete', array() );

		$sut = $this->get_api( settings: $settings, email_repository: $email_repository, email_account_repository: $email_account_repository );
		$sut->check_email();
	}

	/**
	 * A filter-supplied fetcher takes priority over the built-in class map.
	 *
	 * @covers ::get_provider_for_email_account
	 */
	public function test_get_provider_for_email_account_returns_custom_fetcher_via_filter(): void {

		$email_account  = BH_Email_Account_Fixture::make();
		$custom_fetcher = Mockery::mock( Email_Provider_Interface::class );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_provider_for_account' )
				->with( null, 'test-plugin', $email_account )
				->reply( $custom_fetcher );

		$sut    = $this->get_api();
		$result = $sut->get_provider_for_email_account( $email_account );

		$this->assertSame( $custom_fetcher, $result );
	}

	/**
	 * An IMAP provider_type_class produces an ImapEngine fetcher.
	 *
	 * @covers ::get_provider_for_email_account
	 */
	public function test_get_provider_for_email_account_returns_imap_fetcher(): void {

		$email_account = BH_Email_Account_Fixture::make( provider_type_class: ImapEngine_Imap_Email_Provider::class );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_provider_for_account' )
				->with( null, 'test-plugin', $email_account )
				->reply( null );

		$sut    = $this->get_api();
		$result = $sut->get_provider_for_email_account( $email_account );

		$this->assertInstanceOf( ImapEngine_Imap_Email_Provider::class, $result );
	}

	/**
	 * A Gmail provider_type_class produces a Gmail fetcher.
	 *
	 * @covers ::get_provider_for_email_account
	 */
	public function test_get_provider_for_email_account_returns_gmail_fetcher(): void {

		$email_account = BH_Email_Account_Fixture::make( provider_type_class: Google_API_Credentials_Interface::class );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_provider_for_account' )
				->with( null, 'test-plugin', $email_account )
				->reply( null );

		$sut    = $this->get_api();
		$result = $sut->get_provider_for_email_account( $email_account );

		$this->assertInstanceOf( Gmail_Email_Provider::class, $result );
	}

	/**
	 * An unrecognised provider class logs a warning and returns null.
	 *
	 * @covers ::get_provider_for_email_account
	 */
	public function test_get_provider_for_email_account_logs_warning_for_unknown_class(): void {

		$email_account = BH_Email_Account_Fixture::make( provider_type_class: 'Unknown\\Provider\\Class' );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_provider_for_account' )
				->with( null, 'test-plugin', $email_account )
				->reply( null );

		$sut    = $this->get_api();
		$result = $sut->get_provider_for_email_account( $email_account );

		$this->assertNull( $result );
		$this->assertTrue( $this->logger->hasWarningThatContains( 'No email fetcher found for provider type' ) );
	}

	/**
	 * Connecting without an exception is reported as success.
	 *
	 * @covers ::test_connection
	 */
	public function test_test_connection_returns_success(): void {

		$email_account = BH_Email_Account_Fixture::make();
		$credentials   = Mockery::mock( Account_Credentials_Interface::class );

		$provider = Mockery::mock( Email_Provider_Interface::class );

		$provider->expects( 'test_connection' )->andReturn( true );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_provider_for_account' )
				->with( null, 'test-plugin', $email_account )
				->reply( $provider );

		$result = $this->get_api()->test_connection( $email_account, $credentials );

		$this->assertTrue( $result->success );
	}

	/**
	 * A provider exception is reported as a failure with its message.
	 *
	 * @covers ::test_connection
	 */
	public function test_test_connection_reports_failure_message(): void {

		$email_account = BH_Email_Account_Fixture::make();
		$credentials   = Mockery::mock( Account_Credentials_Interface::class );

		$provider = Mockery::mock( Email_Provider_Interface::class );
		$provider->allows( 'set_credentials' );
		$provider->allows( 'test_connection' )->andThrow( new \Exception( 'AUTHENTICATIONFAILED' ) );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_provider_for_account' )
				->with( null, 'test-plugin', $email_account )
				->reply( $provider );

		$result = $this->get_api()->test_connection( $email_account, $credentials );

		$this->assertFalse( $result->success );
		$this->assertSame( 'AUTHENTICATIONFAILED', $result->message );
	}
}
