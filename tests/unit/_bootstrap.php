<?php
/**
 * PHPUnit bootstrap file for WP_Mock.
 *
 * @package           brianhenryie/bh-wp-mailboxes
 */

WP_Mock::setUsePatchwork( true );
WP_Mock::bootstrap();

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
}
