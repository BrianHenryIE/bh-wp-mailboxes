# BH WP Mailboxes Test Credentials

This directory is for saving credentials for testing locally with `wp-env` and in `tests/contract` live tests.

This directory is mapped (in `.wp-env.json`) to `/var/www/test-credentials/` in the container and if the files are present, the development plugin will use them to configure mailboxes.

# IMAP

Copy `.env.secret.example` to `.env.secret` and fill in the values.

# Google Developer Console Project

Place the Google Developer project credentials in this directory as: 
* `client_secret.json`
* `access_token.json`

Gmail allows IMAP application passwords for accounts with 2FA, you don't necessarily need to set up a Google Developer Console project.