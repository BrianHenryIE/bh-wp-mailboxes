<?php
/**
 * The public-facing functionality of the plugin.
 *
 * @link       http://example.com
 * @since      1.0.0
 *
 * @package    BH_WP_Emails
 * @subpackage BH_WP_Emails/frontend
 */

namespace BH_WP_Emails\Frontend;

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the frontend-facing stylesheet and JavaScript.
 *
 * @package    BH_WP_Emails
 * @subpackage BH_WP_Emails/frontend
 * @author     BrianHenryIE <BrianHenryIE@gmail.com>
 */
class Frontend {

	/**
	 * Register the stylesheets for the frontend-facing side of the site.
	 *
	 * @hooked wp_enqueue_scripts
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles(): void {
		$version = defined( 'BH_WP_EMAILS_VERSION' ) ? BH_WP_EMAILS_VERSION : time();

		wp_enqueue_style( 'bh-wp-emails', plugin_dir_url( __FILE__ ) . 'css/bh-wp-emails-frontend.css', array(), $version, 'all' );

	}

	/**
	 * Register the JavaScript for the frontend-facing side of the site.
	 *
	 * @hooked wp_enqueue_scripts
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts(): void {
		$version = defined( 'BH_WP_EMAILS_VERSION' ) ? BH_WP_EMAILS_VERSION : time();

		wp_enqueue_script( 'bh-wp-emails', plugin_dir_url( __FILE__ ) . 'js/bh-wp-emails-frontend.js', array( 'jquery' ), $version, false );

	}

}
