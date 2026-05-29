<?php
/**
 * Defines an interface to allow different IMAP libraries to be used.
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\BH_Email;
use DateTimeInterface;

interface Email_Fetcher_Interface {

	/**
	 * Typically, check for emails since the time of the last email or the last time emails were checked for.
	 *
	 * @param DateTimeInterface $since_time
	 *
	 * @return BH_Email[] Unsaved emails.
	 */
	public function retrieve_emails( DateTimeInterface $since_time ): array;
}
