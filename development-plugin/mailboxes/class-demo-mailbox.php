<?php
/**
 * The Demo mailbox: a ready-to-browse mailbox of example emails for people trying the plugin.
 *
 * It has two accounts:
 * - `support@example-shop.test` fetches, like an IMAP or Gmail account (see {@see Demo_Fetching_Connection}).
 * - `orders@example-shop.test` is receive-only, like the REST ingress (see {@see Demo_Delivered_Connection}).
 *
 * Between them they hold a plain-text email, an HTML + plain-text email, an email with an attachment, and
 * a three-message conversation that is threaded by its headers even though the shop's own replies were
 * never stored, and whose last message arrived at the other account.
 *
 * The accounts are created and their emails seeded the first time the mailbox loads. The fetching
 * account's emails are seeded by a real fetch, so the normal pipeline runs (saving, attachments,
 * threading, the `bh_wp_mailboxes_new_email` action). Deleted emails come back with "Check now".
 *
 * Tests do not use this mailbox: Playwright works in the menu-hidden E2E mailbox.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\API\API;
use BrianHenryIE\WP_Mailboxes\API\Email_Thread_Linker;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Account_Factory;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_Thread_Taxonomy;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections\Demo_Delivered_Connection;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections\Demo_Fetching_Connection;
use Psr\Log\LoggerInterface;
use Throwable;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Registers the Demo mailbox and seeds its accounts and example emails.
 */
class Demo_Mailbox {

	/**
	 * The account that fetches its emails.
	 */
	public const FETCHING_ACCOUNT_EMAIL_ADDRESS = 'support@example-shop.test';

	/**
	 * The receive-only account emails are delivered to.
	 */
	public const DELIVERED_ACCOUNT_EMAIL_ADDRESS = 'orders@example-shop.test';

	/**
	 * Where the receive-only account's example emails are read from when seeding.
	 */
	protected const DELIVERED_EMAILS_DIRECTORY = __DIR__ . '/../demo-emails/delivered';

	/**
	 * The mailbox's API instance, set by {@see self::register()}.
	 *
	 * @var API
	 */
	protected API $api;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		protected LoggerInterface $logger,
	) {
	}

	/**
	 * The Demo mailbox's settings. REST stays disabled so the site still advertises exactly the Fixtures and
	 * E2E ingress endpoints.
	 */
	public static function make_settings(): Mailbox_Settings {
		return new Mailbox_Settings( 'development-plugin', 'Demo Email', 'Demo Accounts' );
	}

	/**
	 * Register the mailbox and its connections, and seed the accounts once WordPress has initialised.
	 *
	 * Call on `plugins_loaded`.
	 */
	public function register(): void {

		$settings  = self::make_settings();
		$this->api = BH_WP_Mailboxes::make( $settings, $this->logger );

		$fetching_account_settings = new Demo_Account_Settings( self::FETCHING_ACCOUNT_EMAIL_ADDRESS, 'Support (fetched)' );
		// The connection registers its own hooks.
		new Demo_Fetching_Connection( $settings, $fetching_account_settings, $this->make_email_repository() );

		new Demo_Delivered_Connection( $settings )->register_hooks();

		// After the CPTs (init 10) and the thread taxonomy (init 11) are registered; inserting posts on
		// `plugins_loaded` runs before `$wp_rewrite` exists, which `wp_unique_post_slug()` reads.
		add_action( 'init', $this->seed( ... ), 20 );
	}

	/**
	 * Create any missing demo account and seed its emails.
	 *
	 * Runs on every request but only does work for an account that does not exist yet, so deleting an
	 * account re-creates it (with its emails) on the next page load.
	 *
	 * @hooked init
	 */
	public function seed(): void {

		$accounts = $this->api->get_email_accounts();

		try {
			if ( ! isset( $accounts[ self::FETCHING_ACCOUNT_EMAIL_ADDRESS ] ) ) {
				$account = $this->api->configure_email_account(
					email_address: self::FETCHING_ACCOUNT_EMAIL_ADDRESS,
					display_name: 'Support (fetched)',
					connection_type_class: Demo_Fetching_Connection::class,
				);
				$this->seed_fetched_emails( $account );
			}

			if ( ! isset( $accounts[ self::DELIVERED_ACCOUNT_EMAIL_ADDRESS ] ) ) {
				$account = $this->api->configure_email_account(
					email_address: self::DELIVERED_ACCOUNT_EMAIL_ADDRESS,
					display_name: 'Orders (delivered)',
					connection_type_class: Demo_Delivered_Connection::class,
				);
				$this->seed_delivered_emails( $account );
			}
		} catch ( Throwable $exception ) {
			$this->logger->error(
				'Failed to seed the Demo mailbox: {message}',
				array(
					'message'   => $exception->getMessage(),
					'exception' => $exception,
				)
			);
		}
	}

	/**
	 * Fetch the fetching account's emails through the API, exactly as "Check now" would.
	 *
	 * @param BH_Email_Account $account The fetching account.
	 */
	protected function seed_fetched_emails( BH_Email_Account $account ): void {

		$result = $this->api->check_email_for_account( $account );

		if ( ! $result->success ) {
			$this->logger->warning(
				'Seeding the Demo mailbox fetch failed: {message}',
				array( 'message' => $result->message )
			);
		}
	}

	/**
	 * Save the receive-only account's emails the way a push delivery (e.g. the REST ingress) does, then
	 * announce each one.
	 *
	 * @param BH_Email_Account $account The receive-only account.
	 */
	protected function seed_delivered_emails( BH_Email_Account $account ): void {

		$repository = $this->make_email_repository();
		$parser     = new MailMimeParser();
		$files      = glob( self::DELIVERED_EMAILS_DIRECTORY . '/*.eml' ) ?: array();

		foreach ( $files as $filepath ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local filesystem path, not a remote URL.
			$message    = $parser->parse( (string) file_get_contents( $filepath ), true );
			$message_id = $message->getMessageId() ?? 'sha256:' . hash_file( 'sha256', $filepath );

			if ( $repository->is_post_for_message_id( $account->get_account_email_address(), $message_id ) ) {
				continue;
			}

			$bh_email = $repository->save_new(
				new Fetched_Email( $message, new Remote_Email_Coordinates( $message_id ) ),
				$this->api->get_settings(),
				$account,
			);

			$this->api->alert_new_email( $account, $bh_email );
		}
	}

	/**
	 * An email repository for the Demo mailbox, wired as the library wires its own, so threads can span
	 * the two accounts.
	 */
	protected function make_email_repository(): Email_WP_Post_Repository {

		$settings         = self::make_settings();
		$emails_post_type = $settings->get_emails_cpt_underscored_20();

		return new Email_WP_Post_Repository(
			$emails_post_type,
			new BH_Email_Factory( $this->logger ),
			$this->logger,
			new Email_Thread_Linker(
				$emails_post_type,
				new BH_Email_Thread_Taxonomy( $emails_post_type, $this->logger ),
				new Email_Account_WP_Post_Repository(
					$settings->get_email_accounts_cpt_underscored_20(),
					new BH_Email_Account_Factory( $this->logger ),
					$this->logger,
				),
				$this->logger
			)
		);
	}
}
