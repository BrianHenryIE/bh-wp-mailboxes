<?php
/**
 * Unit tests for the API's use of the credentials store: the delegating accessors, discarding
 * credentials with a deleted account, reading saved credentials for remote actions, and saving
 * credentials a connection refreshed during a fetch or connection test.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Factories\New_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\Models\BH_WP_Mailboxes_Settings_Fixture;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use BrianHenryIE\WP_Private_Uploads\API\API as Private_Uploads;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Mockery;
use ReflectionProperty;
use RuntimeException;
use WP_Mock;
use ZBateson\MailMimeParser\IMessage;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\API
 */
class API_Credentials_Unit_Test extends Unit_Testcase {

	/**
	 * @param BH_WP_Mailboxes_Settings_Interface|null $settings                 Defaults to the fixture (plugin slug `test-plugin`, emails CPT `test_emails`).
	 * @param Email_WP_Post_Repository|null           $email_repository         Defaults to a bare mock.
	 * @param Email_Account_WP_Post_Repository|null   $email_account_repository Defaults to a bare mock.
	 * @param Credentials_Store_Interface|null        $credentials_store        Defaults to a bare mock; null to let the API build its default store.
	 * @param bool                                    $default_store            True to pass no store at all.
	 */
	protected function get_api(
		?BH_WP_Mailboxes_Settings_Interface $settings = null,
		?Email_WP_Post_Repository $email_repository = null,
		?Email_Account_WP_Post_Repository $email_account_repository = null,
		?Credentials_Store_Interface $credentials_store = null,
		bool $default_store = false,
	): API {
		return new API(
			$settings ?? BH_WP_Mailboxes_Settings_Fixture::make(),
			$email_repository ?? Mockery::mock( Email_WP_Post_Repository::class ),
			$email_account_repository ?? Mockery::mock( Email_Account_WP_Post_Repository::class ),
			new New_Email_Factory(),
			Mockery::mock( Private_Uploads::class ),
			$this->logger,
			$default_store ? null : ( $credentials_store ?? Mockery::mock( Credentials_Store_Interface::class ) ),
		);
	}

