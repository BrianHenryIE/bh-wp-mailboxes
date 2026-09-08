<?php
/**
 * The email accounts table above the emails list, as a WP_List_Table.
 *
 * No checkbox column (no `cb` column is declared), no bulk actions (none are declared) and no table
 * nav (pagination/nonce), since accounts are few. Enable/disable, edit and delete are row actions on
 * the primary (account) column; "Check now" and "Check since…" are row actions under the last fetched
 * time. The rows carry the data attributes the admin JavaScript reads (edit pre-fill, enable/disable,
 * check now, delete).
 *
 * Keyed to the accounts CPT screen (`edit-{accounts_cpt}`), not the emails one: WP_List_Table
 * registers its `get_columns()` on `manage_{screen id}_columns` and WordPress caches column headers
 * and the hidden-columns user option per screen, so using the emails screen would replace the emails
 * list table's headers with these columns. Instantiate only when rendering, so that filter is never
 * registered on the accounts CPT's own list page.
 *
 * @see Status_View Builds the rows and renders this table.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\Admin\Model\Email_Account_Row;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use DateTimeInterface;
use WP_List_Table;

/**
 * Renders {@see Email_Account_Row} items.
 */
class Email_Accounts_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings Provides the accounts CPT key (the table's screen).
	 * @param Email_Account_Row[]                $rows     The accounts to list.
	 */
	public function __construct(
		BH_WP_Mailboxes_Settings_Interface $settings,
		array $rows,
	) {
		parent::__construct(
			array(
				'singular' => 'bh_mailboxes_account',
				'plural'   => 'bh_mailboxes_accounts',
				'ajax'     => false,
				'screen'   => 'edit-' . $settings->get_email_accounts_cpt_underscored_20(),
			)
		);

		$this->items = $rows;
	}

	/**
	 * Set the column headers; there is no pagination or sorting, and no columns can be hidden.
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'account' );
	}

	/**
	 * The columns. No `cb` entry, so no checkbox column is rendered.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'account'      => __( 'Account', 'bh-wp-mailboxes' ),
			'status'       => __( 'Status', 'bh-wp-mailboxes' ),
			'emails'       => __( 'Emails', 'bh-wp-mailboxes' ),
			'last_fetched' => __( 'Last fetched', 'bh-wp-mailboxes' ),
			'last_failure' => __( 'Last failure', 'bh-wp-mailboxes' ),
		);
	}

	/**
	 * Drop core's `fixed` (six uneven columns) and view-mode classes.
	 *
	 * @return string[]
	 */
	protected function get_table_classes(): array {
		return array( 'widefat', 'striped', 'bh-mailboxes-accounts' );
	}

	/**
	 * No table nav: no bulk actions, pagination, or their nonce.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function display_tablenav( $which ): void {}

	/**
	 * Core prints `<tbody id="the-list">`, which is the emails list table's tbody on the same screen
	 * (the admin JS refreshes `#the-list` after a check), so the rows get a class instead.
	 */
	public function display_rows_or_placeholder(): void {
		echo '<tbody class="bh-mailboxes-accounts__rows">';
		parent::display_rows_or_placeholder();
		echo '</tbody>';
	}

	/**
	 * Overridden so {@see display_rows_or_placeholder()} owns the tbody.
	 */
	public function display(): void {
		$this->screen->render_screen_reader_content( 'heading_list' );
		?>
		<table class="wp-list-table <?php echo esc_attr( implode( ' ', $this->get_table_classes() ) ); ?>">
			<?php $this->print_table_description(); ?>
			<thead>
			<tr>
				<?php $this->print_column_headers(); ?>
			</tr>
			</thead>

			<?php $this->display_rows_or_placeholder(); ?>

			<tfoot>
			<tr>
				<?php $this->print_column_headers( false ); ?>
			</tr>
			</tfoot>
		</table>
		<?php
	}

	/**
	 * Shown when there are no accounts.
	 */
	public function no_items(): void {
		esc_html_e( 'No accounts configured.', 'bh-wp-mailboxes' );
	}

	/**
	 * A row, with the attributes the admin JavaScript reads. The `class` attribute is first and exact
	 * (`bh-mailboxes-account`, plus `--inactive`): tests and the JS select on it.
	 *
	 * @param Email_Account_Row $item The row.
	 */
	public function single_row( $item ): void {
		$account     = $item->account;
		$credentials = $item->credentials;

		$attributes = array(
			'class'                  => 'bh-mailboxes-account' . ( $account->is_active() ? '' : ' bh-mailboxes-account--inactive' ),
			'data-account-id'        => (string) $account->get_post_id(),
			'data-account-name'      => $account->display_name,
			'data-email-address'     => $account->email_address,
			'data-active'            => $account->is_active() ? '1' : '0',
			'data-supports-fetching' => $item->supports_fetching ? '1' : '0',
			'data-can-edit'          => $item->can_edit ? '1' : '0',
			'data-server'            => $credentials?->get_email_imap_server() ?? '',
			'data-username'          => $credentials?->get_email_account_username() ?? '',
			'data-encryption'        => $credentials?->get_encryption() ?? 'TLS',
			'data-has-credentials'   => is_null( $credentials ) ? '0' : '1',
		);

		echo '<tr';
		foreach ( $attributes as $name => $value ) {
			echo ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}
		echo '>';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Account name, address and connection type.
	 *
	 * @param Email_Account_Row $item The row.
	 */
	protected function column_account( Email_Account_Row $item ): string {
		return '<strong class="bh-mailboxes-account__name">' . esc_html( $item->account->display_name ) . '</strong><br>'
			. '<span class="bh-mailboxes-account__email">' . esc_html( $item->account->email_address ) . '</span>'
			. '<span class="bh-mailboxes-account__type"> · ' . esc_html( $item->connection_label ) . '</span>';
	}

	/**
	 * Active/inactive badge, plus login-failure and missing-credentials warnings.
	 *
	 * @param Email_Account_Row $item The row.
	 */
	protected function column_status( Email_Account_Row $item ): string {
		$account = $item->account;

		$html = '<span class="bh-mailboxes-badge ' . ( $account->is_active() ? 'bh-mailboxes-badge--active' : 'bh-mailboxes-badge--inactive' ) . '">'
			. esc_html( $account->is_active() ? __( 'Active', 'bh-wp-mailboxes' ) : __( 'Inactive', 'bh-wp-mailboxes' ) ) . '</span>';

		if ( $item->has_login_failure ) {
			$html .= '<span class="bh-mailboxes-warning bh-mailboxes-login-failure"><span class="dashicons dashicons-warning" aria-hidden="true"></span>'
				/* translators: %s: human-readable time difference, e.g. "5 minutes ago" */
				. esc_html( sprintf( __( 'Login failed %s', 'bh-wp-mailboxes' ), $this->format_time( $account->last_failed_login_time ) ) ) . '</span>';
		}
		if ( $item->can_edit && is_null( $item->credentials ) ) {
			$html .= '<span class="bh-mailboxes-warning bh-mailboxes-no-credentials"><span class="dashicons dashicons-warning" aria-hidden="true"></span>'
				. esc_html__( 'No credentials found', 'bh-wp-mailboxes' ) . '</span>';
		}

		return $html;
	}

	/**
	 * Number of emails saved for the account (updated in place by the JS after a check).
	 *
	 * @param Email_Account_Row $item The row.
	 */
	protected function column_emails( Email_Account_Row $item ): string {
		return '<span data-field="email-count">' . esc_html( (string) $item->email_count ) . '</span>';
	}

	/**
	 * Last fetched time with "Check now" / "Check since…" row actions and the set-fetch-since date
	 * input; "N/A" for receive-only accounts.
	 *
	 * Always visible (core's `visible` row-actions class): "Check now" is the column's main control.
	 * Built by hand rather than with {@see row_actions()} so the wrapper can carry the
	 * `bh-mailboxes-account__check` class the date input is positioned against.
	 *
	 * @param Email_Account_Row $item The row.
	 */
	protected function column_last_fetched( Email_Account_Row $item ): string {
		if ( ! $item->supports_fetching ) {
			return '<span data-field="last-fetched" class="bh-mailboxes-muted" title="' . esc_attr__( 'Emails are delivered to this account, not fetched.', 'bh-wp-mailboxes' ) . '">' . esc_html__( 'N/A', 'bh-wp-mailboxes' ) . '</span>';
		}

		$account_id = (string) $item->account->get_post_id();

		return '<span data-field="last-fetched">' . esc_html( $this->format_time( $item->account->last_successful_login_time ) ) . '</span>'
			. '<div class="row-actions visible bh-mailboxes-account__check">'
			. '<span class="check"><a href="#" class="bh-check-account" data-account-id="' . esc_attr( $account_id ) . '">' . esc_html__( 'Check now', 'bh-wp-mailboxes' ) . '</a> | </span>'
			. '<span class="since"><a href="#" class="bh-fetch-since-toggle" data-account-id="' . esc_attr( $account_id ) . '" title="' . esc_attr__( 'Set the date from which emails will be fetched', 'bh-wp-mailboxes' ) . '">' . esc_html__( 'Check since…', 'bh-wp-mailboxes' ) . '</a></span>'
			. '</div>'
			. '<input type="date" class="bh-fetch-since-input" data-account-id="' . esc_attr( $account_id ) . '" value="' . esc_attr( $item->since_value ) . '" style="display:none;">';
	}

	/**
	 * Last failed login time; "N/A" for receive-only accounts.
	 *
	 * @param Email_Account_Row $item The row.
	 */
	protected function column_last_failure( Email_Account_Row $item ): string {
		return '<span data-field="last-failure">'
			. esc_html( $item->supports_fetching ? $this->format_time( $item->account->last_failed_login_time ) : __( 'N/A', 'bh-wp-mailboxes' ) )
			. '</span>';
	}

	/**
	 * Row actions on the primary (account) column: enable/disable, Edit (IMAP accounts) and Delete
	 * (fetch-capable accounts only: receive-only ones are created by their endpoint and would be
	 * recreated), plus core's responsive "Show more details" toggle.
	 *
	 * @param Email_Account_Row $item        The row.
	 * @param string            $column_name The column being rendered.
	 * @param string            $primary     The primary column.
	 */
	protected function handle_row_actions( $item, $column_name, $primary ): string {
		if ( $primary !== $column_name ) {
			return '';
		}

		$account    = $item->account;
		$account_id = esc_attr( (string) $account->get_post_id() );

		$actions = array(
			'toggle' => '<a href="#" class="bh-account-toggle" data-account-id="' . $account_id . '" data-active="' . ( $account->is_active() ? '0' : '1' ) . '">'
				. esc_html( $account->is_active() ? __( 'Disable', 'bh-wp-mailboxes' ) : __( 'Enable', 'bh-wp-mailboxes' ) ) . '</a>',
		);
		if ( $item->can_edit ) {
			$actions['edit'] = '<a href="#" class="bh-account-edit" data-account-id="' . $account_id . '">' . esc_html__( 'Edit', 'bh-wp-mailboxes' ) . '</a>';
		}
		if ( $item->supports_fetching ) {
			$actions['delete'] = '<a href="#" class="bh-account-delete" data-account-id="' . $account_id . '">' . esc_html__( 'Delete', 'bh-wp-mailboxes' ) . '</a>';
		}

		return $this->row_actions( $actions ) . parent::handle_row_actions( $item, $column_name, $primary );
	}

	/**
	 * Formats a datetime as a human-readable "X ago" string, or "Never" if null.
	 *
	 * @param ?DateTimeInterface $time The datetime to format.
	 */
	protected function format_time( ?DateTimeInterface $time ): string {
		if ( null === $time ) {
			return __( 'Never', 'bh-wp-mailboxes' );
		}
		/* translators: %s: human-readable time difference, e.g. "5 minutes" */
		return sprintf( __( '%s ago', 'bh-wp-mailboxes' ), human_time_diff( $time->getTimestamp() ) );
	}
}
