[![WordPress tested 7.0](https://img.shields.io/badge/WordPress-v7.0%20tested-0073aa.svg)](https://wordpress.org/plugins/bh-wp-mailboxes) [![PHPCS WPCS](https://img.shields.io/badge/PHPCS-WordPress%20Coding%20Standards-8892BF.svg)](https://github.com/WordPress-Coding-Standards/WordPress-Coding-Standards) [![PHPUnit ](.github/coverage.svg)](https://brianhenryie.github.io/bh-wp-mailboxes/) 

# BH WP Mailboxes

A library to download emails into WordPress plugins.

e.g.
* Order payment receipts (Zelle, Venmo etc.)
* Newsletter unsubscribe emails
* Helpdesk
* Post by email 

[![WP List Table of emails](.github/wp-list-table-inbox.png)]

## Goals

* Handle bad credentials – servers block IPs that have too many bad login attempts, so delay a few hours after each failed attempt
* Support multiple mailboxes
* Save emails to cpt after filtering
* Autodelete emails

It's almost supposed to be a log of emails fetched whose data is used in plugins, for debugging when downloaded emails don't trigger plugins as expected, e.g. regex no longer matches after email body changes.

## Anti-goals:

* User-facing UI – the WP_List_Table (conventional, extensible) UI is intended for debugging, to allow site admins (shop managers etc.) to see the original emails and to test account settings etc.
* Sending email – use WP core functions for that, i.e. `wp_mail()` with an SMTP plugin. I recommend sending via AWS SES using WP SES plugin

## Implementation

Your implementation first needs the `BH_WP_Mailboxes_Settings_Interface` configuration which sets the custom post type names that mailboxes and emails are saved to, and the cron schedules mailboxes will be checked on. 

Somewhere in your plugin's settings you'll want to add a section for email account settings, e.g. IMAP server, username, password, etc. Some settings will probably be configured by you as a plugin developer, e.g. the number of days before emails are deleted. 

`API::save_new_mailbox()`

Saved mailboxes are checked on a cron job for new emails. When a new email is downloaded, the library fires an action that you can listen for. Use your own filters there and save the important information. 


// where do credentials get saved? wp_postmeta should be discouraged but possible. ENV variable names need to be customisable.  
## CLI


## Privacy / GDPR

The default setting is to delete emails after 7 days. NB: if you're using a shared inbox for your plugin's purpose (e.g. Venmo receipt emails go to treasurer@company.com rather than payments@company.com) this library will download _all_ emails. You can immediately delete all emails that you know are not relevant, but that is not the default. Emails that are downloaded are saved for debugging, e.g. the format of the Venmo emails changes and regexes that used to work to extract the relevant data no longer work, so you can see the original email in the WP List Table UI. Be aware of this and inform your company's data controller. I am not a lawyer, but I think this is ok! 

## Extensibility

// TODO: implement and document filter.

### Google API client

There is code in the plugin to support Google Developer Console projects but the Composer dependency is not included by default. If you want to use that:

```
{
    "require": {
        "google/apiclient": "^2.12.1"
    },
    "scripts": {
        "pre-autoload-dump": ["Google\\Task\\Composer::cleanup"]
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

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for details on contributing to the project. It's easy.

## TODO:

* AWS SES inbound SMTP via SNS


### More Information

See [github.com/BrianHenryIE/WordPress-Plugin-Boilerplate](https://github.com/BrianHenryIE/WordPress-Plugin-Boilerplate) for initial setup rationale. 

# Acknowledgements