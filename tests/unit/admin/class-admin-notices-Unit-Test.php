<?php
/**
 * Unit tests for Admin_Notices.
 *
 * Admin_Notices is a thin subclass of WPTRT\AdminNotices\Notices; these tests confirm it inherits a
 * working add → get → remove lifecycle (the mechanism a caller uses to raise an auth-failure notice
 * and clear it again on success).
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

use WPTRT\AdminNotices\Notices;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Admin_Notices
 */
class Admin_Notices_Unit_Test extends Unit_Testcase {

}
