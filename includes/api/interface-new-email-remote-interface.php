<?php
/**
 * Former name of {@see Remote_Email_Controller_Interface}, kept so consumers' type hints keep working.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\API\Controller\Remote_Email_Controller_Interface;

/**
 * The remote email controller interface under its former name.
 *
 * @deprecated Use {@see Remote_Email_Controller_Interface}.
 */
interface New_Email_Remote_Interface extends New_Email_Interface, Remote_Email_Controller_Interface {
}
