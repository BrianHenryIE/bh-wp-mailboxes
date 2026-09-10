<?php
/**
 * Email accounts table rendered at the top of the emails list view.
 *
 * Lists each account with its status, email count, last fetched/failure times, a "Check now"
 * button (with the set-fetch-since date utility) and enable/disable, edit and delete actions, plus
 * an "Add account" button. Adding and editing happen in the {@see Email_Account_Modal} (reusable on
 * other screens); the library saves the account and its credentials (see {@see Email_Accounts_Ajax}).
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
	 * Constructor.
	 *
	 * @param API_Interface                      $api                     Main API instance.
	 * @param BH_WP_Mailboxes_Settings_Interface $settings                Plugin settings.
	 * @param Email_Repository_Interface         $email_wp_post_repository Email repository (for counts).
	 * @param LoggerInterface                    $logger                  PSR-3 logger.
	 * @param ?Email_Account_Modal               $modal                   The add/edit modal printed with the table; built from settings when omitted.
	 */
	public function __construct(
		protected API_Interface $api,
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		protected Email_Repository_Interface $email_wp_post_repository,
		LoggerInterface $logger,
		?Email_Account_Modal $modal = null,
	) {
		$this->setLogger( $logger );
		$this->modal = $modal ?? new Email_Account_Modal( $settings );
	}

	/**
	 * Renders the accounts table and modal in the admin notices area of the emails list screen.
	 *
	 * @hooked admin_notices
	 */
	public function display(): void {

		$screen    = get_current_screen();
		$post_type = $this->settings->get_emails_cpt_underscored_20();

		if ( null === $screen || $screen->post_type !== $post_type || 'edit' !== $screen->base ) {
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

		return new Email_Account_Row(
			account: $account,
			connection_label: $this->connection_label( $account->connection_type_class ),
			supports_fetching: $supports_fetching,
			can_edit: $can_edit,
			credentials: $can_edit ? $this->get_credentials( $account ) : null,
			email_count: $this->email_wp_post_repository->count_for_account_email( $account ),
			has_login_failure: ! is_null( $account->last_failed_login_time )
				&& ( is_null( $account->last_successful_login_time ) || $account->last_failed_login_time > $account->last_successful_login_time ),
			since_value: ( $account->last_successful_login_time ?? new DateTimeImmutable()->sub( new DateInterval( 'P1W' ) ) )->format( 'Y-m-d' ),
		);
	}

	/**
	 * The saved IMAP credentials for an account (never displayed: only the server/username/encryption
	 * are used, to pre-fill the edit form).
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
