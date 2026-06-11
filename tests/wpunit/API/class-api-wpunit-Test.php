<?php
/**
 * WPUnit tests for API.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use BrianHenryIE\WP_Private_Uploads\API\API as Private_Uploads;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\API
 */
class API_WPUnit_Test extends WPUnit_Testcase {

	// -------------------------------------------------------------------------
	// Order notes / log comments
	// -------------------------------------------------------------------------

	/**
	 * Returns an API instance with all it dependencies mocked unless they are specified.
	 *
	 * @param ?BH_WP_Mailboxes_Settings_Interface $settings
	 * @param ?Email_WP_Post_Repository           $email_repository
	 * @param ?Email_Account_WP_Post_Repository   $email_account_repository
	 * @param ?Private_Uploads                    $private_uploads
	 * @param ?LoggerInterface                    $logger
	 */
	protected function get_api(
		?BH_WP_Mailboxes_Settings_Interface $settings = null,
		?Email_WP_Post_Repository $email_repository = null,
		?Email_Account_WP_Post_Repository $email_account_repository = null,
		?Private_Uploads $private_uploads = null,
		?LoggerInterface $logger = null
	): API {
		return new API(
			$settings ?? \Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class ),
			$email_repository ?? \Mockery::mock( Email_WP_Post_Repository::class ),
			$email_account_repository ?? \Mockery::mock( Email_Account_WP_Post_Repository::class ),
			$private_uploads ?? \Mockery::mock( Private_Uploads::class ),
			$logger ?? $this->logger,
		);
	}

	/**
	 * Requirement 7: insert_email_log_note creates a WP comment with comment_type 'bh_email_log'.
	 *
	 * This confirms that status-change logs are stored in a way that lets them be
	 * rendered like WooCommerce order notes (same pattern: custom comment_type on the post).
	 *
	 * @covers ::insert_email_log_note
	 */
	public function test_insert_email_log_note_creates_comment_with_bh_email_log_type(): void {

		$post_type = 'test_api_email';
		if ( ! post_type_exists( $post_type ) ) {
			register_post_type( $post_type, array( 'public' => false ) );
		}

		$repository = new Email_WP_Post_Repository(
			$post_type,
			new BH_Email_Factory( $this->logger ),
			$this->logger,
		);

		$post_id = $this->create_post_from_fixture( $post_type );

		$api = $this->get_api( email_repository: $repository );

		$api->insert_email_log_note( $post_id, 'Status changed from "bh_email_new" to "bh_email_processed".' );

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'bh_email_log',
			)
		);

		$this->assertCount( 1, $comments, 'Exactly one bh_email_log comment should exist' );
		$this->assertSame( 'bh_email_log', $comments[0]->comment_type );
		$this->assertStringContainsString( 'bh_email_processed', $comments[0]->comment_content );
	}
}
