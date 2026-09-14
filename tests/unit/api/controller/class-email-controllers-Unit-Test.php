<?php
/**
 * Unit tests for the email controllers and their factory: the fluent/immutable contract, the remote
 * verbs and predicates, state validation, and that a remote controller stays remote through local verbs.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API\Controller;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Email_Connection_Interface;
use BrianHenryIE\WP_Mailboxes\API\Exceptions\Invalid_Email_State_Exception;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Connection;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Fixture;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use Mockery;
use Mockery\MockInterface;
use WP_Mock;
use ZBateson\MailMimeParser\IMessage;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\Controller\Remote_Email_Controller
 */
class Email_Controllers_Unit_Test extends Unit_Testcase {

	/**
	 * An email in a given remote state.
	 *
	 * @param ?bool $is_remote_read    The recorded read state.
	 * @param ?bool $is_remote_deleted The recorded deleted state.
	 * @param int   $post_id           The post id.
	 */
	private function make_email( ?bool $is_remote_read = null, ?bool $is_remote_deleted = null, int $post_id = 42 ): BH_Email {
		return BH_Email_Fixture::new(
			post_id: $post_id,
			post_type: 'test_emails',
			email_account_local_id: 7,
			imessage: Mockery::mock( IMessage::class ),
			message_id: 'm@example.org',
			subject: 'Hi',
			from_email: 'a@example.org',
			is_remote_read: $is_remote_read,
			is_remote_deleted: $is_remote_deleted,
		);
	}

	/**
	 * @return API_Interface&MockInterface
	 */
	private function make_api(): API_Interface {
		/** @var API_Interface&MockInterface $api */
		$api = Mockery::mock( API_Interface::class );
		return $api;
	}

	/**
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Controller\Email_Controller_Factory::make
	 */
	public function test_factory_picks_remote_for_fetching_connections(): void {
		$factory = new Email_Controller_Factory();
		$api     = $this->make_api();
		$email   = $this->make_email();

		$remote = $factory->make( $api, BH_Email_Account_Fixture::make( connection_type_class: ImapEngine_Imap_Email_Connection::class ), $email );
		$local  = $factory->make( $api, BH_Email_Account_Fixture::make( connection_type_class: 'Not\\A\\Class' ), $email );

		$this->assertInstanceOf( Remote_Email_Controller::class, $remote );
		$this->assertInstanceOf( Local_Email_Controller::class, $local );
		$this->assertNotInstanceOf( Remote_Email_Controller::class, $local );
		$this->assertSame( $email, $remote->get_email() );
	}

	/**
	 * Local verbs on a remote controller return a remote controller (previously they downcast to local
	 * and the remote verbs disappeared mid-chain).
	 *
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Controller\Local_Email_Controller::add_local_note
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Controller\Local_Email_Controller::update_local_status
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Controller\Local_Email_Controller::with_email
	 */
	public function test_local_verbs_on_a_remote_controller_stay_remote(): void {
		$api     = $this->make_api();
		$email   = $this->make_email();
		$updated = $this->make_email( is_remote_read: true );
		$api->allows( 'insert_email_log_note' );
		$api->allows( 'get_email' )->with( 42 )->andReturn( $updated );
		$api->allows( 'update_email_local_status' )->andReturn( $updated );

		$sut = new Remote_Email_Controller( $email, $api );

		$after_note   = $sut->add_local_note( 'noted' );
		$after_status = $sut->update_local_status( 'saved' );

		$this->assertInstanceOf( Remote_Email_Controller::class, $after_note );
		$this->assertInstanceOf( Remote_Email_Controller::class, $after_status );
		$this->assertNotSame( $sut, $after_note, 'Immutable: a new instance.' );
		$this->assertSame( $updated, $after_note->get_email(), 'The note re-reads the email, so the wrapped state is current.' );
		$this->assertSame( $updated, $after_status->get_email() );
	}

	/**
	 * The note's context reaches the API (it used to be dropped).
	 *
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Controller\Local_Email_Controller::add_local_note
	 */
	public function test_add_local_note_passes_the_context(): void {
		$api   = $this->make_api();
		$email = $this->make_email();
		$api->expects( 'insert_email_log_note' )->with( 42, 'Matched order #5', 'info', array( 'order_id' => 5 ) )->once();
		$api->allows( 'get_email' )->andReturn( null );

		$result = ( new Local_Email_Controller( $email, $api ) )->add_local_note( 'Matched order #5', 'info', array( 'order_id' => 5 ) );

		$this->assertSame( $email, $result->get_email(), 'When the email cannot be re-read the current one is kept.' );
	}

	/**
	 * @covers ::mark_read_on_server
	 * @covers ::mark_unread_on_server
	 * @covers ::delete_on_server
	 * @covers ::with_email
	 */
	public function test_remote_verbs_delegate_and_return_the_refreshed_email(): void {
		$api    = $this->make_api();
		$unread = $this->make_email( is_remote_read: false );
		$read   = $this->make_email( is_remote_read: true );
		$gone   = $this->make_email( is_remote_read: true, is_remote_deleted: true );
		$api->expects( 'mark_email_read' )->with( $unread )->once()->andReturn( $read );
		$api->expects( 'mark_email_unread' )->with( $read )->once()->andReturn( $unread );
		$api->expects( 'delete_email_on_server' )->with( $read )->once()->andReturn( $gone );

		$sut = new Remote_Email_Controller( $unread, $api );

		$marked = $sut->mark_read_on_server();
		$this->assertInstanceOf( Remote_Email_Controller::class, $marked );
		$this->assertSame( $read, $marked->get_email() );

		$this->assertSame( $unread, $marked->mark_unread_on_server()->get_email() );
		$this->assertSame( $gone, $marked->delete_on_server()->get_email() );
	}

