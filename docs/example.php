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

try {
	$imap_mailboxes_api->add_email_account(
		email_address: $imap_env_settings->get_account_email_address(),
		display_name: $imap_env_settings->get_account_display_friendly_name(),
		provider_type_class: \BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Provider::class,
		body_identifier_regex_filter: 'unsubscribe',
	);
} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
	// Account already exists; ignore.
}

$imap_credentials = function ( mixed $value, mixed $plugin_slug, mixed $account ) use ( $imap_env_settings ) {
	if ( $account->email_address === $imap_env_settings->get_account_email_address() ) {
		return new Imap_Credentials_Env();
	}
	return $value;
};
add_filter( 'bh_wp_mailboxes_credentials', $imap_credentials, 10, 3 );

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
