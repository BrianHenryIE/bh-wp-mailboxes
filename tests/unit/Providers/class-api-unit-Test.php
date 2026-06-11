<?php

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use Illuminate\Support\Collection;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Gmail_Email_Fetcher;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Google_API_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Providers\Imap\ImapEngine_Imap_Email_Fetcher;
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
			$settings ?? $this->makeEmpty( BH_WP_Mailboxes_Settings_Interface::class ),
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

		$settings = $this->makeEmpty( BH_WP_Mailboxes_Settings_Interface::class );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array() );

		$sut = $this->get_api( settings: $settings, email_account_repository: $email_account_repository );

		$result = $sut->check_email();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'all_new_emails', $result );
		$this->assertArrayHasKey( 'saved_emails', $result );

		// If the response format changes, this test will fail.
		$this->assertCount( 3, $result );
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

		$settings = $this->makeEmpty( BH_WP_Mailboxes_Settings_Interface::class );

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

		$email_account = BH_Email_Account_Fixture::make( status: 'inactive' );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );

		$sut = $this->get_api( email_account_repository: $email_account_repository );

		$result = $sut->check_email();

		$this->assertTrue( $this->logger->hasDebugThatContains( 'Skipping inactive email account' ) );
		$this->assertSame( array(), $result['all_new_emails'] );
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
				->with( null, $email_account )
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
				->with( null, $email_account )
				->reply( $credentials );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_fetcher_for_credentials' )
				->with( null, $email_account )
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
		$fetcher          = Mockery::mock( Email_Fetcher_Interface::class );
		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$settings         = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'               => 'test-plugin',
				'get_emails_cpt_underscored_20' => 'test_email',
			)
		);

		$fetcher->expects( 'set_credentials' )->with( $credentials );
		$fetcher->expects( 'retrieve_emails' )->andReturn( new Collection() );
		$email_repository->expects( 'save_all' )->andReturn( array() );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )
				->with( null, $email_account )
				->reply( $credentials );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_fetcher_for_credentials' )
				->with( null, $email_account )
				->reply( $fetcher );

		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_saved_test-plugin', array() );
		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_complete', array() );

		$sut    = $this->get_api( settings: $settings, email_repository: $email_repository, email_account_repository: $email_account_repository );
		$result = $sut->check_email();

		$this->assertFalse( $this->logger->hasInfoThatContains( 'Too soon after failed login' ) );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * A fetch exception must be logged as an error; remaining accounts continue to be processed.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_logs_error_on_fetch_exception(): void {

		$email_account = BH_Email_Account_Fixture::make();
		$credentials   = Mockery::mock( Account_Credentials_Interface::class );
		$fetcher       = Mockery::mock( Email_Fetcher_Interface::class );
		$settings      = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug' => 'test-plugin',
			)
		);

		$fetcher->expects( 'set_credentials' )->with( $credentials );
		$fetcher->expects( 'retrieve_emails' )->andThrow( new \Exception( 'Connection refused' ) );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )
				->with( null, $email_account )
				->reply( $credentials );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_fetcher_for_credentials' )
				->with( null, $email_account )
				->reply( $fetcher );

		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_saved_test-plugin', array() );
		\WP_Mock::expectAction( 'bh_wp_mailboxes_fetch_emails_complete', array() );

		$sut = $this->get_api( settings: $settings, email_account_repository: $email_account_repository );
		$sut->check_email();

		$this->assertTrue( $this->logger->hasErrorThatContains( 'Error fetching emails' ) );
	}

	/**
	 * After a successful fetch, both post-fetch actions must fire.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_fires_saved_and_complete_actions(): void {

		$email_account    = BH_Email_Account_Fixture::make();
		$credentials      = Mockery::mock( Account_Credentials_Interface::class );
		$fetcher          = Mockery::mock( Email_Fetcher_Interface::class );
		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$settings         = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'               => 'test-plugin',
				'get_emails_cpt_underscored_20' => 'test_email',
			)
		);

		$fetcher->expects( 'set_credentials' )->with( $credentials );
		$fetcher->expects( 'retrieve_emails' )->andReturn( new Collection() );
		$email_repository->expects( 'save_all' )->andReturn( array() );

		$email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$email_account_repository->expects( 'get_all' )->andReturn( array( $email_account ) );
		$email_account_repository->expects( 'update' )->andReturnArg( 0 );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )
				->with( null, $email_account )
				->reply( $credentials );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_fetcher_for_credentials' )
				->with( null, $email_account )
				->reply( $fetcher );

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
		$custom_fetcher = Mockery::mock( Email_Fetcher_Interface::class );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_fetcher_for_credentials' )
				->with( null, $email_account )
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

		$email_account = BH_Email_Account_Fixture::make( provider_type_class: ImapEngine_Imap_Email_Fetcher::class );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_fetcher_for_credentials' )
				->with( null, $email_account )
				->reply( null );

		$sut    = $this->get_api();
		$result = $sut->get_provider_for_email_account( $email_account );

		$this->assertInstanceOf( ImapEngine_Imap_Email_Fetcher::class, $result );
	}

	/**
	 * A Gmail provider_type_class produces a Gmail fetcher.
	 *
	 * @covers ::get_provider_for_email_account
	 */
	public function test_get_provider_for_email_account_returns_gmail_fetcher(): void {

		$email_account = BH_Email_Account_Fixture::make( provider_type_class: Google_API_Credentials_Interface::class );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_fetcher_for_credentials' )
				->with( null, $email_account )
				->reply( null );

		$sut    = $this->get_api();
		$result = $sut->get_provider_for_email_account( $email_account );

		$this->assertInstanceOf( Gmail_Email_Fetcher::class, $result );
	}

	/**
	 * An unrecognised provider class logs a warning and returns null.
	 *
	 * @covers ::get_provider_for_email_account
	 */
	public function test_get_provider_for_email_account_logs_warning_for_unknown_class(): void {

		$email_account = BH_Email_Account_Fixture::make( provider_type_class: 'Unknown\\Provider\\Class' );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_fetcher_for_credentials' )
				->with( null, $email_account )
				->reply( null );

		$sut    = $this->get_api();
		$result = $sut->get_provider_for_email_account( $email_account );

		$this->assertNull( $result );
		$this->assertTrue( $this->logger->hasWarningThatContains( 'No email fetcher found for provider type' ) );
	}
}
