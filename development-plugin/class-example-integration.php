<?php
/**
 * Example parent-plugin integration: react to each newly downloaded email.
 *
 * This is the snippet the README points to — the minimal shape of a plugin consuming the library.
 * It hooks `bh_wp_mailboxes_new_email` (fired once per saved email by {@see API::check_email()}),
 * logs the subject via the configured bh-wp-logger, and records a note on the email's own log to
 * demonstrate the {@see New_Email_Interface} wrapper.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin;

use BrianHenryIE\WP_Mailboxes\API\New_Email_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use Psr\Log\LoggerInterface;

/**
 * A demonstration consumer of the `bh_wp_mailboxes_new_email` action.
 */
class Example_Integration {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR-3 logger (the dev plugin's bh-wp-logger instance).
	 */
	public function __construct(
		protected LoggerInterface $logger,
	) {
	}

	/**
	 * Register the new-email hook.
	 */
	public function register_hooks(): void {
		add_action( 'bh_wp_mailboxes_new_email', array( $this, 'log_new_email' ), 10, 3 );
	}

	/**
	 * Log each newly downloaded email's subject, and note it on the email's own log.
	 *
	 * @hooked bh_wp_mailboxes_new_email
	 *
	 * @param string              $plugin_slug The slug of the plugin instance that downloaded the email.
	 * @param BH_Email_Account    $account     The account the email was downloaded from.
	 * @param New_Email_Interface $new_email  The newly saved email, wrapped for the consumer.
	 */
	public function log_new_email( string $plugin_slug, BH_Email_Account $account, New_Email_Interface $new_email ): void {

		$email = $new_email->get_email();

		$this->logger->info(
			'New email downloaded: ' . $email->get_subject(),
			array(
				'plugin_slug' => $plugin_slug,
				'account'     => $account->email_address,
				'subject'     => $email->get_subject(),
				'post_id'     => $email->get_post_id(),
			)
		);

		// Record a note on the email's log so it is visible in the single-email view — the primary
		// reason the library wraps emails in New_Email_Interface.
		$new_email->add_local_note( 'Example integration saw this email.', 'info' );
	}
}
