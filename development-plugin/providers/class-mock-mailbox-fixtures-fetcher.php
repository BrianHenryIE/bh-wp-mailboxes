<?php
/**
 * An example implementation of `Email_Fetcher_Interface` that can be used to provide fixtures for testing.
 *
 * Reads from a directory of text files containing emails. Operations on emails are saved per-user in wp_options
 * and can be reset.
 *
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Providers;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Email_Provider_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use DateTimeInterface;
use Illuminate\Support\Collection;

class Mock_Mailbox_Fixtures_Provider implements  Email_Provider_Interface
{
	public function reset(): void {
		//
	}

	public function set_credentials( Account_Credentials_Interface $credentials ): void {
		// noop.
	}

	public function retrieve_emails( DateTimeInterface $since_time ): Collection {
		// TODO: Implement retrieve_emails() method.
	}

	public function test_connection(): bool {
		return true;
	}

	public function can_read_status(): bool {
		return false;
	}

	public function get_is_marked_read( Remote_Email_Coordinates $coordinates ): bool {
		// TODO: Implement get_is_marked_read() method.
	}

	public function can_mark_read(): bool {
		return false;
	}

	public function set_is_marked_read( Remote_Email_Coordinates $coordinates, bool $is_read = true ): void {
		return;
	}

	public function can_delete_on_server(): bool {
		return false;
	}

	public function do_delete_on_server( Remote_Email_Coordinates $coordinates ): bool {
		return false;
	}
}