<?php
/**
 * Defines an interface to allow different IMAP libraries to be used.
 *
 * `Email_Fetcher`s should catch and wrap libraries' exceptions in local exceptions.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\Model\ZImessage_Collection;
use DateTimeInterface;

interface Email_Fetcher_Interface {

	/**
	 * Typically, check for emails since the time of the last email or the last time emails were checked for.
	 *
	 * @param DateTimeInterface $since_time
	 *
	 * @return ZImessage_Collection Unsaved emails.
	 */
	public function retrieve_emails( DateTimeInterface $since_time ): ZImessage_Collection;

	// public function test_connection();
}
