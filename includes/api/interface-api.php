<?php

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;

interface API_Interface {

	/**
	 * @param int $number
	 *
	 * @return BH_Email[]
	 */
	public function get_downloaded_emails( int $number ): array;

	// public function get_mailboxes()

	/**
	 * @return array{success:bool}
	 */
	public function delete_old_emails(): array;

	/**
	 * @return array{success:bool}
	 */
	public function check_email(): array;

	/**
	 * Mark the email as read on its remote server and update local post meta.
	 *
	 * @param BH_Email $email The email to mark as read.
	 */
	public function mark_email_read( BH_Email $email ): void;

	/**
	 * Mark the email as unread on its remote server and update local post meta.
	 *
	 * @param BH_Email $email The email to mark as unread.
	 */
	public function mark_email_unread( BH_Email $email ): void;

	/**
	 * Delete the email on its remote server and update local post meta.
	 *
	 * @param BH_Email $email The email to delete on the server.
	 */
	public function delete_email_on_server( BH_Email $email ): void;

	/**
	 * Insert a WooCommerce-style log note (wp comment) on the email post.
	 *
	 * @param int    $post_id The email CPT post ID.
	 * @param string $message The note text.
	 */
	public function insert_email_log_note( int $post_id, string $message ): void;
}
