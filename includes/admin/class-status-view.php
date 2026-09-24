<?php
/**
 * Email accounts table rendered at the top of the emails list view.
 *
 * Lists each account with its status, email count, last fetched/failure times, a "Check now"
 * button (with the set-fetch-since date utility) and enable/disable, edit and delete actions, plus
 * an "Add account" button. Adding and editing happen in the {@see Email_Account_Modal} (reusable on
 * other screens); the library saves the account and its credentials (see {@see \BrianHenryIE\WP_Mailboxes\REST\Email_Accounts_REST_Controller}).
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\Admin\Model\Email_Account_Row;
use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Repository_Interface;
use BrianHenryIE\WP_Mailboxes\API\Requires_Credentials;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Connection;
use BrianHenryIE\WP_Mailboxes\WP_Includes\Mailbox_Capabilities;
use DateInterval;
use DateTimeImmutable;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Renders the accounts table (an {@see Email_Accounts_List_Table}) and the add/edit modal above the emails list table.
 */
class Status_View {

	use LoggerAwareTrait;

	/**
	 * The add/edit account modal; also owns the shared admin script/style.
	 *
	 * @var Email_Account_Modal
	 */
	protected Email_Account_Modal $modal;

	/**
	 * Decides whether the current user sees the table at all.
	 *
	 * @var Mailbox_Capabilities
	 */
	protected Mailbox_Capabilities $capabilities;

	/**
	 * Constructor.
	 *
	 * @param API_Interface                      $api                     Main API instance.
	 * @param BH_WP_Mailboxes_Settings_Interface $settings                Plugin settings.
	 * @param Email_Repository_Interface         $email_wp_post_repository Email repository (for counts).
	 * @param LoggerInterface                    $logger                  PSR-3 logger.
	 * @param ?Email_Account_Modal               $modal                   The add/edit modal printed with the table; built from settings when omitted.
	 * @param ?Mailbox_Capabilities              $capabilities            This mailbox's capability checks; built from settings when omitted.
	 */
	public function __construct(
		protected API_Interface $api,
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		protected Email_Repository_Interface $email_wp_post_repository,
		LoggerInterface $logger,
		?Email_Account_Modal $modal = null,
		?Mailbox_Capabilities $capabilities = null,
	) {
		$this->setLogger( $logger );
		$this->capabilities = $capabilities ?? new Mailbox_Capabilities( $settings );
		$this->modal        = $modal ?? new Email_Account_Modal( $settings, $this->capabilities );
	}

	/**
	 * Renders the accounts table and modal in the admin notices area of the emails list screen.
	 *
	 * The table is all-or-nothing: it lists each account's server and username (in the rows' `data-*`
	 * attributes, to pre-fill the edit form) and every control on it is an account action, so it is
	 * printed only for users who may manage the mailbox's accounts.
	 *
	 * @hooked admin_notices
	 */
	public function display(): void {

		$screen    = get_current_screen();
		$post_type = $this->settings->get_emails_cpt_underscored_20();

		if ( null === $screen || $screen->post_type !== $post_type || 'edit' !== $screen->base ) {
			return;
		}

		if ( ! $this->capabilities->current_user_can_manage_email_accounts() ) {
			return;
		}

		echo '<div id="bh-mailboxes-status" class="bh-mailboxes-status">';
		echo '<div class="bh-mailboxes-status__table">';
		$this->render_table();
		echo '</div>';
		echo '</div>';

		// The nonce, the add/edit modal and the delete confirmation dialog.
		$this->modal->print_modal();

		// Move the table (and its notices) above the list table, directly under the page title.
		echo '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelector(".wp-header-end").after(document.getElementById("bh-mailboxes-status"));});</script>';
	}

	/**
	 * Renders the accounts table. Also returned by the AJAX handlers to refresh the table in place.
	 *
	 * The list table is instantiated here, not in the constructor: WP_List_Table registers a columns
	 * filter for its screen on construction, which must not happen on the accounts CPT's own list page.
	 */
	public function render_table(): void {

		// WP_List_Table (and the screen functions it uses) are only loaded on admin screens; the REST routes
		// re-render this table for their responses too.
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
			require_once ABSPATH . 'wp-admin/includes/template.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		$accounts = $this->api->get_email_accounts();

		echo '<div class="bh-mailboxes-status__toolbar">';
		echo '<h2 class="bh-mailboxes-status__title">' . esc_html__( 'Email accounts', 'bh-wp-mailboxes' ) . '</h2>';
		$this->modal->print_add_button();
		echo '</div>';

		$table = new Email_Accounts_List_Table(
			$this->settings,
			array_values( array_map( array( $this, 'make_row' ), $accounts ) )
		);
		$table->prepare_items();
		$table->display();
	}

	/**
	 * Gather what an account's row needs beyond the account itself.
	 *
	 * @param BH_Email_Account $account The account.
	 */
	protected function make_row( BH_Email_Account $account ): Email_Account_Row {

		$connection        = $this->api->get_connection_for_email_account( $account );
		$supports_fetching = $connection instanceof Supports_Fetching;
		$can_edit          = $connection instanceof Requires_Credentials
			&& ImapEngine_Imap_Email_Connection::class === $account->connection_type_class;
		$email_counts      = $this->email_wp_post_repository->count_by_status_for_account_email( $account );

		return new Email_Account_Row(
			account: $account,
			connection_label: $this->connection_label( $account->connection_type_class ),
			supports_fetching: $supports_fetching,
			can_edit: $can_edit,
			credentials: $can_edit ? $this->get_credentials( $account ) : null,
			email_count: $email_counts->total(),
			new_email_count: $email_counts->new_count,
			has_login_failure: ! is_null( $account->last_failed_login_time )
				&& ( is_null( $account->last_successful_login_time ) || $account->last_failed_login_time > $account->last_successful_login_time ),
			since_value: ( $account->last_successful_login_time ?? new DateTimeImmutable()->sub( new DateInterval( 'P1W' ) ) )->format( 'Y-m-d' ),
		);
	}

	/**
	 * The saved IMAP credentials for an account (never displayed: only the server/username/encryption/
	 * validate-certificate values are used, to pre-fill the edit form).
	 *
	 * @param BH_Email_Account $account The account.
	 */
	protected function get_credentials( BH_Email_Account $account ): ?IMAP_Credentials_Interface {
		$credentials = $this->api->get_account_credentials( $account );

		return $credentials instanceof IMAP_Credentials_Interface ? $credentials : null;
	}

	/**
	 * A short name for the connection class, e.g. "IMAP", "REST Ingress".
	 *
	 * @param string $connection_type_class The account's connection class.
	 */
	protected function connection_label( string $connection_type_class ): string {
		if ( ImapEngine_Imap_Email_Connection::class === $connection_type_class ) {
			return 'IMAP';
		}
		$parts = explode( '\\', $connection_type_class );

		return str_replace( array( '_Email_Connection', '_Connection', '_Interface', '_' ), array( '', '', '', ' ' ), (string) end( $parts ) );
	}
}
