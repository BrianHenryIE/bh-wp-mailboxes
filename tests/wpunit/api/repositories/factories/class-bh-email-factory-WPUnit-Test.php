<?php
/**
 * Tests BH_Email_Factory::from_wp_post() across the four sanitized fixture emails,
 * verifying that each email type round-trips correctly through save_new → from_wp_post.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories\Factories;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;
use DateTimeInterface;
use Mockery;
use WP_Post;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\Repositories\Factories\BH_Email_Factory
 */
class BH_Email_Factory_WPUnit_Test extends \BrianHenryIE\WP_Mailboxes\WPUnit_Testcase {

	/** @var BH_WP_Mailboxes_Settings_Interface */
	protected BH_WP_Mailboxes_Settings_Interface $settings;

	/** @var string */
	protected string $post_type = 'test_factory_cpt';

	protected function setUp(): void {
		parent::setUp();

		$this->settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$this->settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( $this->post_type );
		$this->settings->allows( 'get_emails_cpt_friendly_name' )->andReturn( 'Test Factory CPT' );

		$cpt = new BH_Email_CPT( $this->settings, $this->logger );
		$cpt->register_cpt();
		$cpt->register_post_statuses();
	}

	/**
	 * Html-and-plaintext.eml: multipart/alternative — both bodies should be hydrated.
	 *
	 * @covers ::from_wp_post
	 */
	public function test_from_wp_post_html_and_plaintext(): void {
		$post_id = $this->create_post_from_fixture(
			$this->post_type,
			codecept_root_dir( 'tests/_data/wpunit/html-and-plaintext.eml' ),
		);

		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );

		$sut    = new BH_Email_Factory( $this->logger );
		$result = $sut->from_wp_post( $post );

		$sent_at = $result->get_sent_at();
		$this->assertInstanceOf( DateTimeInterface::class, $sent_at );

		$this->assertSame( 'Re: www.bhwp.ie - Your Website Ready for an Upgrade?', $result->get_subject() );
		$this->assertSame( 'Sagar@shinetechserve.com', $result->get_from_email() );
		// Date: Fri, 26 Sep 2025 13:32:58 +0530 — timezone preserved as-is from the header.
		$this->assertSame( '2025-09-26 13:32:58', $sent_at->format( 'Y-m-d H:i:s' ) );
		$this->assertNotNull( $result->body_html, 'HTML body should be present' );
		$this->assertNotNull( $result->body_plain_text, 'Plain-text body should be present' );
	}

	/**
	 * Html-no-plain-text.eml: text/html only — HTML present, plain text absent.
	 *
	 * @covers ::__construct
	 * @covers ::from_wp_post
	 */
	public function test_from_wp_post_html_no_plain_text(): void {
		$post_id = $this->create_post_from_fixture(
			$this->post_type,
			codecept_root_dir( 'tests/_data/wpunit/html-no-plain-text.eml' ),
		);

		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );

		$sut    = new BH_Email_Factory( $this->logger );
		$result = $sut->from_wp_post( $post );

		$sent_at = $result->get_sent_at();
		$this->assertInstanceOf( DateTimeInterface::class, $sent_at );

		$this->assertSame( 'DMARC weekly digest for bhwp.ie', $result->get_subject() );
		$this->assertSame( 'dmarc@postmarkapp.com', $result->get_from_email() );
		// Date: Mon, 25 May 2026 12:44:27 +0000.
		$this->assertSame( '2026-05-25 12:44:27', $sent_at->format( 'Y-m-d H:i:s' ) );
		$this->assertNotNull( $result->body_html, 'HTML body should be present' );
		$this->assertNull( $result->body_plain_text, 'Plain-text body should be absent' );
	}

	/**
	 * Non-multipart.eml: text/plain only — plain text present, HTML absent; subject is encoded.
	 *
	 * @covers ::from_wp_post
	 */
	public function test_from_wp_post_non_multipart_plain_text_only(): void {
		$post_id = $this->create_post_from_fixture(
			$this->post_type,
			codecept_root_dir( 'tests/_data/wpunit/non-multipart.eml' ),
		);

		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );

		$sut    = new BH_Email_Factory( $this->logger );
		$result = $sut->from_wp_post( $post );

		$sent_at = $result->get_sent_at();
		$this->assertInstanceOf( DateTimeInterface::class, $sent_at );

		// Subject is QP-encoded in the raw .eml; post_title holds the decoded value.
		$this->assertSame(
			'[BHWP Plugins] Please moderate: "Add Playwright E2E tests to existing WordPress plugins"',
			$result->get_subject(),
		);
		$this->assertSame( 'contact@bhwp.ie', $result->get_from_email() );
		// Date: Thu, 11 Sep 2025 07:04:43 +0000.
		$this->assertSame( '2025-09-11 07:04:43', $sent_at->format( 'Y-m-d H:i:s' ) );
		$this->assertNotNull( $result->body_plain_text, 'Plain-text body should be present' );
		$this->assertNull( $result->body_html, 'HTML body should be absent' );
	}

	/**
	 * Test_save_new.eml: text/html — subject and sent_at round-trip correctly.
	 *
	 * @covers ::from_wp_post
	 */
	public function test_from_wp_post_html_only(): void {
		$post_id = $this->create_post_from_fixture(
			$this->post_type,
			codecept_root_dir( 'tests/_data/wpunit/test_save_new.eml' ),
		);

		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );

		$sut    = new BH_Email_Factory( $this->logger );
		$result = $sut->from_wp_post( $post );

		$sent_at = $result->get_sent_at();
		$this->assertInstanceOf( DateTimeInterface::class, $sent_at );

		$this->assertSame( '[Wordfence Alert] Problems found on bhwp.ie', $result->get_subject() );
		$this->assertSame( 'contact@bhwp.ie', $result->get_from_email() );
		// Date: Wed, 30 Jul 2025 03:38:07 +0000.
		$this->assertSame( '2025-07-30 03:38:07', $sent_at->format( 'Y-m-d H:i:s' ) );
		$this->assertNotNull( $result->body_html, 'HTML body should be present' );
		$this->assertNull( $result->body_plain_text, 'Plain-text body should be absent' );
	}
}
