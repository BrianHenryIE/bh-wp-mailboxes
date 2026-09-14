<?php
/**
 * REST routes for one mailbox's emails: read, remote actions, local status, delete, and "check all".
 *
 * Route base is the mailbox's dashed emails post type under {@see Mailbox_REST_Controller::get_namespace()},
 * carrying the mailbox identity as the admin-ajax `_{cpt}` suffixes did. Per-id routes resolve the id
 * through {@see Mailbox_Capabilities}' per-post checks, so an id of another post type (another mailbox,
 * or any other CPT) is a 404, never an action on someone else's post.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\REST;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Check_Email_Account_Result;
use BrianHenryIE\WP_Mailboxes\API\Controller\Email_Controller_Factory;
use BrianHenryIE\WP_Mailboxes\API\Controller\Email_Controller_Interface;
use BrianHenryIE\WP_Mailboxes\API\Controller\Local_Email_Controller;
use BrianHenryIE\WP_Mailboxes\API\Controller\Remote_Email_Controller_Interface;
use BrianHenryIE\WP_Mailboxes\API\Exceptions\Invalid_Email_State_Exception;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Repository_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WP_Includes\Mailbox_Capabilities;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET /{emails}`, `GET /{emails}/{id}`, `GET /{emails}/{id}/remote-status`, `POST /{emails}/{id}/mark-read`,
 * `POST /{emails}/{id}/mark-unread`, `POST /{emails}/{id}/delete-on-server`, `POST /{emails}/{id}/status`,
 * `DELETE /{emails}/{id}`, `POST /{emails}/check`.
 */
class Emails_REST_Controller extends Mailbox_REST_Controller {

	const LOCAL_STATUSES = array( 'bh_email_new', 'bh_email_processed', 'bh_email_saved' );

	/**
	 * Constructor.
	 *
	 * @param API_Interface                      $api              Main API instance.
	 * @param Email_Repository_Interface         $email_repository Loads emails by post id.
	 * @param BH_WP_Mailboxes_Settings_Interface $settings         Names the mailbox's post types and REST namespace.
	 * @param Mailbox_Capabilities               $capabilities     Decides who may read and act on emails.
	 * @param LoggerInterface                    $logger           PSR-3 logger.
	 */
	public function __construct(
		protected API_Interface $api,
		protected Email_Repository_Interface $email_repository,
		BH_WP_Mailboxes_Settings_Interface $settings,
		Mailbox_Capabilities $capabilities,
		LoggerInterface $logger,
	) {
		parent::__construct( $settings, $capabilities, $logger );
		$this->rest_base          = $settings->get_emails_cpt_dashed();
		$this->controller_factory = new Email_Controller_Factory();
	}

	/**
	 * Builds the controller the command routes act through.
	 *
	 * @var Email_Controller_Factory
	 */
	protected Email_Controller_Factory $controller_factory;

