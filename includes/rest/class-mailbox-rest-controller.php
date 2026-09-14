<?php
/**
 * Base class for the library's REST controllers: the mailbox-scoped namespace and shared error helpers.
 *
 * Routes replace the admin-ajax handlers one for one. Every route has a real permission callback that
 * delegates to {@see Mailbox_Capabilities}; none uses `__return_true`, reads included.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\REST;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WP_Includes\Mailbox_Capabilities;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;

/**
 * Namespace, error helpers and the capabilities layer shared by the emails and accounts controllers.
 */
abstract class Mailbox_REST_Controller extends WP_REST_Controller {

	use LoggerAwareTrait;

	/**
	 * Constructor.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings     Names the mailbox's post types and REST namespace.
	 * @param Mailbox_Capabilities               $capabilities The single audit point for who may do what.
	 * @param LoggerInterface                    $logger       PSR-3 logger.
	 */
	public function __construct(
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		protected Mailbox_Capabilities $capabilities,
		LoggerInterface $logger,
	) {
		$this->setLogger( $logger );
		$this->route_namespace = self::get_namespace( $settings );
		$this->namespace       = $this->route_namespace;
	}

	/**
	 * The namespace the routes are registered under ({@see self::get_namespace()}).
	 *
	 * @var non-falsy-string
	 */
	protected string $route_namespace;

	/**
	 * The REST namespace for a mailbox ({@see REST_Namespace::for_mailbox()}).
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings The mailbox settings.
	 *
	 * @return non-falsy-string
	 */
	public static function get_namespace( BH_WP_Mailboxes_Settings_Interface $settings ): string {
		return REST_Namespace::for_mailbox( $settings );
	}

	/**
	 * The `id` route parameter as an integer (0 when absent or not numeric).
	 *
	 * @param WP_REST_Request $request The request.
	 */
	protected function request_id( WP_REST_Request $request ): int {
		$id = $request->get_param( 'id' );

		return is_numeric( $id ) ? (int) $id : 0;
	}

	/**
	 * The error for a request the current user may not make: 401 when logged out, 403 when logged in.
	 *
	 * @param string $message The user-facing reason.
	 */
	protected function forbidden( string $message ): WP_Error {
		return new WP_Error( 'bh_wp_mailboxes_forbidden', $message, array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * The error for a resource that does not exist in this mailbox (an id of another post type included).
	 *
	 * @param string $message The user-facing reason.
	 */
	protected function not_found( string $message ): WP_Error {
		return new WP_Error( 'bh_wp_mailboxes_not_found', $message, array( 'status' => 404 ) );
	}
}
