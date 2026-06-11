<?php
/**
 * The main entrypoint for the library.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

use BrianHenryIE\WP_Mailboxes\API\API;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Factories\BH_Email_Account_Factory;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_WP_Mailboxes_Hooks;
use BrianHenryIE\WP_Private_Uploads\BH_WP_Private_Uploads_Hooks;
use BrianHenryIE\WP_Private_Uploads\Private_Uploads_Settings_Interface;
use BrianHenryIE\WP_Private_Uploads\Private_Uploads_Settings_Trait;
use BrianHenryIE\WP_Private_Uploads\Private_Uploads;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Main library class; singleton wrapper around the API.
 */
class BH_WP_Mailboxes extends API {

	/**
	 * @param BH_WP_Mailboxes_Settings_Interface $settings Plugin settings
	 * @param ?LoggerInterface                   $logger   PSR-3 logger.
	 *
	 * @throws \Exception When settings are not provided on first use.
	 */
	public static function make(
		BH_WP_Mailboxes_Settings_Interface $settings,
		?LoggerInterface $logger = null
	): API {
		self::validate_settings( $settings );
		$logger ??= new NullLogger();

		$emails_post_type = $settings->get_emails_cpt_underscored_20();
		$bh_email_factory = new BH_Email_Factory( $logger );
		$email_repository = new Email_WP_Post_Repository(
			$emails_post_type,
			$bh_email_factory,
			$logger
		);

		$email_accounts_post_type = $settings->get_email_accounts_cpt_underscored_20();
		$bh_email_account_factory = new BH_Email_Account_Factory( $logger );
		$email_account_repository = new Email_Account_WP_Post_Repository(
			$email_accounts_post_type,
			$bh_email_account_factory,
			$logger,
		);

		$private_uploads = self::make_private_uploads( $settings, $logger );

		$mailboxes_api = new API(
			$settings,
			$email_repository,
			$email_account_repository,
			$private_uploads,
			$logger
		);
		new BH_WP_Mailboxes_Hooks( $mailboxes_api, $settings, $logger );
		return $mailboxes_api;
	}

	/**
	 * Because the defaults trait truncates strings, it's easily possible that two custom post types have the same
	 * name. This is a quick check to avoid that.
	 *
	 * This is something that would be caught during development.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings For `::get_*_cpt_underscored_20()`.
	 *
	 * @throws \Exception
	 */
	protected static function validate_settings( BH_WP_Mailboxes_Settings_Interface $settings ): void {
		if ( $settings->get_emails_cpt_underscored_20() === $settings->get_email_accounts_cpt_underscored_20() ) {
			throw new \Exception( 'The emails CPT and email accounts CPT cannot have the same slug. Please change one of them in your settings.' );
		}
	}

	/**
	 * We save attachments in a secure directory.
	 *
	 * @see https://github.com/BrianHenryIE/bh-wp-private-uploads
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings
	 * @param LoggerInterface                    $logger PSR logger.
	 */
	protected static function make_private_uploads( BH_WP_Mailboxes_Settings_Interface $settings, LoggerInterface $logger ): ?Private_Uploads {
		if ( is_null( $settings->get_private_uploads_directory_name() ) || ! class_exists( Private_Uploads::class ) ) {
			return null;
		}

		// Make the attachments' directory inaccessible to the public.
		$private_uploads_settings = new class( $settings ) implements Private_Uploads_Settings_Interface {
			use Private_Uploads_Settings_Trait;

			/**
			 * Constructor.
			 *
			 * @param BH_WP_Mailboxes_Settings_Interface $mailboxes_settings Mailboxes settings.
			 */
			public function __construct( protected BH_WP_Mailboxes_Settings_Interface $mailboxes_settings ) {
			}

			/**
			 * Returns the plugin slug.
			 */
			public function get_plugin_slug(): string {
				return $this->mailboxes_settings->get_plugin_slug();
			}

			/**
			 * Returns the uploads subdirectory name.
			 */
			public function get_uploads_subdirectory_name(): string {
				return $this->mailboxes_settings->get_private_uploads_directory_name();
			}
		};

		// We don't use the Private_Uploads singleton in case the parent plugin also needs it.
		$private_uploads = new Private_Uploads( $private_uploads_settings, $logger );
		new BH_WP_Private_Uploads_Hooks( $private_uploads, $private_uploads_settings, $logger );
		return $private_uploads;
	}
}
