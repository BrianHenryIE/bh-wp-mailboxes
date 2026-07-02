<?php
/**
 * Interface for email connections that can actively fetch (pull) emails from a server.
 *
 * Pull connections (IMAP, Gmail API) connect out and retrieve messages. Receive-only connections are
 * passive – messages are pushed to them – e.g. AWS SES->SNS->WP REST, or Cloudflare email
 * routing->Worker->WP REST. Receive-only connections implement {@see Email_Connection_Interface} but
 * not this interface.
 *
 * Downstream UIs can check `instanceof Supports_Fetching` to decide whether to show a "Check now" /
 * fetch button for an account.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Always used with {@see Email_Connection_Interface}.
 */
interface Supports_Fetching {

	/**
	 * Typically, check for emails since the time of the last email or the last time emails were checked for.
	 *
	 * @param DateTimeInterface $since_time The earliest time to retrieve emails from.
	 *
	 * @return Collection<int, \BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email> Unsaved emails with their remote coordinates.
	 */
	public function retrieve_emails( DateTimeInterface $since_time ): Collection;


	/**
	 * Does the email service support reading the read/unread status of emails on the server.
	 */
	public function can_read_status(): bool;

	/**
	 * Make an API call to determine whether the email is marked read on the server.
	 *
	 * @param Remote_Email_Coordinates $coordinates How to locate the email on the remote server.
	 */
	public function get_is_marked_read( Remote_Email_Coordinates $coordinates ): bool;

	/**
	 * Does the email service support changing the read/unread status on the server.
	 */
	public function can_mark_read(): bool;

	/**
	 * Perform API call to change the read status of an email on a server.
	 *
	 * @param Remote_Email_Coordinates $coordinates The data required to address a single email.
	 * @param bool                     $is_read Mark as read or false for unread.
	 */
	public function set_is_marked_read( Remote_Email_Coordinates $coordinates, bool $is_read = true ): void;

	/**
	 * Does the service supports deleting the remote emails.
	 */
	public function can_delete_on_server(): bool;

	/**
	 * Delete the email on the remote server.
	 *
	 * @param Remote_Email_Coordinates $coordinates The data required to address a single email.
	 *
	 * @throws \Exception When the email service does not support the operation or the user does not have permission.
	 */
	public function do_delete_on_server( Remote_Email_Coordinates $coordinates ): bool;
}
