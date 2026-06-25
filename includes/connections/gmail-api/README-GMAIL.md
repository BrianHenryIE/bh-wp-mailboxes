# Gmail via Google API setup

This is probably the best option when you want to use Gmail in a plugin you are not distributing. I.e. it's presumably better because this uses Google's own API rather than IMAP but the process of getting your Google Developer app approved for broad use is onerous so using Gmail via IMAP is probably easier for your clients.

## Dependencies

The is code to support Google Developer Console projects exists in the library but the Composer dependency is not installed by default.

If you want to use that, in your plugin that includes this library, add the following to your `composer.json`:

```
{
    "require": {
        "google/apiclient": "^2.12.1"
    },
    "scripts": {
        "pre-autoload-dump": [
            "Google\\Task\\Composer::cleanup"
        ]
    },
    "extra": {
        "google/apiclient-services": [
            "Gmail"        
        ]
    }
}
```

```bash
jq '.scripts["pre-autoload-dump"] |= ((. // []) + ["Google\\Task\\Composer::cleanup"]) | unique' composer.json | sponge composer.json
jq '.extra["google/apiclient-services"] |= ((. // []) + ["Gmail"]) | unique' composer.json | sponge composer.json
composer require google/apiclient
```

## Project Setup

To fetch email via the Gmail API this plugin needs two JSON files for each account:

* `client_secret.json` – your Google Cloud project's OAuth client (client id + secret), mapped to [`OAuth_Client_Credentials`](model/class-oauth-client-credentials.php).
* `access_token.json` – the user's access **and** refresh token, mapped to [`Access_Token`](model/class-access-token.php).

The steps below create a Google Developer Console project from the command line using the `gcloud` CLI, then obtain those two files.

