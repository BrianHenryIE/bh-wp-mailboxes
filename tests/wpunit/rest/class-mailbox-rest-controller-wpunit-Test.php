<?php
/**
 * WPUnit tests for the REST controllers' shared base class and the namespace helper.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\REST;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WP_Includes\Mailbox_Capabilities;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Mockery;
use WP_Error;
use WP_REST_Request;

/**
 * @covers \BrianHenryIE\WP_Mailboxes\REST\Mailbox_REST_Controller
 * @covers \BrianHenryIE\WP_Mailboxes\REST\REST_Namespace
 */
class Mailbox_REST_Controller_WPUnit_Test extends WPUnit_Testcase {

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Mocked settings, with or without a REST namespace.
	 *
	 * @param ?string $rest_namespace The consumer's REST namespace, or null for none.
	 */
	protected function make_settings( ?string $rest_namespace ): BH_WP_Mailboxes_Settings_Interface {
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_plugin_slug' )->andReturn( 'my-plugin' );
		$settings->allows( 'get_rest_namespace' )->andReturn( $rest_namespace );
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( 'my_plugin_emails' );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( 'my_plugin_accounts' );
		return $settings;
	}

	/**
	 * A concrete controller exposing the protected helpers.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings The mailbox settings.
	 */
	protected function make_controller( BH_WP_Mailboxes_Settings_Interface $settings ): Mailbox_REST_Controller {
		return new class( $settings, new Mailbox_Capabilities( $settings ), $this->logger ) extends Mailbox_REST_Controller {
			public function ns(): string {
				return $this->namespace;
			}
			public function id( WP_REST_Request $request ): int {
				return $this->request_id( $request );
			}
			public function forbidden_error( string $message ): WP_Error {
				return $this->forbidden( $message );
			}
			public function not_found_error( string $message ): WP_Error {
				return $this->not_found( $message );
			}
		};
	}

	/**
	 * The namespace is the consumer's REST namespace when configured, else the plugin slug; always `/v2`.
	 */
	public function test_namespace_uses_the_rest_namespace_or_the_plugin_slug(): void {
		$this->assertSame( 'shop/v2', REST_Namespace::for_mailbox( $this->make_settings( 'shop' ) ) );
		$this->assertSame( 'my-plugin/v2', REST_Namespace::for_mailbox( $this->make_settings( null ) ) );
		$this->assertSame( 'my-plugin/v2', REST_Namespace::for_mailbox( $this->make_settings( '' ) ) );

		$this->assertSame( 'shop/v2', Mailbox_REST_Controller::get_namespace( $this->make_settings( 'shop' ) ) );
	}

	/**
	 * The URL is the site's REST URL for the namespace, for scripts to build route URLs from.
	 */
	public function test_url_is_the_rest_url_of_the_namespace(): void {
		$url = REST_Namespace::url( $this->make_settings( 'shop' ) );

		$this->assertSame( rest_url( 'shop/v2' ), $url );
		$this->assertStringEndsWith( '/shop/v2', $url );
	}

	/**
	 * The controller registers under the namespace, in both WP_REST_Controller's property and its own.
	 */
	public function test_controller_takes_the_namespace(): void {
		$controller = $this->make_controller( $this->make_settings( 'shop' ) );

		$this->assertSame( 'shop/v2', $controller->ns() );
	}

	/**
	 * The `id` parameter as an integer; 0 when absent or not numeric.
	 */
	public function test_request_id(): void {
		$controller = $this->make_controller( $this->make_settings( null ) );

		$request = new WP_REST_Request( 'GET', '/my-plugin/v2/my-plugin-emails/42' );
		$this->assertSame( 0, $controller->id( $request ), 'Absent.' );

		$request->set_param( 'id', '42' );
		$this->assertSame( 42, $controller->id( $request ) );

		$request->set_param( 'id', 'forty-two' );
		$this->assertSame( 0, $controller->id( $request ), 'Not numeric.' );
	}

	/**
	 * Forbidden is 401 for a visitor and 403 for a logged-in user; not found is 404.
	 */
	public function test_error_helpers(): void {
		$controller = $this->make_controller( $this->make_settings( null ) );

		wp_set_current_user( 0 );
		$error = $controller->forbidden_error( 'No.' );
		$this->assertSame( 'bh_wp_mailboxes_forbidden', $error->get_error_code() );
		$this->assertSame( 'No.', $error->get_error_message() );
		$this->assertSame( 401, $error->get_error_data()['status'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $controller->forbidden_error( 'No.' )->get_error_data()['status'] );

		$not_found = $controller->not_found_error( 'Gone.' );
		$this->assertSame( 'bh_wp_mailboxes_not_found', $not_found->get_error_code() );
		$this->assertSame( 404, $not_found->get_error_data()['status'] );
	}
}
