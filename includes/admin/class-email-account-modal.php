<?php
/**
 * The add/edit IMAP account modal (and its delete confirmation), reusable on any admin screen.
 *
 * The emails list screen prints it beside the accounts table; a consumer can print it elsewhere,
 * e.g. on a WooCommerce payment gateway settings screen, with an "Add account" button:
 *
 *     $modal = new Email_Account_Modal( $settings, new Mailbox_Capabilities( $settings ) );
 *     add_action( 'admin_enqueue_scripts', fn() => $modal->enqueue_assets() ); // On the relevant screen.
 *     add_action( 'admin_footer', fn() => $modal->print_modal() );
 *     $modal->print_add_button(); // Wherever the button should appear.
 *
 * Saving posts to the accounts REST route ({@see \BrianHenryIE\WP_Mailboxes\REST\Email_Accounts_REST_Controller}),
 * which stores the account and its credentials (encrypted, via the WordPress Secrets API); "Test
 * connection" posts the entered details there too, and reports the result in the form without saving anything. Where an accounts table
 * (`.bh-mailboxes-status__table`) is on the page the JS refreshes it from the response; otherwise the
 * result is only reported in a notice.
 *
 * Owns its own script (`js/account-modal.js`) and stylesheet (`css/account-modal.css`), so printing it
 * on a consumer's screen enqueues nothing else; the accounts table's script depends on the modal's.
 *
 * The button and the modal are printed only for users who may manage the mailbox's accounts (see
 * {@see Mailbox_Capabilities::current_user_can_manage_email_accounts()}), so a consumer's screen is gated
 * without checking anything itself. The assets are enqueued regardless: the script also carries the REST
 * helpers the emails list's own script depends on, and it holds nothing a user could not already obtain.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\REST\REST_Namespace;
use BrianHenryIE\WP_Mailboxes\WP_Includes\Mailbox_Capabilities;

/**
 * Prints the modal markup and enqueues the script/style it needs.
 */
class Email_Account_Modal {

	/**
	 * Constructor.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings     Provides the post type keys the REST routes are scoped by.
	 * @param Mailbox_Capabilities               $capabilities Decides whether the current user sees the button and the modal.
	 */
	public function __construct(
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		protected Mailbox_Capabilities $capabilities,
	) {
	}

	/**
	 * The handle of the modal's script and stylesheet, scoped per library instance. Other scripts that
	 * need the modal (or its AJAX helpers) list it as a dependency.
	 */
	public function get_script_handle(): string {
		return 'bh-wp-mailboxes-account-modal-' . $this->settings->get_email_accounts_cpt_dashed();
	}

