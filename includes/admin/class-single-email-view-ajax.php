<?php
/**
 * Handle buttons on the list page. (and maybe settings page).
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Handles AJAX requests from the admin UI.
 */
class Single_Email_View_Ajax {

	use LoggerAwareTrait;

	/**
	 * Constructor.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings                Plugin settings.
	 * @param API_Interface                      $api                     Main API instance.
	 * @param Email_WP_Post_Repository           $email_wp_post_repository Email repository.
	 * @param LoggerInterface                    $logger                  PSR-3 logger.
	 */
	public function __construct(
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		protected API_Interface $api,
		protected Email_WP_Post_Repository $email_wp_post_repository,
		LoggerInterface $logger
	) {
		$this->setLogger( $logger );
	}


	/**
	 * Mark email as read on the remote server.
	 *
	 * The action is suffixed with the emails CPT so each library instance only handles its own request.
	 *
	 * @hooked wp_ajax_bh_wp_mailboxes_mark_read_{emails_cpt}
	 */
	public function ajax_mark_read(): void {
		$this->handle_remote_action( 'mark_read' );
	}

	/**
	 * Mark email as unread on the remote server.
	 *
	 * The action is suffixed with the emails CPT so each library instance only handles its own request.
	 *
	 * @hooked wp_ajax_bh_wp_mailboxes_mark_unread_{emails_cpt}
	 */
	public function ajax_mark_unread(): void {
		$this->handle_remote_action( 'mark_unread' );
	}

	/**
	 * Delete the email on the remote server.
	 *
	 * The action is suffixed with the emails CPT so each library instance only handles its own request.
	 *
	 * @hooked wp_ajax_bh_wp_mailboxes_delete_on_server_{emails_cpt}
	 */
	public function ajax_delete_on_server(): void {
		$this->handle_remote_action( 'delete_on_server' );
	}

	/**
	 * Return the live remote read/deleted status for an email.
	 *
	 * Called on page load so the displayed status reflects the server, not just cached local meta.
	 *
	 * @hooked wp_ajax_bh_wp_mailboxes_get_remote_status_{emails_cpt}
	 */
	public function ajax_get_remote_status(): void {

		if ( ! isset( $_POST['_wpnonce'], $_POST['post_id'] )
			|| ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'bh-wp-mailboxes-remote-action' ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 403 );
		}

		$post_id = (int) $_POST['post_id'];

		try {
			$email = $this->email_wp_post_repository->find_by_post_id( $post_id );
		} catch ( \InvalidArgumentException $exception ) {
			wp_send_json_error( array( 'message' => 'Invalid post.' ), 400 );
		}

		// `get_remote_read_status()` makes the live API call; deleted status has no remote query, so the
		// local meta value is returned for it.
		wp_send_json_success(
			array(
				'is_read'           => $this->api->get_remote_read_status( $email ),
				'is_remote_deleted' => $email->is_remote_deleted,
			)
		);
	}

	/**
	 * Shared AJAX handler for remote email actions.
	 *
	 * @param string $action One of: mark_read, mark_unread, delete_on_server.
	 */
	protected function handle_remote_action( string $action ): void {

		if ( ! isset( $_POST['_wpnonce'], $_POST['post_id'] )
			|| ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'bh-wp-mailboxes-remote-action' ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 403 );
		}

		$post_id = (int) $_POST['post_id'];

		try {
			$email = $this->email_wp_post_repository->find_by_post_id( $post_id );
		} catch ( \InvalidArgumentException $exception ) {
			wp_send_json_error( array( 'message' => 'Invalid post.' ), 400 );
		}

		// TODO: Validate request here. Is it even possible to mark an email read if it's already marked read? etc.

		// TODO: Check user permissions here.

		try {
			match ( $action ) {
				'mark_read'        => $this->api->mark_email_read( $email ),
				'mark_unread'      => $this->api->mark_email_unread( $email ),
				'delete_on_server' => $this->api->delete_email_on_server( $email ),
			};
		} catch ( \Throwable $e ) {
			$this->logger->error( "Remote action '{$action}' failed: " . $e->getMessage(), array( 'post_id' => $post_id ) );
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}

		$is_read_raw       = get_post_meta( $post_id, 'bh_email_is_read', true );
		$deleted_on_server = get_post_meta( $post_id, 'bh_email_deleted_on_server', true );

		wp_send_json_success(
			array(
				'is_read'           => '' === $is_read_raw ? null : ( '1' === $is_read_raw ),
				'is_remote_deleted' => '' === $deleted_on_server ? null : ( '1' === $deleted_on_server ),
			)
		);
	}
}
