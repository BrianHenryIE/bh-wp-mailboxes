<?php
/**
 * WPUnit tests for the emails list-table row actions.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Email_Provider_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Mockery;
use WP_Post;
use ZBateson\MailMimeParser\IMessage;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Admin\Emails_List_Page
 */
class Emails_List_Page_WPUnit_Test extends WPUnit_Testcase {

	private string $post_type = 'test_list_email';

	/**
	 * Build the SUT with a mocked API + repository.
	 *
	 * @param bool $can_delete        Whether the resolved provider supports delete-on-server.
	 * @param bool $is_remote_deleted Whether the email is already deleted on the server.
	 * @param bool $has_account       Whether an account resolves for the email.
	 */
	private function make_sut( bool $can_delete = true, bool $is_remote_deleted = false, bool $has_account = true ): Emails_List_Page {

		$email = new BH_Email(
			post_id: 4242,
			post_type: $this->post_type,
			imessage: Mockery::mock( IMessage::class ),
			message_id: 'row-action@example.org',
			subject: 'Row action test',
			from_email: 'sender@example.org',
			is_remote_deleted: $is_remote_deleted,
		);

		$repository = Mockery::mock( Email_WP_Post_Repository::class );
		$repository->allows( 'find_by_post_id' )->andReturn( $email );

		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class )->shouldIgnoreMissing();
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( $this->post_type );

		$api = Mockery::mock( API_Interface::class )->shouldIgnoreMissing();

		if ( $has_account ) {
			$account  = BH_Email_Account_Fixture::make( post_id: 7 );
			$provider = Mockery::mock( Email_Provider_Interface::class, Supports_Fetching::class );
			$provider->allows( 'can_delete_on_server' )->andReturn( $can_delete );
			$api->allows( 'get_email_account_for_email' )->andReturn( $account );
			$api->allows( 'get_provider_for_email_account' )->andReturn( $provider );
		} else {
			$api->allows( 'get_email_account_for_email' )->andReturnNull();
		}

		return new Emails_List_Page( $repository, $api, $settings, $this->logger );
	}

	private function make_post(): WP_Post {
		return new WP_Post(
			(object) array(
				'ID'        => 4242,
				'post_type' => $this->post_type,
			)
		);
	}

	/**
	 * "Trash" is relabelled "Trash locally" for email rows.
	 *
	 * @covers ::row_actions
	 */
	public function test_trash_is_relabelled_trash_locally(): void {
		$actions = $this->make_sut()->row_actions(
			array( 'trash' => '<a href="#" class="submitdelete" aria-label="Move to the Trash">Trash</a>' ),
			$this->make_post()
		);

		$this->assertStringContainsString( '>Trash locally<', $actions['trash'] );
		$this->assertStringNotContainsString( '>Trash<', $actions['trash'] );
	}

	/**
	 * "Delete on server" is added when the provider supports it and the email is not already deleted.
	 *
	 * @covers ::row_actions
	 */
	public function test_delete_on_server_added_when_supported(): void {
		$actions = $this->make_sut( can_delete: true, is_remote_deleted: false )->row_actions( array(), $this->make_post() );

		$this->assertArrayHasKey( 'bh_delete_on_server', $actions );
		$this->assertStringContainsString( 'Delete on server', $actions['bh_delete_on_server'] );
		$this->assertStringContainsString( 'data-post-id="4242"', $actions['bh_delete_on_server'] );
		$this->assertStringContainsString( 'bh-email-delete-on-server', $actions['bh_delete_on_server'] );
	}

	/**
	 * "Delete on server" is absent when the provider cannot delete on the server.
	 *
	 * @covers ::row_actions
	 */
	public function test_delete_on_server_absent_when_provider_cannot_delete(): void {
		$actions = $this->make_sut( can_delete: false )->row_actions( array(), $this->make_post() );

		$this->assertArrayNotHasKey( 'bh_delete_on_server', $actions );
	}

	/**
	 * "Delete on server" is absent when the email is already deleted on the server.
	 *
	 * @covers ::row_actions
	 */
	public function test_delete_on_server_absent_when_already_deleted(): void {
		$actions = $this->make_sut( can_delete: true, is_remote_deleted: true )->row_actions( array(), $this->make_post() );

		$this->assertArrayNotHasKey( 'bh_delete_on_server', $actions );
	}

	/**
	 * "Delete on server" is absent when no account resolves for the email.
	 *
	 * @covers ::row_actions
	 */
	public function test_delete_on_server_absent_when_no_account(): void {
		$actions = $this->make_sut( has_account: false )->row_actions( array(), $this->make_post() );

		$this->assertArrayNotHasKey( 'bh_delete_on_server', $actions );
	}

	/**
	 * Rows of other post types are left untouched.
	 *
	 * @covers ::row_actions
	 */
	public function test_other_post_types_are_unchanged(): void {
		$original = array( 'trash' => '<a href="#" class="submitdelete">Trash</a>' );
		$post     = new WP_Post(
			(object) array(
				'ID'        => 99,
				'post_type' => 'post',
			)
		);

		$actions = $this->make_sut()->row_actions( $original, $post );

		$this->assertSame( $original, $actions );
	}
}
