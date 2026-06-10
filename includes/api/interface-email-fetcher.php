<?php
/**
 * Defines an interface to allow different IMAP libraries to be used.
 *
 * `Email_Fetcher`s should catch and wrap libraries' exceptions in local exceptions.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use DateTimeInterface;
use Illuminate\Support\Collection;

interface Email_Fetcher_Interface {

	/**
	 * Typically, check for emails since the time of the last email or the last time emails were checked for.
	 *
	 * @param DateTimeInterface $since_time The earliest date to retrieve emails from.
	 *
	 * @return Collection<int, \ZBateson\MailMimeParser\IMessage> Unsaved emails.
	 */
	public function retrieve_emails( DateTimeInterface $since_time ): Collection;

	// public function test_connection(); // TODO: implement.
}
