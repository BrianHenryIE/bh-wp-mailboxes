<?php
/**
 * The main entrypoint for the library.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

use BrianHenryIE\WP_Mailboxes\API\API;
use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Account_Factory;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Factories\New_Email_Factory;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_WP_Mailboxes_Hooks;
use BrianHenryIE\WP_Private_Uploads\BH_WP_Private_Uploads_Hooks;
use BrianHenryIE\WP_Private_Uploads\Private_Uploads_Settings_Interface;
use BrianHenryIE\WP_Private_Uploads\Private_Uploads_Settings_Trait;
use BrianHenryIE\WP_Private_Uploads\Private_Uploads;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Main library class; singleton wrapper around the API.
 */
class BH_WP_Mailboxes extends API {

	/**
	 * The library version.
	 *
	 * Used when enqueuing scripts and styles.
	 */
	public static function get_version(): string {
		return '1.0.0';
	}

	/**
	 * Every mailbox instance created via {@see self::make()}, for the registry filter.
	 *
	 * @var API_Interface[]
	 */
	protected static array $mailboxes = array();

	/**
	 * Append this plugin's registered mailbox instances to the registry.
	 *
	 * @hooked bh_wp_mailboxes_registered_mailboxes
	 *
	 * @param API_Interface[] $mailboxes   Mailboxes registered so far.
	 * @param string          $plugin_slug The plugin slug whose mailboxes are being requested.
	 *
	 * @return API_Interface[]
	 */
	public static function filter( array $mailboxes, string $plugin_slug ): array {

		$file_plugin_slug = dirname( plugin_basename( __DIR__ ) );
		if ( $file_plugin_slug !== $plugin_slug ) {
			return $mailboxes;
		}

		return array_merge( $mailboxes, self::$mailboxes );
	}

	/**
	 * Idempotently initialize the library.
	 *
	 * Hooked at 100 so the default runs before this.
	 */
	public static function init(): void {
		add_filter( 'bh_wp_mailboxes_registered_mailboxes', __CLASS__ . '::filter', 100, 2 );
	}

	/**
	 * Create an instance of the BH_WP_Mailboxes API class.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings Plugin settings.
	 * @param ?LoggerInterface                   $logger   PSR-3 logger.
	 *
	 * @throws Exception When settings are not provided on first use.
	 */
	public static function make(
		BH_WP_Mailboxes_Settings_Interface $settings,
		?LoggerInterface $logger = null
	): API {
		self::init();

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
			new New_Email_Factory(),
			$private_uploads,
			$logger
		);
		new BH_WP_Mailboxes_Hooks( $mailboxes_api, $settings, $logger, $private_uploads );

		self::$mailboxes[] = $mailboxes_api;

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
	 * @throws Exception When the CPT names are not unique.
	 */
	protected static function validate_settings( BH_WP_Mailboxes_Settings_Interface $settings ): void {
		if ( $settings->get_emails_cpt_underscored_20() === $settings->get_email_accounts_cpt_underscored_20() ) {
			throw new Exception( 'The emails CPT and email accounts CPT cannot have the same slug. Please change one of them in your settings.' );
		}
	}

	/**
	 * One Private_Uploads instance per uploads directory. {@see self::make()} runs once per mailbox,
	 * but every mailbox of a plugin shares one attachments directory, so the instance – and its hooks
	 * (post type registration, URL-check crons, the publicly-accessible admin notice) – must only be
	 * created once per directory, not once per mailbox.
	 *
	 * @var array<string, Private_Uploads>
	 */
	protected static array $private_uploads_instances = array();

	/**
	 * We save attachments in a secure directory.
	 *
	 * @see https://github.com/BrianHenryIE/bh-wp-private-uploads
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings The plugin name, CPT names, cron schedules.
	 * @param LoggerInterface                    $logger PSR logger.
	 */
	protected static function make_private_uploads( BH_WP_Mailboxes_Settings_Interface $settings, LoggerInterface $logger ): ?Private_Uploads {

		$directory_name = $settings->get_private_uploads_directory_name();

		if ( is_null( $directory_name ) || ! class_exists( Private_Uploads::class ) ) {
			return null;
		}

		if ( isset( self::$private_uploads_instances[ $directory_name ] ) ) {
			return self::$private_uploads_instances[ $directory_name ];
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

			/**
			 * The private-uploads trait default truncates the plugin slug to 12 characters + `_private`,
			 * which collides with other plugins' private-uploads instances whose slugs share a prefix
			 * (e.g. `development-plugin` and bh-wp-logger's `development-plugin-logger` both derive
			 * `development__private`), duplicating the post type, admin notice, and dismissal option.
			 * Use an email-attachments-specific name instead.
			 */
			public function get_post_type_name(): string {
				// Post type names are limited to 20 characters; `_email_attach` is 13.
				return sanitize_key( substr( str_replace( '-', '_', $this->get_plugin_slug() ), 0, 7 ) . '_email_attach' );
			}
		};

		// We don't use the Private_Uploads singleton in case the parent plugin also needs it.
		$private_uploads = new Private_Uploads( $private_uploads_settings, $logger );
		new BH_WP_Private_Uploads_Hooks( $private_uploads, $private_uploads_settings, $logger );

		self::$private_uploads_instances[ $directory_name ] = $private_uploads;

		return $private_uploads;
	}
}
