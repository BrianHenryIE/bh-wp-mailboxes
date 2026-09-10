<?php


use BrianHenryIE\WP_Mailboxes\Connections\Imap\Imap_Credentials_Env;

$imap_env_settings = new class() implements \BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface {
	use \BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Defaults_Trait;

	/**
	 * Returns the IMAP account email address.
	 */
	public function get_account_email_address(): string {
		return $_ENV['IMAP_USERNAME'] ?? '';
	}
};

$imap_mailboxes_api = \BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes::make( $imap_mailboxes_settings );

$imap_mailboxes_api->configure_email_account(
	email_address: $imap_env_settings->get_account_email_address(),
	display_name: $imap_env_settings->get_account_display_friendly_name(),
	connection_type_class: \BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Connection::class,
	body_identifier_regex_filter: 'unsubscribe',
);

// Save the credentials once (encrypted, in the WordPress Secrets API); the library reads them when fetching.
$imap_account = $imap_mailboxes_api->get_email_accounts()[ $imap_env_settings->get_account_email_address() ] ?? null;
if ( $imap_account && is_null( $imap_mailboxes_api->get_account_credentials( $imap_account ) ) ) {
	$imap_mailboxes_api->save_account_credentials( $imap_account, new Imap_Credentials_Env() );
}

$add_menu = function () use ( $imap_env_settings ) {
	add_menu_page(
		page_title: 'Mailboxes',
		menu_title: 'Mailboxes',
		capability: 'manage_options',
		menu_slug: 'edit.php?post_type=' . $imap_env_settings->get_emails_cpt_underscored_20(),
		callback: '',
		icon_url: 'dashicons-email',
		position: 3
	);
};
add_action( 'admin_menu', $add_menu );