	/**
	 * Enqueue the modal's script (with its localised, instance-scoped AJAX action names) and stylesheet.
	 * Idempotent, so the emails list page and a consumer's screen can both call it.
	 *
	 * @hooked admin_enqueue_scripts
	 */
	public function enqueue_assets(): void {
		$handle = $this->get_script_handle();

		if ( wp_script_is( $handle, 'enqueued' ) ) {
			return;
		}

		$version = BH_WP_Mailboxes::get_version();

		wp_enqueue_script( $handle, plugin_dir_url( __FILE__ ) . 'js/account-modal.js', array( 'jquery' ), $version, true );

		// The REST routes the scripts call (see REST\Emails_REST_Controller / Email_Accounts_REST_Controller), scoped
		// to this instance's post types, and the cookie-auth nonce.
		wp_localize_script(
			$handle,
			'bh_wp_mailboxes_ajax',
			array(
				'rest' => array(
					'root'     => REST_Namespace::url( $this->settings ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'emails'   => $this->settings->get_emails_cpt_dashed(),
					'accounts' => $this->settings->get_email_accounts_cpt_dashed(),
				),
			)
		);

		wp_enqueue_style( $handle, plugin_dir_url( __FILE__ ) . 'css/account-modal.css', array( 'dashicons' ), $version );
	}

	/**
	 * Print an "Add account" button that opens the modal. Prints nothing for a user who may not manage
	 * the mailbox's accounts.
	 *
	 * @param string $classes Extra CSS classes for the button.
	 */
	public function print_add_button( string $classes = 'button' ): void {
		if ( ! $this->capabilities->current_user_can_manage_email_accounts() ) {
			return;
		}
		echo '<button type="button" class="' . esc_attr( trim( $classes . ' bh-account-add' ) ) . '">' . esc_html__( 'Add account', 'bh-wp-mailboxes' ) . '</button>';
	}

	/**
	 * Fired once the modal markup has been printed; its ids and nonce must appear once per page.
	 */
	const PRINTED_ACTION = 'bh_wp_mailboxes_account_modal_printed';

	/**
	 * Print the add/edit modal (IMAP fields only) and the delete
	 * confirmation dialog. Printed once per page: by {@see Status_View::display()} on the emails list
	 * screen, or by a consumer on `admin_footer` on their own screen; a second call is a no-op, as is any
	 * call for a user who may not manage the mailbox's accounts.
	 *
	 * @hooked admin_footer
	 */
	public function print_modal(): void {
		if ( did_action( self::PRINTED_ACTION ) > 0 ) {
			return;
		}

		if ( ! $this->capabilities->current_user_can_manage_email_accounts() ) {
			return;
		}

		/**
		 * The modal markup is being printed (at most once per page, whichever instance prints first).
		 */
		do_action( self::PRINTED_ACTION );

		?>
		<dialog id="bh-mailboxes-account-dialog" class="bh-mailboxes-account-dialog" aria-labelledby="bh-mailboxes-account-dialog-title">
			<form class="bh-mailboxes-account-form" autocomplete="off" data-mode="add">
				<div class="bh-mailboxes-account-dialog__header">
					<h2 id="bh-mailboxes-account-dialog-title" data-add-title="<?php esc_attr_e( 'Add IMAP account', 'bh-wp-mailboxes' ); ?>" data-edit-title="<?php esc_attr_e( 'Edit IMAP account', 'bh-wp-mailboxes' ); ?>"><?php esc_html_e( 'Add IMAP account', 'bh-wp-mailboxes' ); ?></h2>
					<button type="button" class="bh-mailboxes-account-dialog__close" aria-label="<?php esc_attr_e( 'Close', 'bh-wp-mailboxes' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
				</div>
				<div class="bh-mailboxes-account-form__notice notice inline" hidden role="status"><p></p></div>
				<input type="hidden" name="account_post_id" value="" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bh-mailboxes-account-display-name"><?php esc_html_e( 'Account name', 'bh-wp-mailboxes' ); ?></label></th>
						<td><input type="text" id="bh-mailboxes-account-display-name" name="display_name" class="regular-text" placeholder="<?php esc_attr_e( 'Payments inbox', 'bh-wp-mailboxes' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="bh-mailboxes-account-email-address"><?php esc_html_e( 'Email address', 'bh-wp-mailboxes' ); ?></label></th>
						<td>
							<input type="email" id="bh-mailboxes-account-email-address" name="email_address" class="regular-text" required placeholder="inbox@example.com" />
							<p class="description bh-mailboxes-account-form__edit-only" hidden><?php esc_html_e( 'The email address identifies the account and cannot be changed.', 'bh-wp-mailboxes' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bh-mailboxes-account-server"><?php esc_html_e( 'IMAP server', 'bh-wp-mailboxes' ); ?></label></th>
						<td>
							<input type="text" id="bh-mailboxes-account-server" name="server" class="regular-text" required placeholder="imap.example.com:993" />
							<p class="description"><?php esc_html_e( 'Hostname or IP address, with an optional :port.', 'bh-wp-mailboxes' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bh-mailboxes-account-username"><?php esc_html_e( 'Username', 'bh-wp-mailboxes' ); ?></label></th>
						<td>
							<input type="text" id="bh-mailboxes-account-username" name="username" class="regular-text" autocomplete="off" data-lpignore="true" />
							<p class="description"><?php esc_html_e( 'Defaults to the email address.', 'bh-wp-mailboxes' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bh-mailboxes-account-password"><?php esc_html_e( 'Password', 'bh-wp-mailboxes' ); ?></label></th>
						<td>
							<input type="password" id="bh-mailboxes-account-password" name="password" class="regular-text" required autocomplete="new-password" data-lpignore="true" />
							<p class="description bh-mailboxes-account-form__edit-only" hidden><?php esc_html_e( 'Leave blank to keep the saved password.', 'bh-wp-mailboxes' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bh-mailboxes-account-encryption"><?php esc_html_e( 'Encryption', 'bh-wp-mailboxes' ); ?></label></th>
						<td>
							<select id="bh-mailboxes-account-encryption" name="encryption">
								<option value="TLS" selected>TLS</option>
								<option value="STARTTLS">STARTTLS</option>
								<option value=""><?php esc_html_e( 'None', 'bh-wp-mailboxes' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Certificate', 'bh-wp-mailboxes' ); ?></th>
						<td>
							<label for="bh-mailboxes-account-validate-cert">
								<input type="checkbox" id="bh-mailboxes-account-validate-cert" name="validate_cert" value="1" checked />
								<?php esc_html_e( 'Validate the server\'s certificate', 'bh-wp-mailboxes' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Untick only for a server with a self-signed or otherwise untrusted certificate.', 'bh-wp-mailboxes' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="bh-mailboxes-account-form__actions">
					<button type="button" class="button bh-mailboxes-account-form__test"><?php esc_html_e( 'Test connection', 'bh-wp-mailboxes' ); ?></button>
					<button type="button" class="button bh-mailboxes-account-form__cancel"><?php esc_html_e( 'Cancel', 'bh-wp-mailboxes' ); ?></button>
					<button type="submit" class="button button-primary bh-mailboxes-account-form__submit" data-add-label="<?php esc_attr_e( 'Add account', 'bh-wp-mailboxes' ); ?>" data-edit-label="<?php esc_attr_e( 'Save account', 'bh-wp-mailboxes' ); ?>"><?php esc_html_e( 'Add account', 'bh-wp-mailboxes' ); ?></button>
					<span class="spinner"></span>
				</p>
			</form>
		</dialog>

		<dialog id="bh-mailboxes-account-confirm" class="bh-mailboxes-account-confirm" aria-labelledby="bh-mailboxes-account-confirm-title">
			<h2 id="bh-mailboxes-account-confirm-title"><?php esc_html_e( 'Delete email account?', 'bh-wp-mailboxes' ); ?></h2>
			<p class="bh-mailboxes-account-confirm__message"></p>
			<p class="description"><?php esc_html_e( 'Emails already downloaded are kept.', 'bh-wp-mailboxes' ); ?></p>
			<p class="bh-mailboxes-account-confirm__actions">
				<button type="button" class="button bh-mailboxes-account-confirm__cancel"><?php esc_html_e( 'Cancel', 'bh-wp-mailboxes' ); ?></button>
				<button type="button" class="button button-primary bh-mailboxes-account-confirm__delete"><?php esc_html_e( 'Delete account', 'bh-wp-mailboxes' ); ?></button>
			</p>
		</dialog>

		<dialog id="bh-mailboxes-fetch-since" class="bh-mailboxes-fetch-since" aria-labelledby="bh-mailboxes-fetch-since-title">
			<form method="dialog" class="bh-mailboxes-fetch-since__form">
				<h2 id="bh-mailboxes-fetch-since-title"><?php esc_html_e( 'Check for emails since…', 'bh-wp-mailboxes' ); ?></h2>
				<p class="bh-mailboxes-fetch-since__account"></p>
				<p>
					<label for="bh-mailboxes-fetch-since-date"><?php esc_html_e( 'Fetch emails received on or after', 'bh-wp-mailboxes' ); ?></label>
					<input type="date" id="bh-mailboxes-fetch-since-date" class="bh-fetch-since-input" autocomplete="off" data-lpignore="true" data-1p-ignore required>
				</p>
				<p class="description"><?php esc_html_e( 'This will poll for 100 emails at a time until no more are found. Emails already downloaded will be ignored based on their message id.', 'bh-wp-mailboxes' ); ?></p>
				<p class="bh-mailboxes-fetch-since__actions">
					<button type="button" class="button bh-mailboxes-fetch-since__cancel"><?php esc_html_e( 'Cancel', 'bh-wp-mailboxes' ); ?></button>
					<button type="submit" class="button button-primary bh-mailboxes-fetch-since__fetch"><?php esc_html_e( 'Fetch', 'bh-wp-mailboxes' ); ?></button>
				</p>
			</form>
		</dialog>
		<?php
	}
}
