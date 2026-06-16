<?php
/**
 * DI for returning a new mailbox instance for each server/mailbox to connect to.
 *
 * @link       https://GitHub.com/BrianHenryIE/bh-wp-mailboxes
 * @since      1.0.0
 *
 * @package    brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Providers\Imap;

use DirectoryTree\ImapEngine\MailboxInterface;

interface Server_Container_Interface {

	/**
	 * Returns a mailbox instance for checking emails.
	 *
	 * @see Email_Fetcher
	 *
	 * @param string $url_or_ip imap.example.org or 127.0.0.1 or 127.0.0.1:993.
	 *
	 * @return MailboxInterface
	 */
	public function get_server( string $url_or_ip ): MailboxInterface;
}
