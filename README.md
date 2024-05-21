[![WordPress tested 5.9](https://img.shields.io/badge/WordPress-v5.8%20tested-0073aa.svg)](https://wordpress.org/plugins/bh-wp-mailboxes) [![PHPCS WPCS](https://img.shields.io/badge/PHPCS-WordPress%20Coding%20Standards-8892BF.svg)](https://github.com/WordPress-Coding-Standards/WordPress-Coding-Standards) [![PHPUnit ](.github/coverage.svg)](https://brianhenryie.github.io/bh-wp-mailboxes/) 

# BH WP Mailboxes

Intended as a library to use in WordPress plug ins that connect to email servers and process those emails.

e.g.
* Order payment receipts (Zelle, Venmo etc.)
* Newsletter unsubscribe emails
* Helpdesk
* Post by email 

Goals:
* Handle bad credentials – servers block IPs that have too many bad login attempts, so delay a few hours after each failed attempt
* Support multiple mailboxes
* Save emails to cpt after filtering
* Autodelete emails

It's almost supposed to be a log of emails fetched whose data is used in plugins, for debugging when downloaded emails
don't trigger plugins as expected, e.g. regex no longer matches after email body changes.


It is not intended for a UI to be presented to the site users.
Extensible UI is intended for site admins (shop managers etc.) to see the original emails, test account settings etc. 

TODO:

* AWS SES inbound SMTP via SNS

Anti-goals:
* Sending email

```
{
    "require": {
        "google/apiclient": "^2.12.1"
    },
    "scripts": {
        "pre-autoload-dump": "Google\\Task\\Composer::cleanup"
    },
    "extra": {
        "google/apiclient-services": [
            "Gmail"        
        ]
    }
}
```



### More Information

See [github.com/BrianHenryIE/WordPress-Plugin-Boilerplate](https://github.com/BrianHenryIE/WordPress-Plugin-Boilerplate) for initial setup rationale. 

# Acknowledgements