	/**
	 * Operations that contradict the recorded state throw before touching the server.
	 *
	 * @covers ::mark_read_on_server
	 * @covers ::mark_unread_on_server
	 * @covers ::delete_on_server
	 * @covers ::assert_not_deleted_on_server
	 */
	public function test_invalid_state_transitions_throw(): void {
		$api = $this->make_api();
		$api->expects( 'mark_email_read' )->never();
		$api->expects( 'mark_email_unread' )->never();
		$api->expects( 'delete_email_on_server' )->never();

		$cases = array(
			'already read'        => array( $this->make_email( is_remote_read: true ), 'mark_read_on_server' ),
			'already unread'      => array( $this->make_email( is_remote_read: false ), 'mark_unread_on_server' ),
			'already deleted'     => array( $this->make_email( is_remote_deleted: true ), 'delete_on_server' ),
			'read after deletion' => array( $this->make_email( is_remote_deleted: true ), 'mark_read_on_server' ),
		);
		foreach ( $cases as $name => [ $email, $method ] ) {
			try {
				( new Remote_Email_Controller( $email, $api ) )->$method();
				$this->fail( "Expected Invalid_Email_State_Exception for: {$name}" );
			} catch ( Invalid_Email_State_Exception $exception ) {
				$this->assertNotEmpty( $exception->getMessage(), $name );
			}
		}
	}

	/**
	 * An unknown (null) recorded state never blocks an operation.
	 *
	 * @covers ::mark_read_on_server
	 */
	public function test_unknown_state_does_not_block(): void {
		$api   = $this->make_api();
		$email = $this->make_email();
		$api->expects( 'mark_email_read' )->once()->andReturn( $email );

		( new Remote_Email_Controller( $email, $api ) )->mark_read_on_server();
	}

	/**
	 * The predicates mirror the account's connection; false when the connection cannot be resolved.
	 *
	 * @covers ::can_mark_read_on_server
	 * @covers ::can_delete_on_server
	 * @covers ::can_read_remote_status
	 * @covers ::get_remote_read_status
	 * @covers ::connection
	 */
	public function test_predicates_mirror_the_connection(): void {
		$api        = $this->make_api();
		$email      = $this->make_email();
		$account    = BH_Email_Account_Fixture::make();
		$connection = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$connection->allows( 'can_mark_read' )->andReturn( true );
		$connection->allows( 'can_delete_on_server' )->andReturn( false );
		$connection->allows( 'can_read_status' )->andReturn( true );
		$api->allows( 'get_email_account_for_email' )->with( $email )->andReturn( $account );
		$api->allows( 'get_connection_for_email_account' )->with( $account )->andReturn( $connection );
		$api->allows( 'get_remote_read_status' )->with( $email )->andReturn( false );

		$sut = new Remote_Email_Controller( $email, $api );

		$this->assertTrue( $sut->can_mark_read_on_server() );
		$this->assertFalse( $sut->can_delete_on_server() );
		$this->assertTrue( $sut->can_read_remote_status() );
		$this->assertFalse( $sut->get_remote_read_status() );

		$orphan_api = $this->make_api();
		$orphan_api->allows( 'get_email_account_for_email' )->andReturn( null );
		$orphan = new Remote_Email_Controller( $email, $orphan_api );
		$this->assertFalse( $orphan->can_mark_read_on_server() );
		$this->assertFalse( $orphan->can_delete_on_server() );
		$this->assertFalse( $orphan->can_read_remote_status() );
	}

	/**
	 * Trash and delete call WordPress with the email's post id (delete forced, so the deletion handler runs).
	 *
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Controller\Local_Email_Controller::trash_local_email_post
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Controller\Local_Email_Controller::delete_local_email_post
	 */
	public function test_trash_and_delete_call_wordpress(): void {
		WP_Mock::userFunction( 'wp_trash_post' )->with( 42 )->once();
		WP_Mock::userFunction( 'wp_delete_post' )->with( 42, true )->once();

		$sut = new Local_Email_Controller( $this->make_email(), $this->make_api() );
		$sut->trash_local_email_post();
		$sut->delete_local_email_post();
	}

	/**
	 * The deprecated names still resolve to the controllers, so consumers' type hints keep working.
	 *
	 * @coversNothing
	 */
	public function test_deprecated_names_are_the_controllers(): void {
		$api   = $this->make_api();
		$email = $this->make_email();

		$this->assertInstanceOf( \BrianHenryIE\WP_Mailboxes\API\New_Email_Interface::class, new Local_Email_Controller( $email, $api ) );
		$this->assertInstanceOf( \BrianHenryIE\WP_Mailboxes\API\New_Email_Remote_Interface::class, new Remote_Email_Controller( $email, $api ) );
		$this->assertInstanceOf( Remote_Email_Controller::class, new \BrianHenryIE\WP_Mailboxes\API\Model\New_Email_Remote( $email, $api ) );
		$this->assertInstanceOf( Email_Controller_Factory::class, new \BrianHenryIE\WP_Mailboxes\API\Factories\New_Email_Factory() );
	}
}
