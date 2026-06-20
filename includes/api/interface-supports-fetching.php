<?php
/**
 * Interface for email providers that can actively fetch (pull) emails from a server.
 *
 * Pull providers (IMAP, Gmail API) connect out and retrieve messages. Receive-only providers are
 * passive – messages are pushed to them – e.g. AWS SES->SNS->WP REST, or Cloudflare email
 * routing->Worker->WP REST. Receive-only providers implement {@see Email_Provider_Interface} but
 * not this interface.
 *
 * Downstream UIs can check `instanceof Supports_Fetching` to decide whether to show a "Check now" /
 * fetch button for an account.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API;

use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Always used with {@see Email_Provider_Interface}.
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
}
