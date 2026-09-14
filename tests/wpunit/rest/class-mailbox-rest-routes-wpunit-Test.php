<?php
/**
 * WPUnit tests for the mailbox REST routes: the authorization invariants and the response shapes.
 *
 * Two mailboxes are booted (as two consuming plugins would be), each with its own post types,
 * capabilities and routes, so the cross-mailbox test can prove a grant on one gives nothing on the other.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\REST;

use BrianHenryIE\WP_Mailboxes\Admin\Email_Account_Manager;
use BrianHenryIE\WP_Mailboxes\Admin\Status_View;
use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Email_Connection_Interface;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Check_Email_Account_Result;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Check_Mailbox_Result;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Test_Connection_Result;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account_CPT;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;
use BrianHenryIE\WP_Mailboxes\WP_Includes\Mailbox_Capabilities;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\REST\Emails_REST_Controller
 */
class Mailbox_REST_Routes_WPUnit_Test extends WPUnit_Testcase {

	/**
	 * Per-mailbox fixtures, keyed 'a' / 'b'.
	 *
	 * @var array<string, array{settings: BH_WP_Mailboxes_Settings_Interface, api: API_Interface&MockInterface, repository: Email_WP_Post_Repository, filter: callable}>
	 */
	protected array $mailboxes = array();

	protected function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$wp_rest_server = null;

		foreach ( array( 'a', 'b' ) as $name ) {
			$settings = $this->make_settings( $name );

			$capabilities = new Mailbox_Capabilities( $settings );
			$filter       = $capabilities->map_meta_cap( ... );
			add_filter( 'map_meta_cap', $filter, 10, 4 );

			$email_cpt = new BH_Email_CPT( $settings, $this->logger );
			$email_cpt->register_cpt();
			$email_cpt->register_post_statuses();
			( new BH_Email_Account_CPT( $settings, $this->logger ) )->register_cpt();

			$repository = new Email_WP_Post_Repository( "mb_{$name}_emails", new BH_Email_Factory( $this->logger ), $this->logger );

			/** @var API_Interface&MockInterface $api */
			$api = Mockery::mock( API_Interface::class );
			$api->allows( 'get_email_accounts' )->andReturn( array() )->byDefault();

			$this->mailboxes[ $name ] = compact( 'settings', 'api', 'repository', 'filter' );

			$emails   = new Emails_REST_Controller( $api, $repository, $settings, $capabilities, $this->logger );
			$accounts = new Email_Accounts_REST_Controller(
				$api,
				new Email_Account_Manager( $api, $settings, $this->logger ),
				new Status_View( $api, $settings, $repository, $this->logger ),
				$settings,
				$capabilities,
				$this->logger
			);
			add_action( 'rest_api_init', $emails->register_routes( ... ) );
			add_action( 'rest_api_init', $accounts->register_routes( ... ) );
		}

