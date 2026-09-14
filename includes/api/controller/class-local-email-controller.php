<?php
/**
 * The local-only operations on one stored email: status changes, log notes, trashing and deleting.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API\Controller;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\New_Email_Interface;

/**
 * Immutable: every operation returns a new instance wrapping the re-read email.
 */
readonly class Local_Email_Controller implements New_Email_Interface {

	/**
	 * Constructor.
	 *
	 * @param BH_Email      $email The email.
	 * @param API_Interface $api   The API the operations delegate to.
	 */
	final public function __construct(
		protected BH_Email $email,
		protected API_Interface $api,
	) {
	}

	/**
	 * Get the immutable email itself.
	 */
	public function get_email(): BH_Email {
		return $this->email;
	}

	/**
	 * Set the local status; the change is recorded in the email's log.
	 *
	 * @param string $local_status 'new'|'processed'|'saved'.
	 */
	public function update_local_status( string $local_status = 'processed' ): static {
		return $this->with_email( $this->api->update_email_local_status( email: $this->email, local_status: $local_status ) );
	}

	/**
	 * Add a note to the email's log.
	 *
	 * @param string              $message Message text.
	 * @param string              $level   'info'|'notice'|'warning'|'error'.
	 * @param array<string,mixed> $context Arbitrary serializable data, stored with the note.
	 */
	public function add_local_note( string $message, string $level = 'notice', array $context = array() ): static {
		$this->api->insert_email_log_note( post_id: $this->email->post_id, message: $message, level: $level, context: $context );

		return $this->with_email( $this->api->get_email( $this->email->post_id ) ?? $this->email );
	}

	/**
	 * Move the local email post to the trash. Its attachments and log notes are kept, so it can be restored.
	 */
	public function trash_local_email_post(): void {
		wp_trash_post( $this->email->post_id );
	}

	/**
	 * Permanently delete the local email post. Its attachments (posts and files) and log notes go with it
	 * ({@see \BrianHenryIE\WP_Mailboxes\API\Email_Post_Deletion_Handler}).
	 */
	public function delete_local_email_post(): void {
		wp_delete_post( $this->email->post_id, true );
	}

	/**
	 * Trash the local email.
	 *
	 * @deprecated Use {@see self::trash_local_email_post()} (or `delete_local_email_post()` to delete permanently).
	 */
	public function trash_locally(): static {
		$this->trash_local_email_post();

		return $this->with_email( $this->api->get_email( $this->email->post_id ) ?? $this->email );
	}

	/**
	 * A new instance of the same class wrapping another state of the email. `static`, so a remote
	 * controller's local operations return a remote controller.
	 *
	 * @param BH_Email $email The email after an operation.
	 */
	protected function with_email( BH_Email $email ): static {
		return new static( email: $email, api: $this->api );
	}
}
