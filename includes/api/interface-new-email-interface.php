<?php
/**
 * Former name of {@see Email_Controller_Interface}, kept so consumers' type hints keep working.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\API\Controller\Email_Controller_Interface;

/**
 * The email controller interface under its former name.
 *
 * @deprecated Use {@see Email_Controller_Interface}.
 */
interface New_Email_Interface extends Email_Controller_Interface {

	/**
	 * Trash the local email.
	 *
	 * @deprecated Use {@see Email_Controller_Interface::trash_local_email_post()} (or `delete_local_email_post()`).
	 */
	public function trash_locally(): static;
}
