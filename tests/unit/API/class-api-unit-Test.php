<?php

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use Codeception\Stub\Expected;
use DateTime;
use DateTimeInterface;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\API
 */
class API_Unit_Test extends \Codeception\Test\Unit {

	protected function setUp() : void {
		parent::setUp();
		\WP_Mock::setUp();
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * The most simple test... no mailboxes configured.
	 * Verify the response is formatted as expected.
	 *
	 * @covers ::check_email
	 * @covers ::__construct
	 */
	public function test_check_email_no_mailboxes_configured(): void {

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_configured_mailbox_settings' => Expected::atLeastOnce(
					function() {
						return array(); }
				),
			)
		);

		$sut = new API( $settings, null, $logger );

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

		$configured_mailbox_settings = array(
			$this->makeEmpty(
				Mailbox_Settings_Interface::class,
				array(
					'get_account_unique_friendly_name' => Expected::atLeastOnce(
						function() {
							return 'Dummy Account';}
					),
					'get_credentials'                  => Expected::once(
						function() {
							return $this->makeEmpty( Account_Credentials_Interface::class ); }
					),
				)
			),
		);

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'                 => Expected::atLeastOnce(
					function() {
						return 'plugin-slug';}
				),
				'get_configured_mailbox_settings' => Expected::atLeastOnce(
					function() use ( $configured_mailbox_settings ) {
						return $configured_mailbox_settings; }
				),
			)
		);

		$sut = new API( $settings, null, $logger );

		\WP_Mock::userFunction(
			'sanitize_key',
			array(
				'return_arg' => true,
			)
		);

		\WP_Mock::userFunction(
			'get_option',
			array(
				'args'   => array( 'plugin-slug_mailbox_last_fetched_Dummy Account', null ),
				'return' => ( new DateTime() )->format( DateTime::ATOM ),
			)
		);

		\WP_Mock::userFunction(
			'get_option',
			array(
				'args'   => array( 'plugin-slug_mailbox_last_failure_Dummy Account', null ),
				'return' => ( new DateTime() )->format( DateTime::ATOM ),
			)
		);

		$sut->check_email();

		$this->assertTrue( $logger->hasInfoThatContains( 'Too soon after failed login' ) );
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
	public function test_set_last_fetched_time():void {

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug' => Expected::atLeastOnce(
					function() {
						return 'plugin-slug';
					}
				),
			)
		);

		$sut = new API( $settings, null, $logger );

		$datetime = new \DateTime();

		\WP_Mock::userFunction(
			'sanitize_key',
			array(
				'return_arg' => true,
				'times'      => 1,
			)
		);

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

		$logger = new ColorLogger();

		$account = $this->makeEmpty(
			Mailbox_Settings_Interface::class,
			array(
				'get_account_unique_friendly_name' => 'brianhenryie@gmail.com',
			)
		);

		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug'                 => Expected::atLeastOnce(
					function() {
						return 'plugin-slug';
					}
				),
				'get_configured_mailbox_settings' => array(
					$account,
				),
			)
		);

		$sut = new API( $settings, null, $logger );

		$datetime = new \DateTime();

		\WP_Mock::userFunction(
			'sanitize_key',
			array(
				'return_arg' => true,
				'times'      => 1,
			)
		);

		$expected_key = 'plugin-slug_mailbox_last_fetched_brianhenryie@gmail.com';

		\WP_Mock::userFunction(
			'get_option',
			array(
				'args'   => array(
					$expected_key,
					null,
				),
				'return' => $datetime->format( DateTimeInterface::ATOM ),
				'times'  => 1,
			)
		);

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

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug' => Expected::atLeastOnce(
					function() {
						return 'plugin-slug';
					}
				),
			)
		);

		$sut = new API( $settings, null, $logger );

		$datetime = new \DateTime();

		\WP_Mock::userFunction(
			'sanitize_key',
			array(
				'return_arg' => true,
				'times'      => 1,
			)
		);

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

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug' => Expected::atLeastOnce(
					function() {
						return 'plugin-slug';
					}
				),
			)
		);

		$sut = new API( $settings, null, $logger );

		\WP_Mock::userFunction(
			'sanitize_key',
			array(
				'return_arg' => true,
				'times'      => 1,
			)
		);

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

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_plugin_slug' => Expected::atLeastOnce(
					function() {
						return 'plugin-slug';
					}
				),
			)
		);

		$sut = new API( $settings, null, $logger );

		\WP_Mock::userFunction(
			'sanitize_key',
			array(
				'return_arg' => true,
				'times'      => 2,
			)
		);

		$expected_key = 'plugin-slug_mailbox_last_failure_account';

		// One month in the past.
		$return = ( new DateTime() )->sub( new \DateInterval( 'P1D' ) )->format( DateTime::ATOM );
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

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty( BH_WP_Mailboxes_Settings_Interface::class );

		$sut = new API( $settings, null, $logger );

		$result = $sut->get_settings();

		$this->assertInstanceOf( BH_WP_Mailboxes_Settings_Interface::class, $result );
		$this->assertEquals( $settings, $result );
	}
}