	/**
	 * A fetching connection that requires credentials and may report refreshed ones.
	 *
	 * @param mixed $refreshed What get_refreshed_credentials() returns.
	 */
	protected function make_refreshing_connection( mixed $refreshed ): Email_Connection_Interface {
		$connection = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class, Requires_Credentials::class, Refreshes_Credentials::class );
		$connection->allows( 'set_credentials' );
		$connection->allows( 'get_refreshed_credentials' )->andReturn( $refreshed );
		return $connection;
	}

	/**
	 * Have the connection filter answer $connection for $account.
	 *
	 * @param BH_Email_Account           $account    The account the filter is asked about.
	 * @param Email_Connection_Interface $connection The connection to answer with.
	 * @param string                     $emails_cpt The emails post type the filter is called with.
	 */
	protected function use_connection( BH_Email_Account $account, Email_Connection_Interface $connection, string $emails_cpt = 'test_emails' ): void {
		WP_Mock::onFilter( 'bh_wp_mailboxes_connection_for_account' )
			->with( null, 'test-plugin', $emails_cpt, $account )
			->reply( $connection );
	}

	/**
	 * Without an injected store the API builds the Secrets API one.
	 *
	 * @covers ::__construct
	 */
	public function test_defaults_to_the_secrets_credentials_store(): void {
		$sut = $this->get_api( default_store: true );

		$property = new ReflectionProperty( API::class, 'credentials_store' );

		$this->assertInstanceOf( Secrets_Credentials_Store::class, $property->getValue( $sut ) );
	}

	/**
	 * @covers ::get_account_credentials
	 * @covers ::save_account_credentials
	 * @covers ::delete_account_credentials
	 */
	public function test_accessors_delegate_to_the_store(): void {
		$account     = BH_Email_Account_Fixture::make();
		$credentials = Mockery::mock( Account_Credentials_Interface::class );

		$store = Mockery::mock( Credentials_Store_Interface::class );
		$store->expects( 'get' )->with( $account )->once()->andReturn( $credentials );
		$store->expects( 'save' )->with( $account, $credentials )->once();
		$store->expects( 'delete' )->with( $account )->once();

		$sut = $this->get_api( credentials_store: $store );

		$this->assertSame( $credentials, $sut->get_account_credentials( $account ) );
		$sut->save_account_credentials( $account, $credentials );
		$sut->delete_account_credentials( $account );
	}

	/**
	 * Store exceptions from the accessors reach the caller unchanged (the AJAX handler and CLI report them).
	 *
	 * @covers ::save_account_credentials
	 */
	public function test_save_account_credentials_propagates_store_exceptions(): void {
		$store = Mockery::mock( Credentials_Store_Interface::class );
		$store->allows( 'save' )->andThrow( new RuntimeException( 'The Secrets API is not available; credentials cannot be saved.' ) );

		$this->expectException( RuntimeException::class );
		$this->get_api( credentials_store: $store )->save_account_credentials( BH_Email_Account_Fixture::make(), Mockery::mock( Account_Credentials_Interface::class ) );
	}

	/**
	 * @covers ::delete_email_account
	 */
	public function test_delete_email_account_discards_its_credentials(): void {
		$account = BH_Email_Account_Fixture::make( email_address: 'gone@example.com' );

		$repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$repository->expects( 'find_by_email_address' )->with( 'gone@example.com' )->andReturn( $account );
		$repository->expects( 'delete' )->with( $account )->andReturn( true );

		$store = Mockery::mock( Credentials_Store_Interface::class );
		$store->expects( 'delete' )->with( $account )->once();

		$this->assertTrue( $this->get_api( email_account_repository: $repository, credentials_store: $store )->delete_email_account( 'gone@example.com' ) );
	}

	/**
	 * The account post is gone, so a failure to discard the credentials is logged, not thrown.
	 *
	 * @covers ::delete_email_account
	 */
	public function test_delete_email_account_logs_when_credentials_cannot_be_discarded(): void {
		$account = BH_Email_Account_Fixture::make( email_address: 'gone@example.com' );

		$repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$repository->allows( 'find_by_email_address' )->andReturn( $account );
		$repository->allows( 'delete' )->andReturn( true );

		$store = Mockery::mock( Credentials_Store_Interface::class );
		$store->allows( 'delete' )->andThrow( new RuntimeException( 'Store down.' ) );

		$this->assertTrue( $this->get_api( email_account_repository: $repository, credentials_store: $store )->delete_email_account( 'gone@example.com' ) );
		$this->assertTrue( $this->logger->hasErrorThatContains( 'Deleted account gone@example.com but failed to discard its credentials: Store down.' ) );
	}

	/**
	 * Credentials are kept when the account post could not be deleted.
	 *
	 * @covers ::delete_email_account
	 */
	public function test_delete_email_account_keeps_credentials_when_the_post_is_not_deleted(): void {
		$account = BH_Email_Account_Fixture::make( email_address: 'kept@example.com' );

		$repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$repository->allows( 'find_by_email_address' )->andReturn( $account );
		$repository->allows( 'delete' )->andReturn( false );

		$store = Mockery::mock( Credentials_Store_Interface::class );
		$store->expects( 'delete' )->never();

		$this->assertFalse( $this->get_api( email_account_repository: $repository, credentials_store: $store )->delete_email_account( 'kept@example.com' ) );
	}

	/**
	 * @covers ::delete_email_account
	 */
	public function test_delete_email_account_unknown_address_touches_nothing(): void {
		$repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$repository->allows( 'find_by_email_address' )->andReturn( null );
		$repository->expects( 'delete' )->never();

		$store = Mockery::mock( Credentials_Store_Interface::class );
		$store->expects( 'delete' )->never();

		$this->assertFalse( $this->get_api( email_account_repository: $repository, credentials_store: $store )->delete_email_account( 'nobody@example.com' ) );
	}

	/**
	 * A remote action reads the account's saved credentials; with none saved it fails clearly.
	 *
	 * @covers ::set_connection_credentials
	 * @covers ::mark_email_read
	 */
	public function test_remote_action_without_saved_credentials_throws(): void {
		$account = BH_Email_Account_Fixture::make( post_id: 321, display_name: 'Payments inbox' );
		$email   = new BH_Email( 5, 'test_emails', 321, Mockery::mock( IMessage::class ), 'msg-id', 'Subject', 'from@example.com', remote_coordinates: new Remote_Email_Coordinates( 'msg-id' ) );

		$repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$repository->allows( 'find_by_post_id' )->with( 321 )->andReturn( $account );

		$connection = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class, Requires_Credentials::class );
		$connection->expects( 'set_credentials' )->never();
		$connection->expects( 'set_is_marked_read' )->never();
		$this->use_connection( $account, $connection );

		$store = Mockery::mock( Credentials_Store_Interface::class );
		$store->expects( 'get' )->with( $account )->once()->andReturn( null );

		WP_Mock::passthruFunction( 'esc_html' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'No credentials are saved for Payments inbox.' );
		$this->get_api( email_account_repository: $repository, credentials_store: $store )->mark_email_read( $email );
	}

	/**
	 * The saved credentials are applied to the connection before a remote action.
	 *
	 * @covers ::set_connection_credentials
	 * @covers ::mark_email_read
	 */
	public function test_remote_action_applies_saved_credentials(): void {
		$account     = BH_Email_Account_Fixture::make( post_id: 321 );
		$credentials = Mockery::mock( Account_Credentials_Interface::class );
		$email       = new BH_Email( 5, 'test_emails', 321, Mockery::mock( IMessage::class ), 'msg-id', 'Subject', 'from@example.com', remote_coordinates: new Remote_Email_Coordinates( 'msg-id' ) );

		$account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$account_repository->allows( 'find_by_post_id' )->with( 321 )->andReturn( $account );

		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$email_repository->allows( 'update' )->andReturn( $email );
		$email_repository->allows( 'find_by_post_id' )->with( 5 )->andReturn( $email );
		$email_repository->expects( 'log' )->withArgs( fn( BH_Email $logged, string $message ): bool => $logged === $email && 'Marked as read on server' === $message )->once();

		$connection = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class, Requires_Credentials::class );
		$connection->expects( 'set_credentials' )->with( $credentials )->once();
		$connection->expects( 'set_is_marked_read' )->once();
		$this->use_connection( $account, $connection );

		$store = Mockery::mock( Credentials_Store_Interface::class );
		$store->allows( 'get' )->with( $account )->andReturn( $credentials );

		$result = $this->get_api( email_repository: $email_repository, email_account_repository: $account_repository, credentials_store: $store )->mark_email_read( $email );

		$this->assertSame( $email, $result );
	}

	/**
	 * Credentials a connection refreshed during a fetch (e.g. a new Gmail access token) are saved.
	 *
	 * @covers ::fetch_for_account
	 * @covers ::save_refreshed_credentials
	 */
	public function test_fetch_saves_refreshed_credentials(): void {
		$account     = BH_Email_Account_Fixture::make();
		$credentials = Mockery::mock( Account_Credentials_Interface::class );
		$refreshed   = Mockery::mock( Account_Credentials_Interface::class );

		$connection = $this->make_refreshing_connection( $refreshed );
		$connection->expects( 'retrieve_emails' )->andReturn( new Collection() );
		$this->use_connection( $account, $connection );

		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$email_repository->allows( 'save_all' )->andReturn( array() );
		$account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$account_repository->allows( 'update' )->andReturnArg( 0 );

		$store = Mockery::mock( Credentials_Store_Interface::class );
		$store->allows( 'get' )->with( $account )->andReturn( $credentials );
		$store->expects( 'save' )->with( $account, $refreshed )->once();

		$this->get_api( email_repository: $email_repository, email_account_repository: $account_repository, credentials_store: $store )->check_email_for_account( $account );
	}

	/**
	 * Nothing is written when the connection did not refresh anything, or cannot refresh at all.
	 *
	 * @covers ::fetch_for_account
	 * @covers ::save_refreshed_credentials
	 */
	public function test_fetch_does_not_save_when_nothing_was_refreshed(): void {
		$account = BH_Email_Account_Fixture::make();

		$connection = $this->make_refreshing_connection( null );
		$connection->allows( 'retrieve_emails' )->andReturn( new Collection() );
		$this->use_connection( $account, $connection );

		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$email_repository->allows( 'save_all' )->andReturn( array() );
		$account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$account_repository->allows( 'update' )->andReturnArg( 0 );

		$store = Mockery::mock( Credentials_Store_Interface::class );
		$store->allows( 'get' )->andReturn( Mockery::mock( Account_Credentials_Interface::class ) );
		$store->expects( 'save' )->never();

		$this->get_api( email_repository: $email_repository, email_account_repository: $account_repository, credentials_store: $store )->check_email_for_account( $account );
	}

	/**
	 * A failed fetch does not save (the token was not usable) and does not consult the connection for refreshed credentials.
	 *
	 * @covers ::fetch_for_account
	 */
	public function test_failed_fetch_does_not_save_refreshed_credentials(): void {
		$account = BH_Email_Account_Fixture::make();

		$connection = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class, Requires_Credentials::class, Refreshes_Credentials::class );
		$connection->allows( 'set_credentials' );
		$connection->allows( 'retrieve_emails' )->andThrow( new \Exception( 'AUTHENTICATIONFAILED' ) );
		$connection->expects( 'get_refreshed_credentials' )->never();
		$this->use_connection( $account, $connection );

		$account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$account_repository->allows( 'update' )->andReturnArg( 0 );

		$store = Mockery::mock( Credentials_Store_Interface::class );
		$store->allows( 'get' )->andReturn( Mockery::mock( Account_Credentials_Interface::class ) );
		$store->expects( 'save' )->never();

		$this->get_api( email_account_repository: $account_repository, credentials_store: $store )->check_email_for_account( $account );
	}

	/**
	 * A store failure while saving refreshed credentials is logged; the fetched emails are still returned.
	 *
	 * @covers ::save_refreshed_credentials
	 */
	public function test_failure_to_save_refreshed_credentials_is_logged_not_thrown(): void {
		$account = BH_Email_Account_Fixture::make( display_name: 'Payments inbox' );

		$connection = $this->make_refreshing_connection( Mockery::mock( Account_Credentials_Interface::class ) );
		$connection->allows( 'retrieve_emails' )->andReturn( new Collection() );
		$this->use_connection( $account, $connection );

		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$email_repository->allows( 'save_all' )->andReturn( array() );
		$account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$account_repository->allows( 'update' )->andReturnArg( 0 );

		$store = Mockery::mock( Credentials_Store_Interface::class );
		$store->allows( 'get' )->andReturn( Mockery::mock( Account_Credentials_Interface::class ) );
		$store->allows( 'save' )->andThrow( new RuntimeException( 'Store down.' ) );

		$result = $this->get_api( email_repository: $email_repository, email_account_repository: $account_repository, credentials_store: $store )->check_email_for_account( $account );

		$this->assertTrue( $result->success );
		$this->assertTrue( $this->logger->hasErrorThatContains( 'Failed to save the refreshed credentials for Payments inbox: Store down.' ) );
		// The fetch itself succeeded, so this is reported as a warning rather than a failure.
		$this->assertCount( 1, $result->warnings );
		$this->assertStringContainsString( 'refreshed credentials could not be saved', $result->warnings[0] );
		$this->assertStringContainsString( 'Store down.', $result->warnings[0] );
	}

	/**
	 * A successful connection test also saves credentials the connection refreshed.
	 *
	 * @covers ::test_connection
	 * @covers ::save_refreshed_credentials
	 */
	public function test_connection_test_saves_refreshed_credentials(): void {
		$account   = BH_Email_Account_Fixture::make();
		$refreshed = Mockery::mock( Account_Credentials_Interface::class );

		$connection = $this->make_refreshing_connection( $refreshed );
		$connection->expects( 'test_connection' )->andReturn( true );
		$this->use_connection( $account, $connection );

		$store = Mockery::mock( Credentials_Store_Interface::class );
		$store->allows( 'get' )->with( $account )->andReturn( Mockery::mock( Account_Credentials_Interface::class ) );
		$store->expects( 'save' )->with( $account, $refreshed )->once();

		$this->assertTrue( $this->get_api( credentials_store: $store )->test_connection( $account )->success );
	}

	/**
	 * A failed connection test saves nothing.
	 *
	 * @covers ::test_connection
	 */
	public function test_failed_connection_test_does_not_save_refreshed_credentials(): void {
		$account = BH_Email_Account_Fixture::make();

		$connection = $this->make_refreshing_connection( Mockery::mock( Account_Credentials_Interface::class ) );
		$connection->allows( 'test_connection' )->andThrow( new \Exception( 'AUTHENTICATIONFAILED' ) );
		$this->use_connection( $account, $connection );

		$store = Mockery::mock( Credentials_Store_Interface::class );
		$store->allows( 'get' )->andReturn( Mockery::mock( Account_Credentials_Interface::class ) );
		$store->expects( 'save' )->never();

		$this->assertFalse( $this->get_api( credentials_store: $store )->test_connection( $account )->success );
	}

	/**
	 * With no credentials saved the connection test reports it rather than connecting.
	 *
	 * @covers ::test_connection
	 */
	public function test_connection_test_without_saved_credentials_fails_without_connecting(): void {
		$account = BH_Email_Account_Fixture::make( display_name: 'Payments inbox' );

		$connection = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class, Requires_Credentials::class );
		$connection->expects( 'test_connection' )->never();
		$this->use_connection( $account, $connection );

		$store = Mockery::mock( Credentials_Store_Interface::class );
		$store->allows( 'get' )->andReturn( null );

		$result = $this->get_api( credentials_store: $store )->test_connection( $account );

		$this->assertFalse( $result->success );
		$this->assertSame( 'No credentials found for Payments inbox.', $result->message );
	}
}
