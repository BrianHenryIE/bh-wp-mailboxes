<?php

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use BrianHenryIE\WP_Private_Uploads\API\API as Private_Uploads;
use Codeception\Stub\Expected;
use DateTime;
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
			$settings ?? \Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class ),
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
	 *
	 * Check:
	 * * the key is sanitized.
	 * * update_option is called with expected key and a string
	 *
	 * TODO: Validate the date string is ATOM.
	 *
	 * @see https://gist.github.com/olivertappin/615737591c9fa8882719fed405978aaf
	 *
	 * @covers ::set_last_fetched_time
	 * @covers ::get_last_fetched_option_name
	 */
	public function test_set_last_fetched_time(): void {

		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug' => Expected::atLeastOnce(
					fn() => 'plugin-slug'
				),
			)
		);

		$sut = $this->get_api( settings: $settings );

		$datetime = new \DateTime();

		$expected_key = 'plugin-slug_mailbox_last_fetched_brianhenryie@gmail.com';

		\WP_Mock::userFunction(
			'update_option',
			array(
				'args'  => array(
					$expected_key,
					\WP_Mock\Functions::type( 'string' ),
				),
				'times' => 1,
			)
		);

		$sut->set_last_fetched_time( 'brianhenryie@gmail.com', $datetime );
	}

	/**
	 * @covers ::get_last_fetched_times
	 * @covers ::get_last_fetched_option_name
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

		$expected_key = 'plugin-slug_mailbox_last_fetched_brianhenryie@gmail.com';

		$result = $sut->get_last_fetched_times();

		$this->assertArrayHasKey( 'brianhenryie@gmail.com', $result );
		// Assert there is less than one second difference between the two. (because saving as ATOM time loses the microseconds).

		/** @var DateTime $result_datetime */
		$result_datetime = $result['brianhenryie@gmail.com'];
		$difference      = $result_datetime->format( 'U' ) - $datetime->format( 'U' );
		$this->assertEquals( 0, $difference );
	}

	/**
	 * Pretty much the same test as above.
	 *
	 * @covers ::set_failed_login_time
	 */
	public function test_set_failed_login_time(): void {

		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug' => Expected::atLeastOnce(
					fn() => 'plugin-slug'
				),
			)
		);

		$sut = $this->get_api( settings: $settings );

		$datetime = new \DateTime();

		$expected_key = 'plugin-slug_mailbox_last_failure_brianhenryie@gmail.com';

		\WP_Mock::userFunction(
			'update_option',
			array(
				'args'  => array(
					$expected_key,
					\WP_Mock\Functions::type( 'string' ),
				),
				'times' => 1,
			)
		);

		$sut->set_failed_login_time( 'brianhenryie@gmail.com', $datetime );
	}

	/**
	 * Pretty much the same test as above.
	 *
	 * @covers ::set_failed_login_time
	 */
	public function test_set_failed_login_time_delete(): void {

		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug' => Expected::atLeastOnce(
					fn() => 'plugin-slug'
				),
			)
		);

		$sut = $this->get_api( settings: $settings );

		$expected_key = 'plugin-slug_mailbox_last_failure_brianhenryie@gmail.com';

		\WP_Mock::userFunction(
			'delete_option',
			array(
				'args'  => array(
					$expected_key,
				),
				'times' => 1,
			)
		);

		$sut->set_failed_login_time( 'brianhenryie@gmail.com', null );
	}

	/**
	 * Need to test the date interval.
	 *
	 * Set a failure date one day in the past.
	 * The function should recognise this as over 6 hours ago!
	 * It should delete the saved option.
	 * And return null.
	 *
	 * @covers ::get_last_failed_login_time
	 * @covers ::get_last_failed_login_option_name
	 */
	public function test_get_last_failed_login_time(): void {

		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug' => Expected::atLeastOnce(
					fn() => 'plugin-slug'
				),
			)
		);

		$sut = $this->get_api( settings: $settings );

		$expected_key = 'plugin-slug_mailbox_last_failure_account';

		// One month in the past.
		$return = new DateTime()->sub( new \DateInterval( 'P1D' ) )->format( DateTime::ATOM );
		\WP_Mock::userFunction(
			'get_option',
			array(
				'args'   => array(
					$expected_key,
					null,
				),
				'return' => $return,
				'times'  => 1,
			)
		);

		\WP_Mock::userFunction(
			'delete_option',
			array(
				'args'  => array(
					$expected_key,
				),
				'times' => 1,
			)
		);

		$result = $sut->get_last_failed_login_time( 'account' );

		$this->assertNull( $result );
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
}