		rest_get_server();
	}

	protected function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_actions( 'rest_api_init' );
		remove_all_filters( Mailbox_Capabilities::REQUIRED_CAPABILITY_FILTER );
		foreach ( $this->mailboxes as $name => $mailbox ) {
			remove_filter( 'map_meta_cap', $mailbox['filter'], 10 );
			unregister_post_type( "mb_{$name}_emails" );
			unregister_post_type( "mb_{$name}_accounts" );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Mocked settings for one mailbox.
	 *
	 * @param string $name 'a' or 'b'.
	 */
	protected function make_settings( string $name ): BH_WP_Mailboxes_Settings_Interface {
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_plugin_slug' )->andReturn( "mailbox-{$name}" );
		$settings->allows( 'get_rest_namespace' )->andReturn( "mb-{$name}" );
		$settings->allows( 'get_emails_cpt_friendly_name' )->andReturn( "Mailbox {$name} Emails" );
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( "mb_{$name}_emails" );
		$settings->allows( 'get_emails_cpt_dashed' )->andReturn( "mb-{$name}-emails" );
		$settings->allows( 'get_email_accounts_cpt_friendly_name' )->andReturn( "Mailbox {$name} Accounts" );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( "mb_{$name}_accounts" );
		$settings->allows( 'get_email_accounts_cpt_dashed' )->andReturn( "mb-{$name}-accounts" );
		return $settings;
	}

	/**
	 * A stored email in a mailbox.
	 *
	 * @param string $name 'a' or 'b'.
	 */
	protected function make_email( string $name ): BH_Email {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => "mb_{$name}_emails",
				'post_status' => 'bh_email_new',
				'post_title'  => "Email in mailbox {$name}",
			)
		);
		update_post_meta( $post_id, 'message_id', "msg-{$post_id}@example.com" );
		update_post_meta( $post_id, 'from_email', 'sender@example.com' );

		return $this->mailboxes[ $name ]['repository']->find_by_post_id( $post_id );
	}

	/**
	 * A fetch-capable account in a mailbox, known to its mocked API.
	 *
	 * @param string $name 'a' or 'b'.
	 */
	protected function make_account( string $name ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => "mb_{$name}_accounts",
				'post_status' => 'bh_email_ac_active',
			)
		);
		$account = BH_Email_Account_Fixture::make( post_id: $post_id, post_type: "mb_{$name}_accounts", email_address: "inbox-{$name}@example.com" );

		$api = $this->mailboxes[ $name ]['api'];
		$api->allows( 'get_email_accounts' )->andReturn( array( $account ) );
		$api->allows( 'get_connection_for_email_account' )->andReturn( Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class ) );

		return $post_id;
	}

	/**
	 * Log in as a new user of the role.
	 *
	 * @param string $role The role slug.
	 */
	protected function login_as( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	protected function grant_mailbox_a_to_subscribers(): void {
		add_filter(
			Mailbox_Capabilities::REQUIRED_CAPABILITY_FILTER,
			fn( string $required, string $capability, string $post_type ): string => str_starts_with( $post_type, 'mb_a_' ) ? 'read' : $required,
			10,
			3
		);
	}

	/**
	 * Dispatch a request through the REST server.
	 *
	 * @param string              $method The HTTP method.
	 * @param string              $route  The route, e.g. `/mb-a/v2/mb-a-emails/1`.
	 * @param array<string,mixed> $body   The JSON body.
	 */
	protected function request( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		if ( count( $body ) > 0 ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		$response = rest_do_request( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		return $response;
	}

	/**
	 * Every route of a mailbox, as [method, route] pairs.
	 *
	 * @param string $name       'a' or 'b'.
	 * @param int    $email_id   An email post id for the per-id routes.
	 * @param int    $account_id An account post id for the per-id routes.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	protected function all_routes( string $name, int $email_id, int $account_id ): array {
		$e = "/mb-{$name}/v2/mb-{$name}-emails";
		$a = "/mb-{$name}/v2/mb-{$name}-accounts";
		return array(
			array( 'GET', $e ),
			array( 'GET', "{$e}/{$email_id}" ),
			array( 'GET', "{$e}/{$email_id}/remote-status" ),
			array( 'POST', "{$e}/{$email_id}/mark-read" ),
			array( 'POST', "{$e}/{$email_id}/mark-unread" ),
			array( 'POST', "{$e}/{$email_id}/delete-on-server" ),
			array( 'POST', "{$e}/{$email_id}/status" ),
			array( 'DELETE', "{$e}/{$email_id}" ),
			array( 'POST', "{$e}/check" ),
			array( 'POST', $a ),
			array( 'POST', "{$a}/test-connection" ),
			array( 'POST', "{$a}/{$account_id}/check" ),
			array( 'POST', "{$a}/{$account_id}/active" ),
			array( 'DELETE', "{$a}/{$account_id}" ),
		);
	}

	/**
	 * Make the mocked API answer every command route successfully.
	 *
	 * @param string   $name  'a' or 'b'.
	 * @param BH_Email $email The email the API methods return.
	 */
	protected function allow_all_api_calls( string $name, BH_Email $email ): void {
		$api = $this->mailboxes[ $name ]['api'];
		$api->allows( 'get_downloaded_emails' )->andReturn( array( $email ) );
		$api->allows( 'get_remote_read_status' )->andReturn( true );
		$api->allows( 'mark_email_read' )->andReturn( $email );
		$api->allows( 'mark_email_unread' )->andReturn( $email );
		$api->allows( 'delete_email_on_server' )->andReturn( $email );
		$api->allows( 'update_email_local_status' )->andReturn( $email );
		$api->allows( 'check_email' )->andReturn( new Check_Mailbox_Result( success: true, accounts: array(), account_results: array() ) );
		$api->allows( 'check_email_for_account' )->andReturnUsing( fn( $account ) => new Check_Email_Account_Result( bh_account: $account, success: true ) );
		$api->allows( 'set_email_account_active' )->andReturnUsing( fn() => BH_Email_Account_Fixture::make() );
		$api->allows( 'delete_email_account' )->andReturn( true );
		$api->allows( 'configure_email_account' )->andReturnUsing( fn() => BH_Email_Account_Fixture::make() );
		$api->allows( 'save_account_credentials' );
		$api->allows( 'get_account_credentials' )->andReturn( null );
		$api->allows( 'test_connection' )->andReturn( new Test_Connection_Result( success: true, message: 'Connected successfully.' ) );
	}

	/**
	 * The invariant behind "capability checks on every read and every action": no route in either
	 * namespace is registered with `__return_true` or without a permission callback.
	 *
	 * @coversNothing
	 */
	public function test_every_route_has_a_real_permission_callback(): void {
		$routes = rest_get_server()->get_routes();
		$seen   = 0;

		foreach ( $routes as $route => $handlers ) {
			// Skip WordPress's own (public) namespace index routes, `/mb-a/v2` and `/mb-b/v2`.
			if ( ! str_starts_with( $route, '/mb-a/v2/' ) && ! str_starts_with( $route, '/mb-b/v2/' ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				if ( ! isset( $handler['callback'] ) ) {
					continue;
				}
				++$seen;
				$this->assertArrayHasKey( 'permission_callback', $handler, $route );
				$this->assertNotEmpty( $handler['permission_callback'], $route );
				$this->assertNotSame( '__return_true', $handler['permission_callback'], $route );
			}
		}

		$this->assertSame( 28, $seen, 'Fourteen endpoints per mailbox, two mailboxes.' );
	}

	/**
	 * Anonymous requests are 401 on every route.
	 *
	 * @coversNothing
	 */
	public function test_anonymous_is_401_everywhere(): void {
		$email      = $this->make_email( 'a' );
		$account_id = $this->make_account( 'a' );
		wp_set_current_user( 0 );

		foreach ( $this->all_routes( 'a', $email->get_post_id(), $account_id ) as [ $method, $route ] ) {
			$this->assertSame(
				401,
				$this->request(
					$method,
					$route,
					array(
						'status'        => 'bh_email_saved',
						'active'        => true,
						'email_address' => 'x@example.com',
					)
				)->get_status(),
				"{$method} {$route}"
			);
		}
	}

	/**
	 * A subscriber is 403 on every route.
	 *
	 * @coversNothing
	 */
	public function test_subscriber_is_403_everywhere(): void {
		$email      = $this->make_email( 'a' );
		$account_id = $this->make_account( 'a' );
		$this->login_as( 'subscriber' );

		foreach ( $this->all_routes( 'a', $email->get_post_id(), $account_id ) as [ $method, $route ] ) {
			$this->assertSame(
				403,
				$this->request(
					$method,
					$route,
					array(
						'status'        => 'bh_email_saved',
						'active'        => true,
						'email_address' => 'x@example.com',
					)
				)->get_status(),
				"{$method} {$route}"
			);
		}
	}

	/**
	 * An administrator is 2xx on every route.
	 *
	 * @coversNothing
	 */
	public function test_administrator_is_2xx_everywhere(): void {
		$email      = $this->make_email( 'a' );
		$account_id = $this->make_account( 'a' );
		$this->allow_all_api_calls( 'a', $email );
		$this->login_as( 'administrator' );

		foreach ( $this->all_routes( 'a', $email->get_post_id(), $account_id ) as [ $method, $route ] ) {
			$body = array(
				'status'        => 'bh_email_saved',
				'active'        => true,
				'email_address' => 'new@example.com',
				'server'        => 'imap.example.com',
				'password'      => 'secret',
			);
			// DELETE /{emails}/{id} trashes the email; run it last so the earlier routes still find it.
			$status = $this->request( $method, $route, $body )->get_status();
			$this->assertGreaterThanOrEqual( 200, $status, "{$method} {$route}" );
			$this->assertLessThan( 300, $status, "{$method} {$route}" );
		}
	}

	/**
	 * The one that proves "mailbox-scoped" is real: a user granted mailbox A's capabilities through the
	 * filter is 200 on A's routes and 403 on B's, for the same user.
	 *
	 * @coversNothing
	 */
	public function test_grant_on_one_mailbox_gives_nothing_on_the_other(): void {
		$email_a = $this->make_email( 'a' );
		$email_b = $this->make_email( 'b' );
		$this->allow_all_api_calls( 'a', $email_a );
		$this->allow_all_api_calls( 'b', $email_b );
		$this->grant_mailbox_a_to_subscribers();
		$this->login_as( 'subscriber' );

		$this->assertSame( 200, $this->request( 'GET', "/mb-a/v2/mb-a-emails/{$email_a->get_post_id()}" )->get_status() );
		$this->assertSame( 200, $this->request( 'POST', "/mb-a/v2/mb-a-emails/{$email_a->get_post_id()}/mark-read" )->get_status() );
		$this->assertSame( 200, $this->request( 'POST', '/mb-a/v2/mb-a-emails/check' )->get_status() );

		$this->assertSame( 403, $this->request( 'GET', "/mb-b/v2/mb-b-emails/{$email_b->get_post_id()}" )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', "/mb-b/v2/mb-b-emails/{$email_b->get_post_id()}/mark-read" )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/mb-b/v2/mb-b-emails/check' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/mb-b/v2/mb-b-accounts/test-connection', array( 'email_address' => 'x@example.com' ) )->get_status() );
	}

	/**
	 * A valid-looking id of another post type (another mailbox's email, a page) is 404 on a per-id route,
	 * even for an administrator: the route never acts on someone else's post.
	 *
	 * @covers ::item_permissions_check
	 */
	public function test_ids_of_other_post_types_are_404(): void {
		$email_b = $this->make_email( 'b' );
		$page    = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->login_as( 'administrator' );

		foreach ( array( $email_b->get_post_id(), $page, 999999 ) as $id ) {
			$this->assertSame( 404, $this->request( 'GET', "/mb-a/v2/mb-a-emails/{$id}" )->get_status(), (string) $id );
			$this->assertSame( 404, $this->request( 'POST', "/mb-a/v2/mb-a-emails/{$id}/mark-read" )->get_status(), (string) $id );
			$this->assertSame( 404, $this->request( 'POST', "/mb-a/v2/mb-a-accounts/{$id}/active", array( 'active' => true ) )->get_status(), (string) $id );
		}
	}

	/**
	 * Characterization of the response shapes the admin JavaScript reads.
	 *
	 * @covers ::get_item
	 * @covers ::get_remote_status
	 * @covers ::remote_action
	 * @covers ::update_status
	 * @covers ::check
	 */
	public function test_response_shapes(): void {
		$email = $this->make_email( 'a' );
		$this->allow_all_api_calls( 'a', $email );
		$this->login_as( 'administrator' );
		$base = "/mb-a/v2/mb-a-emails/{$email->get_post_id()}";

		$item = $this->request( 'GET', $base )->get_data();
		$this->assertSame( $email->get_post_id(), $item['id'] );
		foreach ( array( 'subject', 'from_email', 'local_status', 'is_remote_read', 'is_remote_deleted', 'body_plain_text', 'body_html', 'attachment_ids' ) as $key ) {
			$this->assertArrayHasKey( $key, $item, $key );
		}
		$this->assertArrayNotHasKey( 'body_html', $this->request( 'GET', '/mb-a/v2/mb-a-emails' )->get_data()[0], 'The list omits bodies.' );

		$this->assertSame(
			array(
				'is_read'           => true,
				'is_remote_deleted' => null,
			),
			$this->request( 'GET', "{$base}/remote-status" )->get_data()
		);
		$this->assertSame(
			array(
				'is_read'           => null,
				'is_remote_deleted' => null,
			),
			$this->request( 'POST', "{$base}/mark-read" )->get_data()
		);
		$this->assertSame( array( 'status' => 'bh_email_new' ), $this->request( 'POST', "{$base}/status", array( 'status' => 'bh_email_saved' ) )->get_data() );
		$this->assertSame( 400, $this->request( 'POST', "{$base}/status", array( 'status' => 'publish' ) )->get_status(), 'The status is validated against the enum.' );

		$check = $this->request( 'POST', '/mb-a/v2/mb-a-emails/check' )->get_data();
		$this->assertSame( array( 'success', 'new_email_count', 'new_email_ids', 'accounts' ), array_keys( $check ) );
	}

	/**
	 * A remote-action failure is a 502, not a success with a quiet log note.
	 *
	 * @covers ::remote_action
	 */
	public function test_remote_action_failure_is_502(): void {
		$email = $this->make_email( 'a' );
		$this->mailboxes['a']['api']->allows( 'mark_email_read' )->andThrow( new RuntimeException( 'IMAP said no.' ) );
		$this->login_as( 'administrator' );

		$response = $this->request( 'POST', "/mb-a/v2/mb-a-emails/{$email->get_post_id()}/mark-read" );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'IMAP said no.', $response->get_data()['message'] );
	}

	/**
	 * Account route shapes: the failed connection test and the failed check are reported in the body,
	 * not as HTTP errors; save answers 201 for a new account with the connection result and table.
	 *
	 * @coversNothing
	 */
	public function test_account_response_shapes(): void {
		$account_id = $this->make_account( 'a' );
		$api        = $this->mailboxes['a']['api'];
		$api->allows( 'test_connection' )->andReturn( new Test_Connection_Result( success: false, message: 'Connection refused' ) );
		$api->allows( 'check_email_for_account' )->andReturnUsing( fn( $account ) => new Check_Email_Account_Result( bh_account: $account, success: false, message: 'Could not fetch emails: nope' ) );
		$api->allows( 'get_account_credentials' )->andReturn( null );
		$api->allows( 'configure_email_account' )->andReturnUsing( fn() => BH_Email_Account_Fixture::make() );
		$api->allows( 'save_account_credentials' );
		$this->login_as( 'administrator' );

		$test = $this->request(
			'POST',
			'/mb-a/v2/mb-a-accounts/test-connection',
			array(
				'email_address' => 'new@example.com',
				'server'        => 'imap.example.com',
				'password'      => 'pw',
			)
		);
		$this->assertSame( 200, $test->get_status() );
		$this->assertSame(
			array(
				'success' => false,
				'message' => 'Connection refused',
			),
			$test->get_data()
		);

		$check = $this->request( 'POST', "/mb-a/v2/mb-a-accounts/{$account_id}/check" );
		$this->assertSame( 200, $check->get_status() );
		$this->assertFalse( $check->get_data()['success'] );
		$this->assertSame( 'failed', $check->get_data()['status'] );
		$this->assertArrayHasKey( 'table_html', $check->get_data() );

		$this->assertSame( 400, $this->request( 'POST', "/mb-a/v2/mb-a-accounts/{$account_id}/check", array( 'since_date' => 'not-a-date' ) )->get_status() );

		$save = $this->request(
			'POST',
			'/mb-a/v2/mb-a-accounts',
			array(
				'email_address' => 'new@example.com',
				'server'        => 'imap.example.com',
				'password'      => 'pw',
			)
		);
		$this->assertSame( 201, $save->get_status() );
		$this->assertSame( array( 'account_post_id', 'created', 'connection', 'table_html' ), array_keys( $save->get_data() ) );

		$this->assertSame( 400, $this->request( 'POST', '/mb-a/v2/mb-a-accounts', array( 'email_address' => 'not-an-email' ) )->get_status(), 'Validation failures are 400.' );
	}
}