	/**
	 * Register the routes.
	 *
	 * @hooked rest_api_init
	 */
	public function register_routes(): void {
		$id_arg = array(
			'id' => array(
				'description' => __( 'The email post id.', 'bh-wp-mailboxes' ),
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
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => $this->get_items( ... ),
					'permission_callback' => $this->get_items_permissions_check( ... ),
					'args'                => array(
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->route_namespace,
			'/' . $this->rest_base . '/check',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => $this->check( ... ),
					'permission_callback' => $this->manage_accounts_permissions_check( ... ),
				),
			)
		);

		register_rest_route(
			$this->route_namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => $this->get_item( ... ),
					'permission_callback' => $this->get_item_permissions_check( ... ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => $this->delete_item( ... ),
					'permission_callback' => $this->delete_item_permissions_check( ... ),
					'args'                => $id_arg + array(
						'force' => array(
							'description' => __( 'Delete permanently rather than trash.', 'bh-wp-mailboxes' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->route_namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/remote-status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => $this->get_remote_status( ... ),
					'permission_callback' => $this->get_item_permissions_check( ... ),
					'args'                => $id_arg,
				),
			)
		);

		foreach ( array( 'mark-read', 'mark-unread', 'delete-on-server' ) as $action ) {
			register_rest_route(
				$this->route_namespace,
				'/' . $this->rest_base . '/(?P<id>[\d]+)/' . $action,
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $this->remote_action( $action, $request ),
						'permission_callback' => $this->update_item_permissions_check( ... ),
						'args'                => $id_arg,
					),
				)
			);
		}

		register_rest_route(
			$this->route_namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/status',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => $this->update_status( ... ),
					'permission_callback' => $this->update_item_permissions_check( ... ),
					'args'                => $id_arg + array(
						'status' => array(
							'description' => __( 'The local status: new, processed or saved.', 'bh-wp-mailboxes' ),
							'type'        => 'string',
							'required'    => true,
							'enum'        => self::LOCAL_STATUSES,
						),
					),
				),
			)
		);
	}

	/**
	 * Listing requires the mailbox's list capability.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return $this->capabilities->current_user_can_list_emails()
			? true
			: $this->forbidden( __( 'Sorry, you are not allowed to list these emails.', 'bh-wp-mailboxes' ) );
	}

	/**
	 * Reading one email (or its remote status) requires the per-post read capability.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return $this->item_permissions_check( $request, $this->capabilities->current_user_can_read_email( ... ), __( 'Sorry, you are not allowed to read this email.', 'bh-wp-mailboxes' ) );
	}

	/**
	 * Acting on one email (remote actions, local status) requires the per-post edit capability.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		return $this->item_permissions_check( $request, $this->capabilities->current_user_can_edit_email( ... ), __( 'Sorry, you are not allowed to change this email.', 'bh-wp-mailboxes' ) );
	}

	/**
	 * Trashing or deleting one email requires the per-post delete capability.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		return $this->item_permissions_check( $request, $this->capabilities->current_user_can_delete_email( ... ), __( 'Sorry, you are not allowed to delete this email.', 'bh-wp-mailboxes' ) );
	}

	/**
	 * "Check all" fetches every account, so it requires the manage-accounts capability.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 */
	public function manage_accounts_permissions_check( $request ) {
		return $this->capabilities->current_user_can_manage_email_accounts()
			? true
			: $this->forbidden( __( 'Sorry, you are not allowed to check this mailbox.', 'bh-wp-mailboxes' ) );
	}

	/**
	 * A per-id check: 404 unless the id is one of this mailbox's emails, then the capability.
	 *
	 * @param WP_REST_Request     $request The request, carrying `id`.
	 * @param callable(int): bool $can     The capability check.
	 * @param string              $message The forbidden message.
	 *
	 * @return true|WP_Error
	 */
	protected function item_permissions_check( WP_REST_Request $request, callable $can, string $message ) {
		$post_id = $this->request_id( $request );
		$post    = get_post( $post_id );

		if ( ! ( $post instanceof WP_Post ) || $post->post_type !== $this->settings->get_emails_cpt_underscored_20() ) {
			return $this->not_found( __( 'No such email.', 'bh-wp-mailboxes' ) );
		}

		return $can( $post_id ) ? true : $this->forbidden( $message );
	}

	/**
	 * The most recently downloaded emails.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function get_items( $request ): WP_REST_Response {
		$per_page = $request->get_param( 'per_page' );
		$emails   = $this->api->get_downloaded_emails( is_numeric( $per_page ) ? (int) $per_page : 20 );

		return new WP_REST_Response( array_map( fn( BH_Email $email ): array => $this->serialize( $email, false ), $emails ) );
	}

	/**
	 * One email, with its bodies.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function get_item( $request ): WP_REST_Response {
		return new WP_REST_Response( $this->serialize( $this->load_email( $request ), true ) );
	}

	/**
	 * The live remote read status (a server call) and the recorded remote-deleted flag.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function get_remote_status( $request ): WP_REST_Response {
		$email      = $this->load_email( $request );
		$controller = $this->load_controller( $email );

		return new WP_REST_Response(
			array(
				'is_read'           => $controller instanceof Remote_Email_Controller_Interface ? $controller->get_remote_read_status() : null,
				'is_remote_deleted' => $email->is_remote_deleted,
			)
		);
	}

	/**
	 * Mark read / mark unread / delete on the mail server, through the email's controller. An operation
	 * that contradicts the email's state (already read, already deleted) is a 409; a failure on the
	 * server is a 502, not a success with a quiet log note.
	 *
	 * @param string          $action  One of mark-read, mark-unread, delete-on-server.
	 * @param WP_REST_Request $request The request.
	 */
	public function remote_action( string $action, WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email      = $this->load_email( $request );
		$controller = $this->load_controller( $email );

		if ( ! ( $controller instanceof Remote_Email_Controller_Interface ) ) {
			return new WP_Error( 'bh_wp_mailboxes_not_remote', __( 'This email\'s account cannot act on the mail server.', 'bh-wp-mailboxes' ), array( 'status' => 400 ) );
		}

		try {
			$controller = match ( $action ) {
				'mark-read'        => $controller->mark_read_on_server(),
				'mark-unread'      => $controller->mark_unread_on_server(),
				'delete-on-server' => $controller->delete_on_server(),
			};
		} catch ( Invalid_Email_State_Exception $exception ) {
			return new WP_Error( 'bh_wp_mailboxes_invalid_state', $exception->getMessage(), array( 'status' => 409 ) );
		} catch ( Throwable $throwable ) {
			$this->logger->error( "Remote action '{$action}' failed: " . $throwable->getMessage(), array( 'post_id' => $email->get_post_id() ) );

			return new WP_Error( 'bh_wp_mailboxes_remote_action_failed', $throwable->getMessage(), array( 'status' => 502 ) );
		}

		$email = $controller->get_email();

		return new WP_REST_Response(
			array(
				'is_read'           => $email->is_remote_read,
				'is_remote_deleted' => $email->is_remote_deleted,
			)
		);
	}

	/**
	 * The email's controller (remote-capable when its account's connection can act on the server), or
	 * a local one when the account cannot be resolved.
	 *
	 * @param BH_Email $email The email.
	 */
	protected function load_controller( BH_Email $email ): Email_Controller_Interface {
		$account = $this->api->get_email_account_for_email( $email );

		return is_null( $account )
			? new Local_Email_Controller( $email, $this->api )
			: $this->controller_factory->make( $this->api, $account, $email );
	}

	/**
	 * Change the local status, which the API records in the email's log.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function update_status( $request ): WP_REST_Response {
		$email   = $this->load_email( $request );
		$status  = $request->get_param( 'status' );
		$updated = $this->api->update_email_local_status( $email, is_string( $status ) ? $status : 'bh_email_new' );

		return new WP_REST_Response( array( 'status' => $updated->local_status ) );
	}

	/**
	 * Trash the email, or with `force` delete it permanently (attachments and notes go with it, see
	 * {@see \BrianHenryIE\WP_Mailboxes\API\Email_Post_Deletion_Handler}).
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$email = $this->load_email( $request );
		$force = (bool) $request->get_param( 'force' );

		$result = $force ? wp_delete_post( $email->get_post_id(), true ) : wp_trash_post( $email->get_post_id() );

		if ( false === $result || is_null( $result ) ) {
			return new WP_Error( 'bh_wp_mailboxes_delete_failed', __( 'The email could not be deleted.', 'bh-wp-mailboxes' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'trashed' => ! $force,
				'id'      => $email->get_post_id(),
			)
		);
	}

	/**
	 * Check every account for new emails ("Check all"). The request succeeds whenever the check ran; the
	 * body's `success` is false when any account failed, with each account's outcome listed.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function check( $request ): WP_REST_Response {
		$result = $this->api->check_email();

		$payload = array(
			'success'         => $result->success,
			'new_email_count' => count( $result->get_emails() ),
			'new_email_ids'   => array_map( fn( Email_Controller_Interface $email ): int => $email->get_email()->get_post_id(), $result->get_emails() ),
			'accounts'        => array_map(
				fn( Check_Email_Account_Result $account_result ): array => array(
					'account_post_id' => $account_result->bh_account->get_post_id(),
					'name'            => $account_result->bh_account->display_name,
					'email_address'   => $account_result->bh_account->email_address,
					'status'          => $account_result->success ? 'success' : ( $account_result->skipped ? 'skipped' : 'failed' ),
					'message'         => $account_result->message,
					'new_email_count' => count( $account_result->new_emails ),
					'warnings'        => $account_result->warnings,
				),
				$result->account_results
			),
		);

		if ( ! $result->success ) {
			$failures           = $result->get_failures();
			$payload['message'] = sprintf(
				/* translators: 1: number of accounts that failed, 2: total number of accounts checked */
				_n( '%1$d of %2$d accounts could not be checked.', '%1$d of %2$d accounts could not be checked.', count( $failures ), 'bh-wp-mailboxes' ),
				count( $failures ),
				count( $result->account_results )
			);
		}

		return new WP_REST_Response( $payload );
	}

	/**
	 * The email the permission callback already validated.
	 *
	 * @param WP_REST_Request $request The request, carrying `id`.
	 *
	 * @throws InvalidArgumentException When the post vanished between the permission check and the callback.
	 */
	protected function load_email( WP_REST_Request $request ): BH_Email {
		return $this->email_repository->find_by_post_id( $this->request_id( $request ) );
	}

	/**
	 * The email as REST data.
	 *
	 * @param BH_Email $email       The email.
	 * @param bool     $with_bodies Include the plain-text and HTML bodies (single item only).
	 *
	 * @return array<string,mixed>
	 */
	protected function serialize( BH_Email $email, bool $with_bodies ): array {
		$data = array(
			'id'                => $email->get_post_id(),
			'account_post_id'   => $email->email_account_local_id,
			'message_id'        => $email->message_id,
			'subject'           => $email->subject,
			'from_email'        => $email->from_email,
			'from_name'         => $email->from_name,
			'sent_at'           => $email->sent_at?->format( DATE_ATOM ),
			'downloaded_at'     => $email->downloaded_at?->format( DATE_ATOM ),
			'local_status'      => $email->local_status,
			'is_remote_read'    => $email->is_remote_read,
			'is_remote_deleted' => $email->is_remote_deleted,
			'attachment_ids'    => $email->attachment_ids,
		);

		if ( $with_bodies ) {
			$data['body_plain_text'] = $email->body_plain_text;
			$data['body_html']       = $email->body_html;
		}

		return $data;
	}
}
