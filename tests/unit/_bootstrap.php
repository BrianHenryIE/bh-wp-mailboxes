<?php
/**
 * PHPUnit bootstrap file for WP_Mock.
 *
 * @package           brianhenryie/bh-wp-mailboxes
 */

WP_Mock::setUsePatchwork( true );
WP_Mock::bootstrap();

require_once codecept_absolute_path( 'wordpress/wp-includes/class-wp-error.php' );
require_once codecept_absolute_path( 'wordpress/wp-includes/rest-api/class-wp-rest-server.php' );
require_once codecept_absolute_path( 'wordpress/wp-includes/rest-api/class-wp-rest-request.php' );
require_once codecept_absolute_path( 'wordpress/wp-includes/class-wp-http-response.php' );
require_once codecept_absolute_path( 'wordpress/wp-includes/rest-api/class-wp-rest-response.php' );

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
}
