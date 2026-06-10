<?php
/**
 * The main entrypoint for the library.
 *
 * Requires settings on first use `BH_WP_Mailbox::instance( $mailbox_settings );`
 * Then can be retrieved with `BH_WP_Mailbox::instance();`
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

use BrianHenryIE\WP_Mailboxes\API\API;
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
	 * The singleton instance.
	 *
	 * @var BH_WP_Mailboxes
	 */
	protected static BH_WP_Mailboxes $instance;

	/**
	 * Returns the singleton instance, creating it on first call.
	 *
	 * @param ?BH_WP_Mailboxes_Settings_Interface $settings Plugin settings (required on first call).
	 * @param ?LoggerInterface                    $logger   PSR-3 logger.
	 *
	 * @throws \Exception When settings are not provided on first use.
	 */
	public static function instance(
		?BH_WP_Mailboxes_Settings_Interface $settings = null,
		?LoggerInterface $logger = null
	): BH_WP_Mailboxes {

		if ( ! empty( self::$instance ) ) {
			return self::$instance;
		}

		if ( ! is_null( $settings ) ) {
			self::$instance = new BH_WP_Mailboxes( $settings, $logger );
			new BH_WP_Mailboxes_Hooks( self::$instance, $settings, $logger );
			return self::$instance;
		}

		throw new \Exception( 'Settings must be provided on first use' );
	}

	/**
	 * Constructor.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings Plugin settings.
	 * @param ?LoggerInterface                   $logger   PSR-3 logger.
	 */
	protected function __construct( BH_WP_Mailboxes_Settings_Interface $settings, ?LoggerInterface $logger = null ) {

		$logger ??= new NullLogger();

		$private_uploads = null;

		if ( ! is_null( $settings->get_private_uploads_directory_name() ) ) {
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
		}

		parent::__construct( $settings, $private_uploads, $logger );
	}
}