> **Note:** `gcloud` can create the project and enable the API, but Google does **not** provide a `gcloud` command to create an OAuth **client id** or to configure the OAuth consent screen branding. Those two steps must be done in the [Google Cloud Console](https://console.cloud.google.com/) web UI – they are called out below.





## 1. Install the CLI and authenticate

See https://docs.cloud.google.com/sdk/docs/downloads-homebrew

```bash
brew update && brew install --cask gcloud-cli
gcloud components update
export CLOUDSDK_PYTHON=$(which python3.11)
gcloud auth login
```

## 2. Create the project

Project ids are globally unique, 6–30 characters, lowercase letters, digits and hyphens.

gcloud beta billing accounts list

PROJECT_ID="gmail-reader-demo-1234"      # must be globally unique
PROJECT_NAME="Gmail Reader Demo"
BILLING_ACCOUNT_ID="XXXXXX-XXXXXX-XXXXXX"  # replace with your real billing account ID


123456783063-metqtjun2f9237abct5q8b1j5b5cpabf.apps.googleusercontent.com

gcloud projects create bh-wp-mailboxes-project --name="BH WP Mailboxes"
gcloud config set project bh-wp-mailboxes-project
```bash

PROJECT_NAME="globally-unique-my-mailboxes-project"
gcloud projects create $PROJECT_NAME --name="My Mailboxes"
gcloud config set project $PROJECT_NAME
```

If you have multiple billing/organization accounts you may need `--organization=<ORG_ID>` or `--folder=<FOLDER_ID>`.

## 3. Enable the Gmail API

```bash
gcloud services enable gmail.googleapis.com
```

https://console.cloud.google.com/auth/scopes?project=bh-wp-mailboxes-project

## 4. Configure the OAuth consent screen (Console)

```bash
open "https://console.cloud.google.com/apis/credentials/consent?project=$PROJECT_NAME"
```

This step is **web UI only**.

1. Open **APIs & Services → OAuth consent screen** for your project:
   `https://console.cloud.google.com/apis/credentials/consent?project=globally-unique-my-mailboxes-project`
2. Choose **External** (unless the mailbox is inside a Google Workspace organization, in which case **Internal** is simpler).
3. Fill in the app name, support email and developer contact email.
4. Add the scope `https://www.googleapis.com/auth/gmail.readonly` (this plugin reads, and can mark read/unread and trash).
5. Under **Test users**, add the email address of the mailbox you are connecting. While the app is in "Testing", only listed test users can authorize it and refresh tokens expire after 7 days – publish the app to avoid that.

```bash
open "https://console.cloud.google.com/auth/audience?project=$PROJECT_NAME"
```




## 5. Create the OAuth client credentials (Console)

```bash
open "https://console.cloud.google.com/apis/credentials?project=$PROJECT_NAME"
```

This step is **web UI only**.

1. Open **APIs & Services → Credentials**:
   `https://console.cloud.google.com/apis/credentials?project=my-mailboxes-project`
2. **Create credentials → OAuth client ID**.
3. Application type: **Desktop app**.

   This is the right choice when you authorize from the command line and have **no callback URL** to host. A Desktop-app client uses Google's installed-app flow: after you consent, Google sends the authorization code to a loopback address (`http://localhost`) instead of a server you run, so you can simply copy the code out of the browser and paste it into the CLI (see step 6).

   (Choose **Web application** instead only if you actually host a redirect endpoint, e.g. `https://example.com/oauth2callback`. Both are supported — see below.)
4. **Download JSON** and save it as `client_secret.json` in the account's credentials directory (see [`Google_API_Credentials`](class-google-api-credentials.php)).

A Desktop-app client's JSON has a top-level **`installed`** key (a Web-application client uses `web`). [`OAuth_Client_Credentials::from_json()`](model/class-oauth-client-credentials.php) accepts either:

```json
{
  "installed": {
    "client_id": "…apps.googleusercontent.com",
    "project_id": "my-mailboxes-project",
    "auth_uri": "https://accounts.google.com/o/oauth2/auth",
    "token_uri": "https://oauth2.googleapis.com/token",
    "auth_provider_x509_cert_url": "https://www.googleapis.com/oauth2/v1/certs",
    "client_secret": "…",
    "redirect_uris": ["http://localhost"]
  }
}
```

## 6. Authorize once to obtain `access_token.json`

The first authorization is interactive and, with a Desktop-app client, **has no callback URL** — so you copy the authorization code out of the browser by hand. The flow is implemented in [`Gmail_Email_Connection::get_authorization_url()`](class-gmail-email-provider.php) and [`fetch_access_token_with_auth_code()`](class-gmail-email-provider.php); the development plugin drives it from a WP-CLI command:

```bash
wp development-plugin gmail connect
```

This:

1. Creates the Gmail connection (adds the email account), then
2. prints a Google authorization URL and waits at an `Enter the verification code:` prompt.

To complete it:

1. Open the printed URL, choose the Gmail account, and grant access.
2. Because the Desktop-app client redirects to a loopback address that nothing is listening on, the browser lands on an **unreachable `http://localhost/?code=…&scope=…` page** (a "can't reach this site" error). That is expected.
3. Copy the value of the **`code`** query-string parameter from the browser's address bar and paste it at the prompt.

The command exchanges the code for a token (including the long-lived **`refresh_token`**) and writes it to `access_token.json` next to `client_secret.json`. The file looks like:

```json
{
  "access_token": "ya29.…",
  "expires_in": 3599,
  "scope": "https://www.googleapis.com/auth/gmail.readonly",
  "token_type": "Bearer",
  "created": 1700000000,
  "refresh_token": "1//…"
}
```

The `refresh_token` is the important, long-lived value – guard it like a password.

## 7. Refreshing the access token

Access tokens expire after ~1 hour. To mint a fresh one from the refresh token without going through the interactive flow again, use the WP-CLI command:

```bash
wp <plugin-slug> gmail refresh-access-token --account=you@example.com
```

`<plugin-slug>` is the slug of the plugin embedding this library. The command:

* resolves the account's credentials via the `bh_wp_mailboxes_credentials` filter,
* uses the stored refresh token to obtain a new access token,
* prints the new token as JSON,
* fires the `bh_wp_mailboxes_gmail_access_token_refreshed` action with the new [`Access_Token`](model/class-access-token.php) and the account email.

It does **not** save the token anywhere. Persist it by hooking the action:

```php
add_action(
	'bh_wp_mailboxes_gmail_access_token_refreshed',
	function ( $access_token, $account_email ) {
		// e.g. write $access_token to access_token.json or store in the database.
	},
	10,
	2
);
```
