<?php
/**
 * Thrown when an email operation does not apply to the email's current state, e.g. marking read an email
 * that is already read on the server. The REST layer reports it as `409 Conflict`.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API\Exceptions;

use LogicException;

/**
 * The operation contradicts the email's recorded state.
 */
class Invalid_Email_State_Exception extends LogicException {
}
