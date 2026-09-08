<?php
/**
 * IMAP credentials saved from the accounts table modal, one wp_option per account.
 *
 * Demonstrates what a consumer does with the library's credentials actions: persist what
 * `bh_wp_mailboxes_save_account_credentials` hands over, discard on `bh_wp_mailboxes_account_deleted`,
 * and answer `bh_wp_mailboxes_credentials` from the store.
 *
 * Each account has its own option (rather than one array for all accounts) so concurrent saves for
 * different accounts, e.g. parallel e2e workers, are not a read-modify-write race that loses one.
 *
 * The password is stored in plaintext in wp_options. That is acceptable for a development plugin;
 * a real consumer should encrypt it (or store it outside the database) before copying this.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\Imap_Credentials;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\IMAP_Credentials_Interface;

/**
 * A wp_options-backed multi-account IMAP credentials store for the development plugin.
 */
class Imap_Credentials_Options {

	/**
	 * Prefix of the per-account option name; the suffix is a hash of the email address.
	 */
	public const OPTION_NAME_PREFIX = 'bh_wp_mailboxes_dev_imap_credentials_';

	/**
	 * Register the save/delete actions and the credentials filter.
	 */
	public function register_hooks(): void {
		add_action( 'bh_wp_mailboxes_save_account_credentials', array( $this, 'save_credentials' ), 10, 4 );
		add_action( 'bh_wp_mailboxes_account_deleted', array( $this, 'delete_credentials' ), 10, 3 );
		// Registered late so the env/settings-page providers keep precedence for their one account.
		add_filter( 'bh_wp_mailboxes_credentials', array( $this, 'filter_credentials' ), 20, 4 );
	}

	/**
	 * Persist the credentials entered in the accounts modal.
	 *
	 * @hooked bh_wp_mailboxes_save_account_credentials
	 *
	 * @param string                        $plugin_slug      The plugin the library instance is running as.
	 * @param string                        $emails_post_type The emails post type of the instance.
	 * @param BH_Email_Account              $account          The saved account.
	 * @param Account_Credentials_Interface $credentials      The credentials to save.
	 */
	public function save_credentials( string $plugin_slug, string $emails_post_type, BH_Email_Account $account, Account_Credentials_Interface $credentials ): void {
		if ( ! ( $credentials instanceof IMAP_Credentials_Interface ) ) {
			return;
		}
		update_option(
			$this->option_name( $account->email_address ),
			array(
				'server'     => $credentials->get_email_imap_server(),
				'username'   => $credentials->get_email_account_username(),
				'password'   => $credentials->get_email_account_password(),
				'encryption' => $credentials->get_encryption(),
			),
			false
		);
	}

	/**
	 * Discard a deleted account's credentials.
	 *
	 * @hooked bh_wp_mailboxes_account_deleted
	 *
	 * @param string           $plugin_slug      The plugin the library instance is running as.
	 * @param string           $emails_post_type The emails post type of the instance.
	 * @param BH_Email_Account $account          The deleted account.
	 */
	public function delete_credentials( string $plugin_slug, string $emails_post_type, BH_Email_Account $account ): void {
		delete_option( $this->option_name( $account->email_address ) );
	}

	/**
	 * Answer the credentials filter from the store.
	 *
	 * @hooked bh_wp_mailboxes_credentials
	 *
	 * @param mixed            $value            The credentials another provider may already have supplied.
	 * @param string           $plugin_slug      The plugin the library instance is running as.
	 * @param string           $emails_post_type The emails post type of the instance.
	 * @param BH_Email_Account $account          The account.
	 *
	 * @return mixed
	 */
	public function filter_credentials( mixed $value, string $plugin_slug, string $emails_post_type, BH_Email_Account $account ) {
		if ( $value instanceof Account_Credentials_Interface ) {
			return $value;
		}
		$saved = get_option( $this->option_name( $account->email_address ) );
		if ( ! is_array( $saved ) ) {
			return $value;
		}

		return new Imap_Credentials(
			$this->string_at( $saved, 'server' ),
			$this->string_at( $saved, 'username' ),
			$this->string_at( $saved, 'password' ),
			// An empty string means "no encryption" (the modal's "None"); only default when never saved.
			array_key_exists( 'encryption', $saved ) ? $this->string_at( $saved, 'encryption' ) : 'TLS',
		);
	}

	/**
	 * A string value from a saved credentials record, or empty string.
	 *
	 * @param array<mixed> $saved The record.
	 * @param string       $key   The field.
	 */
	protected function string_at( array $saved, string $key ): string {
		return is_string( $saved[ $key ] ?? null ) ? $saved[ $key ] : '';
	}

	/**
	 * The account's option name. Email addresses are matched case-insensitively.
	 *
	 * @param string $email_address The account's email address.
	 */
	protected function option_name( string $email_address ): string {
		return self::OPTION_NAME_PREFIX . md5( strtolower( trim( $email_address ) ) );
	}
}
