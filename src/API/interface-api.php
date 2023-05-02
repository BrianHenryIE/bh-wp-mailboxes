<?php

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\BH_Email;

interface API_Interface {

	/**
	 * @param int $number
	 *
	 * @return BH_Email[]
	 */
	public function get_downloaded_emails( int $number ): array;

	/**
	 * @return array{success:bool}
	 */
	public function delete_old_emails(): array;

	/**
	 * @return array{success:bool}
	 */
	public function check_email(): array;

}
