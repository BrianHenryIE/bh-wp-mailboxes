# Changelog

## Unreleased

* Breaking: library hooks now pass `$plugin_slug` first (after the filtered value), followed by the emails post type on per-mailbox hooks: `bh_wp_mailboxes_credentials`, `bh_wp_mailboxes_connection_for_account`, `bh_wp_mailboxes_auth_failure_notice_message` (filters) and `bh_wp_mailboxes_new_email` (action) gain both; `bh_wp_mailboxes_max_message_size_bytes` (filter) and `bh_wp_mailboxes_gmail_access_token_refreshed` (action) gain only `$plugin_slug`

