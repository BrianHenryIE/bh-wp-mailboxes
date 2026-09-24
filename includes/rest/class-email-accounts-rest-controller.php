<?php
/**
 * REST routes for one mailbox's accounts: save (upsert), test connection, check, enable/disable, delete.
 *
 * Everything credential-adjacent requires the mailbox's manage-accounts capability. Responses carry the
 * re-rendered accounts table as `table_html`, as the admin-ajax responses did, so the admin JavaScript
 * swaps it in place.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\REST;

use BrianHenryIE\WP_Mailboxes\Admin\Email_Account_Manager;
use BrianHenryIE\WP_Mailboxes\Admin\Status_View;
use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WP_Includes\Mailbox_Capabilities;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /{accounts}`, `POST /{accounts}/test-connection`, `POST /{accounts}/{id}/check`,
 * `POST /{accounts}/{id}/active`, `DELETE /{accounts}/{id}`.
 */
class Email_Accounts_REST_Controller extends Mailbox_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @param API_Interface                      $api          Main API instance.
	 * @param Email_Account_Manager              $manager      Validates, saves and tests accounts.
	 * @param Status_View                        $status_view  Renders the refreshed accounts table for responses.
	 * @param BH_WP_Mailboxes_Settings_Interface $settings     Names the mailbox's post types and REST namespace.
	 * @param Mailbox_Capabilities               $capabilities Decides who may manage accounts.
	 * @param LoggerInterface                    $logger       PSR-3 logger.
	 */
	public function __construct(
		protected API_Interface $api,
		protected Email_Account_Manager $manager,
		protected Status_View $status_view,
		BH_WP_Mailboxes_Settings_Interface $settings,
		Mailbox_Capabilities $capabilities,
		LoggerInterface $logger,
	) {
		parent::__construct( $settings, $capabilities, $logger );
		$this->rest_base = $settings->get_email_accounts_cpt_dashed();
	}

	/**
	 * Register the routes.
	 *
	 * @hooked rest_api_init
	 */
	public function register_routes(): void {
		$account_fields = array(
			'email_address' => array(
				'type'     => 'string',
				'required' => true,
			),
			'display_name'  => array(
				'type'    => 'string',
				'default' => '',
			),
			'server'        => array(
				'type'    => 'string',
				'default' => '',
			),
			'username'      => array(
				'type'    => 'string',
				'default' => '',
			),
			'password'      => array(
				'type'    => 'string',
				'default' => '',
			),
			'encryption'    => array(
				'type'    => 'string',
				'default' => 'TLS',
			),
			'validate_cert' => array(
				'type'    => 'boolean',
				'default' => true,
			),
		);
		$id_arg         = array(
			'id' => array(
				'description' => __( 'The account post id.', 'bh-wp-mailboxes' ),
				'type'        => 'integer',
				'required'    => true,
				'minimum'     => 1,
			),
		);

		register_rest_route(
			$this->route_namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => $this->save( ... ),
					'permission_callback' => $this->manage_permissions_check( ... ),
					'args'                => $account_fields,
				),
			)
		);

		register_rest_route(
			$this->route_namespace,
			'/' . $this->rest_base . '/test-connection',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => $this->test_connection( ... ),
					'permission_callback' => $this->manage_permissions_check( ... ),
					'args'                => $account_fields,
				),
			)
		);

		register_rest_route(
			$this->route_namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => $this->delete_item( ... ),
					'permission_callback' => $this->item_permissions_check( ... ),
					'args'                => $id_arg,
				),
			)
		);

		register_rest_route(
			$this->route_namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/check',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => $this->check( ... ),
					'permission_callback' => $this->item_permissions_check( ... ),
					'args'                => $id_arg + array(
						'since_date' => array(
							'description' => __( 'Fetch emails since this date (Y-m-d) instead of the last successful check.', 'bh-wp-mailboxes' ),
							'type'        => 'string',
							'format'      => 'date',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->route_namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/active',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => $this->set_active( ... ),
					'permission_callback' => $this->item_permissions_check( ... ),
					'args'                => $id_arg + array(
						'active' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Every account route requires the manage-accounts capability.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 */
	public function manage_permissions_check( $request ) {
		return $this->capabilities->current_user_can_manage_email_accounts()
			? true
			: $this->forbidden( __( 'Sorry, you are not allowed to manage email accounts.', 'bh-wp-mailboxes' ) );
	}

	/**
	 * Per-id routes additionally require the id to be one of this mailbox's accounts (404 otherwise).
	 *
	 * @param WP_REST_Request $request The request, carrying `id`.
	 *
	 * @return true|WP_Error
	 */
	public function item_permissions_check( $request ) {
		$allowed = $this->manage_permissions_check( $request );
		if ( true !== $allowed ) {
			return $allowed;
		}

		return is_null( $this->manager->find_account_by_post_id( $this->request_id( $request ) ) )
			? $this->not_found( __( 'Account not found.', 'bh-wp-mailboxes' ) )
			: true;
	}

	/**
	 * Add or edit an IMAP account (upsert keyed by email address) and test its connection.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function save( $request ): WP_REST_Response|WP_Error {
		try {
			$result = $this->manager->save( ...$this->account_fields( $request ) );
		} catch ( InvalidArgumentException $exception ) {
			return new WP_Error( 'bh_wp_mailboxes_invalid_account', $exception->getMessage(), array( 'status' => 400 ) );
		} catch ( Throwable $exception ) {
			$this->logger->error( 'Failed to save email account: ' . $exception->getMessage(), array( 'exception' => $exception ) );

			return new WP_Error( 'bh_wp_mailboxes_save_failed', __( 'The account could not be saved.', 'bh-wp-mailboxes' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'account_post_id' => $result->account->get_post_id(),
				'created'         => $result->created,
				'connection'      => array(
					'success' => $result->connection->success,
					'message' => $result->connection->message,
				),
				'table_html'      => $this->render_table(),
			),
			$result->created ? 201 : 200
		);
	}

	/**
	 * Test the entered details without saving. A refused login or unreachable server is the outcome being
	 * reported, so the request itself succeeds with `success: false`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function test_connection( $request ): WP_REST_Response|WP_Error {
		try {
			$result = $this->manager->test_connection( ...$this->account_fields( $request ) );
		} catch ( InvalidArgumentException $exception ) {
			return new WP_Error( 'bh_wp_mailboxes_invalid_account', $exception->getMessage(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => $result->success,
				'message' => $result->message,
			)
		);
	}

	/**
	 * Fetch emails for one account now, optionally since a given date. A skipped or failed check is the
	 * outcome being reported (`success: false` with `status` skipped|failed), not a request error.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function check( $request ): WP_REST_Response|WP_Error {
		$account = $this->load_account( $request );

		if ( ! ( $this->api->get_connection_for_email_account( $account ) instanceof Supports_Fetching ) ) {
			return new WP_Error( 'bh_wp_mailboxes_receive_only', __( 'This account does not fetch email: emails are delivered to it.', 'bh-wp-mailboxes' ), array( 'status' => 400 ) );
		}

		$since     = null;
		$since_raw = $request->get_param( 'since_date' );
		if ( is_string( $since_raw ) && '' !== $since_raw ) {
			$since = DateTimeImmutable::createFromFormat( 'Y-m-d', $since_raw, new DateTimeZone( 'UTC' ) );
			if ( false === $since ) {
				return new WP_Error( 'bh_wp_mailboxes_invalid_date', __( 'Unable to parse the since date.', 'bh-wp-mailboxes' ), array( 'status' => 400 ) );
			}
		}

		$result = $this->api->check_email_for_account( $account, $since );

		if ( ! $result->success ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'message'    => $result->message ?? __( 'Check failed.', 'bh-wp-mailboxes' ),
					'status'     => $result->skipped ? 'skipped' : 'failed',
					// The re-rendered table shows the failure time and login-failure badge on the account's row.
					'table_html' => $this->render_table(),
				)
			);
		}

		return new WP_REST_Response(
			array(
				'success'             => true,
				'new_email_count'     => count( $result->bh_emails ),
				// Post IDs of the new emails, so the JS can highlight their rows in the list table.
				'new_email_ids'       => array_map( fn( $email ) => $email->get_post_id(), $result->bh_emails ),
				// Fetched but rejected by the account's regex filters, so the JS can keep the row's lifetime figures current.
				'ignored_email_count' => $result->filtered_out_count,
				'warnings'            => $result->warnings,
				/* translators: shown in the accounts table immediately after a manual check */
				'last_fetched'        => __( 'Just now', 'bh-wp-mailboxes' ),
			)
		);
	}

	/**
	 * Enable or disable an account without deleting it.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function set_active( $request ): WP_REST_Response|WP_Error {
		$account = $this->load_account( $request );
		$active  = (bool) $request->get_param( 'active' );

		try {
			$updated = $this->api->set_email_account_active( $account->email_address, $active );
		} catch ( Throwable $exception ) {
			$this->logger->error( 'Failed to update email account status: ' . $exception->getMessage(), array( 'exception' => $exception ) );

			return new WP_Error( 'bh_wp_mailboxes_status_failed', __( 'The account status could not be changed.', 'bh-wp-mailboxes' ), array( 'status' => 500 ) );
		}

		if ( is_null( $updated ) ) {
			return $this->not_found( __( 'Account not found.', 'bh-wp-mailboxes' ) );
		}

		return new WP_REST_Response(
			array(
				'active'     => $active,
				'table_html' => $this->render_table(),
			)
		);
	}

	/**
	 * Delete an account and its credentials (locally saved emails are kept). Receive-only accounts
	 * (e.g. the REST ingress) would be recreated on the next delivery, so they can only be disabled.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$account = $this->load_account( $request );

		if ( ! ( $this->api->get_connection_for_email_account( $account ) instanceof Supports_Fetching ) ) {
			return new WP_Error( 'bh_wp_mailboxes_receive_only', __( 'This account cannot be deleted: emails are delivered to it. Disable it instead.', 'bh-wp-mailboxes' ), array( 'status' => 400 ) );
		}

		if ( ! $this->api->delete_email_account( $account->email_address ) ) {
			return new WP_Error( 'bh_wp_mailboxes_delete_failed', __( 'The account could not be deleted.', 'bh-wp-mailboxes' ), array( 'status' => 500 ) );
		}

		$this->logger->info( 'Deleted email account ' . $account->display_name );

		return new WP_REST_Response(
			array(
				'deleted'    => true,
				'table_html' => $this->render_table(),
			)
		);
	}

	/**
	 * The account the permission callback already validated.
	 *
	 * @param WP_REST_Request $request The request, carrying `id`.
	 *
	 * @throws InvalidArgumentException When the account vanished between the permission check and the callback.
	 */
	protected function load_account( WP_REST_Request $request ): BH_Email_Account {
		$account = $this->manager->find_account_by_post_id( $this->request_id( $request ) );
		if ( is_null( $account ) ) {
			throw new InvalidArgumentException( 'Account not found.' );
		}

		return $account;
	}

	/**
	 * The account form fields from the request, as the manager's positional arguments.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: bool}
	 */
	protected function account_fields( WP_REST_Request $request ): array {
		$string = fn( string $key ): string => is_string( $request->get_param( $key ) ) ? (string) $request->get_param( $key ) : '';

		return array(
			$string( 'email_address' ),
			$string( 'display_name' ),
			$string( 'server' ),
			$string( 'username' ),
			$string( 'password' ),
			'' === $string( 'encryption' ) && ! $request->has_param( 'encryption' ) ? 'TLS' : $string( 'encryption' ),
			(bool) $request->get_param( 'validate_cert' ),
		);
	}

	/**
	 * The refreshed accounts table, for the JS to swap in place.
	 */
	protected function render_table(): string {
		ob_start();
		$this->status_view->render_table();

		return (string) ob_get_clean();
	}
}
