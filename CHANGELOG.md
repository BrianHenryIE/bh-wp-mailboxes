# Changelog

## Unreleased

* Fix: no mailboxes were registered in the self-contained WordPress Playground build — `BH_WP_Mailboxes::filter()` derived the plugin slug from the library file's path, which never matched when the library is nested in a plugin's vendor directory; instances are now matched by their own settings' plugin slug
* Add: `composer playground-serve` — build the development plugin and serve it in WordPress Playground locally (the same self-contained layout the CI preview workflow publishes)
* Breaking: `API::add_email_account()` (which threw when an account already existed) is replaced by `API::configure_email_account()` — an upsert keyed by the account's email address, its only required parameter: creates the account when none exists (throwing `InvalidArgumentException` if the then-required display name or connection class is missing), otherwise updates it in place (null values leave existing configuration unchanged, including the connection class)
* Add: `API::delete_email_account( $email_address )` — permanently deletes the account's post (locally saved emails are kept); returns false when no account exists for the address
* Breaking: library hooks now pass `$plugin_slug` first (after the filtered value), followed by the emails post type on per-mailbox hooks: `bh_wp_mailboxes_credentials`, `bh_wp_mailboxes_connection_for_account`, `bh_wp_mailboxes_auth_failure_notice_message` (filters) and `bh_wp_mailboxes_new_email` (action) gain both; `bh_wp_mailboxes_max_message_size_bytes` (filter) and `bh_wp_mailboxes_gmail_access_token_refreshed` (action) gain only `$plugin_slug`

