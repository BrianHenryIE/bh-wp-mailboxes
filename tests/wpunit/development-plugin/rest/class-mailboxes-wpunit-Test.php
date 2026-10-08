<?php
/**
 * WPUnit tests for the development plugin's fixture REST route: threading headers on fixture emails.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Rest;

use BrianHenryIE\WP_Mailboxes\API\Email_Thread_Linker;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account_CPT;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_Thread_Taxonomy;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Mailbox_Settings;
use WP_REST_Request;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes_Development_Plugin\Rest\Mailboxes
 */
class Mailboxes_WPUnit_Test extends WPUnit_Testcase {

	/**
	 * The E2E mailbox's settings, as the development plugin builds them.
	 *
	 * @var Mailbox_Settings
	 */
	protected Mailbox_Settings $settings;

	protected function setUp(): void {
		parent::setUp();

		$this->settings = new Mailbox_Settings( 'development-plugin', 'E2E Email', 'E2E Accounts', 'bh-wp-mailboxes-dev' );

		foreach ( array( new BH_Email_CPT( $this->settings, $this->logger ), new BH_Email_Account_CPT( $this->settings, $this->logger ) ) as $cpt ) {
			if ( ! post_type_exists( $cpt instanceof BH_Email_CPT ? 'e2e_email' : 'e2e_accounts' ) ) {
				$cpt->register_cpt();
			}
			$cpt->register_post_statuses();
		}
	}

	protected function tearDown(): void {
		unregister_taxonomy( BH_Email_Thread_Taxonomy::taxonomy_name_for_post_type( 'e2e_email' ) );
		parent::tearDown();
	}

	/**
	 * POST a fixture email to the route's callback.
	 *
	 * @param array<string, string> $params The request body parameters.
	 *
	 * @return array{post_id: int, message_id: string}
	 */
	protected function create_email( array $params ): array {
		$request = new WP_REST_Request( 'POST', '/bh-wp-mailboxes-dev/v2/emails' );
		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}

		$response = new Mailboxes( $this->settings )->create_email( $request );

		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		/** @var array{post_id: int, message_id: string} $data */
		$data = $response->get_data();
		return $data;
	}

	/**
	 * The response includes the generated Message-ID, so a test can reply to the email.
	 *
	 * @covers ::create_email
	 */
	public function test_create_email_returns_the_message_id(): void {
		$created = $this->create_email( array( 'subject' => 'Root' ) );

		$this->assertGreaterThan( 0, $created['post_id'] );
		$this->assertMatchesRegularExpression( '/^<e2e-fixture-[0-9a-f-]+@bh-wp-mailboxes\.test>$/', $created['message_id'] );
	}

	/**
	 * The email is stored under its parsed Message-ID (no angle brackets), as the real connections store it, so
	 * the dedupe lookup and thread linking find it.
	 *
	 * Regression: it was stored under the bracketed id, so a reply's In-Reply-To never matched it.
	 *
	 * @covers ::create_email
	 */
	public function test_create_email_stores_the_parsed_message_id(): void {
		$created   = $this->create_email( array( 'subject' => 'Root' ) );
		$parsed_id = trim( $created['message_id'], '<>' );

		$repository = new Email_WP_Post_Repository( 'e2e_email', new BH_Email_Factory( $this->logger ), $this->logger );

		$this->assertTrue( $repository->is_post_for_message_id( Mailboxes::FIXTURE_ACCOUNT_EMAIL_ADDRESS, $parsed_id ) );
		$this->assertSame( $parsed_id, $repository->find_by_post_id( $created['post_id'] )->message_id );
	}

	/**
	 * `in_reply_to` and `references` become the email's threading headers, so a reply joins its root's thread.
	 *
	 * @covers ::create_email
	 * @covers ::build_mime
	 * @covers ::sanitize_message_id_list
	 */
	public function test_a_reply_created_with_threading_headers_joins_the_root_thread(): void {
		$root  = $this->create_email( array( 'subject' => 'Order 123' ) );
		$reply = $this->create_email(
			array(
				'subject'     => 'Re: Order 123',
				'in_reply_to' => $root['message_id'],
				'references'  => $root['message_id'],
			)
		);

		$repository  = new Email_WP_Post_Repository( 'e2e_email', new BH_Email_Factory( $this->logger ), $this->logger );
		$root_email  = $repository->find_by_post_id( $root['post_id'] );
		$reply_email = $repository->find_by_post_id( $reply['post_id'] );

		$parsed_root_id = trim( $root['message_id'], '<>' );
		$this->assertSame( array( $parsed_root_id ), $reply_email->in_reply_to );
		$this->assertSame( array( $parsed_root_id ), $reply_email->references );

		$this->assertNotNull( $root_email->thread_term_id );
		$this->assertSame( $root_email->thread_term_id, $reply_email->thread_term_id );
	}

	/**
	 * The threading parameters are reduced to Message-ID tokens: brackets are restored, anything that is not an
	 * id is dropped, and line breaks can never inject another header.
	 *
	 * @covers ::sanitize_message_id_list
	 */
	public function test_threading_parameters_cannot_inject_headers(): void {
		$created = $this->create_email(
			array(
				'subject'     => 'Re: Order 123',
				'in_reply_to' => 'parent@example.org',
				'references'  => "<root@example.org> parent@example.org\r\nX-Injected: yes",
			)
		);

		$stored_mime = htmlspecialchars_decode( (string) get_post_field( 'post_content', $created['post_id'] ) );

		$this->assertStringContainsString( "In-Reply-To: <parent@example.org>\r\n", $stored_mime );
		$this->assertStringContainsString( "References: <root@example.org> <parent@example.org>\r\n", $stored_mime );
		$this->assertStringNotContainsString( 'X-Injected', $stored_mime );

		$this->assertSame(
			array( 'root@example.org', 'parent@example.org' ),
			get_post_meta( $created['post_id'], Email_Thread_Linker::META_KEY_REFERENCES, false )
		);
	}

	/**
	 * Parameters holding no Message-ID add no header.
	 *
	 * @covers ::sanitize_message_id_list
	 */
	public function test_threading_parameters_without_ids_add_no_header(): void {
		$created = $this->create_email(
			array(
				'subject'     => 'Not a reply',
				'in_reply_to' => 'nonsense',
				'references'  => '',
			)
		);

		$stored_mime = htmlspecialchars_decode( (string) get_post_field( 'post_content', $created['post_id'] ) );

		$this->assertStringNotContainsString( 'In-Reply-To:', $stored_mime );
		$this->assertStringNotContainsString( 'References:', $stored_mime );
	}
}
