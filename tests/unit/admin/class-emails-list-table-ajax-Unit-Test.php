<?php

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Check_Email_Account_Result;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Check_Mailbox_Result;
use BrianHenryIE\WP_Mailboxes\API\New_Email_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Fixture;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use Codeception\Stub\Expected;
use Mockery;

/**
 * @coversDefaultClass  \BrianHenryIE\WP_Mailboxes\Admin\Emails_List_Table_Ajax
 */
class Emails_List_Table_Ajax_Unit_Test extends Unit_Testcase {

	/**
	 * @covers ::check_email
	 * @covers ::__construct
	 */
	public function test_check_email_happy_path(): void {

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_emails_cpt_underscored_20' => Expected::once(
					fn() => 'mailboxes_cpt'
				),
			)
		);
		$api      = $this->makeEmpty(
			API_Interface::class,
			array(
				'check_email' => Expected::once(
					fn() => new Check_Mailbox_Result( success: true, accounts: array(), account_results: array() )
				),
			)
		);

		$sut = new Emails_List_Table_Ajax( $api, $settings, $logger );

		// Arrange.

		$_POST['_wpnonce']      = '_wpnonce';
		$_POST['mailboxes_cpt'] = 'mailboxes_cpt';

		\WP_Mock::userFunction(
			'wp_verify_nonce',
			array(
				'return' => true,
				'times'  => 1,
			)
		);

		\WP_Mock::userFunction(
			'wp_send_json_success',
			array(
				'args'  => array( \WP_Mock\Functions::type( 'array' ) ),
				'times' => 1,
			)
		);

		$sut->check_email();
	}

	/**
	 * A failed account is reported in the error payload by name, with its message, alongside the
	 * accounts that succeeded, so the "Check all" notice can say which account needs attention.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_failure_payload_names_the_failed_account(): void {

		$logger       = new ColorLogger();
		$settings     = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array( 'get_emails_cpt_underscored_20' => fn() => 'mailboxes_cpt' )
		);

		$good_account = BH_Email_Account_Fixture::make( post_id: 11, email_address: 'good@example.com', display_name: 'Good' );
		$bad_account  = BH_Email_Account_Fixture::make( post_id: 12, email_address: 'bad@example.com', display_name: 'Bad' );

		$bh_email  = BH_Email_Fixture::new( post_id: 99, post_type: 'test_emails', email_account_local_id: 2, imessage: Mockery::mock( \ZBateson\MailMimeParser\IMessage::class ), message_id: 'm@example.org', subject: 'Hi', from_email: 'a@example.org' );
		$new_email = Mockery::mock( New_Email_Interface::class );
		$new_email->allows( 'get_email' )->andReturn( $bh_email );

		$api = $this->makeEmpty(
			API_Interface::class,
			array(
				'check_email' => Expected::once(
					fn() => new Check_Mailbox_Result(
						success: false,
						accounts: array( $good_account, $bad_account ),
						account_results: array(
							new Check_Email_Account_Result( bh_account: $good_account, success: true, bh_emails: array( $bh_email ), new_emails: array( $new_email ) ),
							new Check_Email_Account_Result( bh_account: $bad_account, success: false, message: 'Could not fetch emails: AUTHENTICATIONFAILED' ),
						)
					)
				),
			)
		);

		$sut = new Emails_List_Table_Ajax( $api, $settings, $logger );

		$_POST['_wpnonce']      = '_wpnonce';
		$_POST['mailboxes_cpt'] = 'mailboxes_cpt';

		\WP_Mock::userFunction( 'wp_verify_nonce', array( 'return' => true ) );
		\WP_Mock::userFunction( 'wp_send_json_success', array( 'times' => 0 ) );
		\WP_Mock::userFunction( '_n', array( 'return_arg' => 0 ) );

		$payload = null;
		\WP_Mock::userFunction( 'wp_send_json_error' )->once()->andReturnUsing(
			function ( array $data ) use ( &$payload ) {
				$payload = $data;
			}
		);

		$sut->check_email();

		$this->assertIsArray( $payload );
		$this->assertSame( 1, $payload['new_email_count'] );
		$this->assertSame( array( 99 ), $payload['new_email_ids'] );
		$this->assertSame( '1 of 2 accounts could not be checked.', $payload['message'] );

		$this->assertCount( 2, $payload['accounts'] );
		$this->assertSame( 'success', $payload['accounts'][0]['status'] );
		$this->assertSame( 1, $payload['accounts'][0]['new_email_count'] );
		$this->assertSame( 'failed', $payload['accounts'][1]['status'] );
		$this->assertSame( 'Bad', $payload['accounts'][1]['name'] );
		$this->assertSame( 'bad@example.com', $payload['accounts'][1]['email_address'] );
		$this->assertSame( 'Could not fetch emails: AUTHENTICATIONFAILED', $payload['accounts'][1]['message'] );
	}

	/**
	 * A skipped account (e.g. disabled) is a success overall, but is still listed with status "skipped".
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_success_payload_lists_skipped_accounts(): void {

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array( 'get_emails_cpt_underscored_20' => fn() => 'mailboxes_cpt' )
		);
		$account  = BH_Email_Account_Fixture::make( local_status: 'bh_email_ac_inactive' );

		$api = $this->makeEmpty(
			API_Interface::class,
			array(
				'check_email' => fn() => new Check_Mailbox_Result(
					success: true,
					accounts: array( $account ),
					account_results: array(
						new Check_Email_Account_Result( bh_account: $account, success: false, skipped: true, message: 'The account is disabled.' ),
					)
				),
			)
		);

		$sut = new Emails_List_Table_Ajax( $api, $settings, $logger );

		$_POST['_wpnonce']      = '_wpnonce';
		$_POST['mailboxes_cpt'] = 'mailboxes_cpt';

		\WP_Mock::userFunction( 'wp_verify_nonce', array( 'return' => true ) );
		\WP_Mock::userFunction( 'wp_send_json_error', array( 'times' => 0 ) );

		$payload = null;
		\WP_Mock::userFunction( 'wp_send_json_success' )->once()->andReturnUsing(
			function ( array $data ) use ( &$payload ) {
				$payload = $data;
			}
		);

		$sut->check_email();

		$this->assertSame( 0, $payload['new_email_count'] );
		$this->assertSame( 'skipped', $payload['accounts'][0]['status'] );
		$this->assertSame( 'The account is disabled.', $payload['accounts'][0]['message'] );
	}

	/**
	 * @covers ::check_email
	 */
	public function test_check_email_api_returns_failure(): void {

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_emails_cpt_underscored_20' => Expected::once(
					fn() => 'mailboxes_cpt'
				),
			)
		);
		$account  = BH_Email_Account_Fixture::make();
		$api      = $this->makeEmpty(
			API_Interface::class,
			array(
				'check_email' => Expected::once(
					fn() => new Check_Mailbox_Result( success: false, accounts: array(), account_results: array() )
				),
			)
		);

		$sut = new Emails_List_Table_Ajax( $api, $settings, $logger );

		// Arrange.

		$_POST['_wpnonce']      = '_wpnonce';
		$_POST['mailboxes_cpt'] = 'mailboxes_cpt';

		\WP_Mock::userFunction(
			'sanitize_key',
			array(
				'return_arg' => 1,
			)
		);

		\WP_Mock::userFunction(
			'wp_verify_nonce',
			array(
				'return' => true,
				'times'  => 1,
			)
		);

		\WP_Mock::userFunction(
			'wp_send_json_success',
			array(
				'times' => 0,
			)
		);

		\WP_Mock::userFunction(
			'wp_send_json_error',
			array(
				'args'  => array( \WP_Mock\Functions::type( 'array' ) ),
				'times' => 1,
			)
		);

		// Act.

		$sut->check_email();
	}


	/**
	 * If the nonce fails, none of the usually expected functions will be called.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_nonce_failure(): void {

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_underscored_20' => Expected::never(),
			)
		);
		$api      = $this->makeEmpty(
			API_Interface::class,
			array(
				'check_email' => Expected::never(),
			)
		);

		$sut = new Emails_List_Table_Ajax( $api, $settings, $logger );

		// Arrange.

		$_POST['_wpnonce']      = '_wpnonce';
		$_POST['mailboxes_cpt'] = 'mailboxes_cpt';

		\WP_Mock::userFunction(
			'sanitize_key',
			array(
				'return_arg' => true,
			)
		);

		\WP_Mock::userFunction(
			'wp_verify_nonce',
			array(
				'return' => false,
				'times'  => 1,
			)
		);

		\WP_Mock::userFunction(
			'wp_send_json_success',
			array(
				'times' => 0,
			)
		);

		\WP_Mock::userFunction(
			'wp_send_json_error',
			array(
				'times' => 0,
			)
		);

		// Act.

		$sut->check_email();
	}


	/**
	 * If the cpt does not match, none of the usually expected functions will be called.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_cpt_mismatch(): void {

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_underscored_20' => Expected::once(
					fn() => 'mailboxes_cpt'
				),
			)
		);
		$api      = $this->makeEmpty(
			API_Interface::class,
			array(
				'check_email' => Expected::never(),
			)
		);

		$sut = new Emails_List_Table_Ajax( $api, $settings, $logger );

		// Arrange.

		$_POST['_wpnonce']      = '_wpnonce';
		$_POST['mailboxes_cpt'] = 'mailboxes_cpt_is_wrong_for_this_test';

		\WP_Mock::userFunction(
			'sanitize_key',
			array(
				'return_arg' => true,
			)
		);

		\WP_Mock::userFunction(
			'wp_verify_nonce',
			array(
				'return' => true,
				'times'  => 1,
			)
		);

		\WP_Mock::userFunction(
			'wp_send_json_success',
			array(
				'times' => 0,
			)
		);

		\WP_Mock::userFunction(
			'wp_send_json_error',
			array(
				'times' => 0,
			)
		);

		// Act.

		$sut->check_email();
	}
}
