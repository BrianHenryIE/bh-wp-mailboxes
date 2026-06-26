<?php
/**
 * Unit tests for the development plugin's fixtures-backed email connection.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections;

use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use DateTimeImmutable;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections\Mock_Mailbox_Fixtures_Connection
 */
class Mock_Mailbox_Fixtures_Connection_Unit_Test extends Unit_Testcase {

	/**
	 * Build the connection with mocked collaborators. The constructor registers hooks, so add_filter /
	 * add_action are stubbed.
	 */
	private function make_sut(): Mock_Mailbox_Fixtures_Connection {

		\WP_Mock::userFunction( 'add_filter' );
		\WP_Mock::userFunction( 'add_action' );

		return new Mock_Mailbox_Fixtures_Connection(
			Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class ),
			Mockery::mock( Email_Account_Settings_Interface::class ),
			Mockery::mock( Email_WP_Post_Repository::class ),
		);
	}

	/**
	 * The connection reports a friendly "Fixtures" name for the UI.
	 *
	 * @covers ::get_friendly_name
	 */
	public function test_get_friendly_name(): void {
		$this->assertSame( 'Fixtures', $this->make_sut()->get_friendly_name() );
	}

	/**
	 * Every bundled `.eml` fixture is parsed into a Fetched_Email carrying its Message-ID and an
	 * unread remote state.
	 *
	 * @covers ::retrieve_emails
	 */
	public function test_retrieve_emails_maps_every_fixture(): void {

		$fixture_count = count( (array) glob( codecept_root_dir( 'development-plugin/connections/fixtures/*.eml' ) ) );

		$emails = $this->make_sut()->retrieve_emails( new DateTimeImmutable( '@0' ) );

		$this->assertSame( 5, $fixture_count, 'Sanity check: the bundled fixtures are present.' );
		$this->assertCount( $fixture_count, $emails );

		foreach ( $emails as $fetched ) {
			$this->assertInstanceOf( Fetched_Email::class, $fetched );
			$this->assertNotSame( '', $fetched->coordinates->message_id, 'Each fixture maps its Message-ID.' );
			$this->assertFalse( $fetched->is_remote_read, 'Fixtures default to unread at fetch time.' );
		}
	}

	/**
	 * Clears the three per-user state meta keys for the current user.
	 *
	 * @covers ::reset
	 */
	public function test_reset_clears_per_user_state(): void {

		$deleted_keys = array();

		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		\WP_Mock::userFunction( 'delete_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key ) use ( &$deleted_keys ): bool {
				$deleted_keys[] = $key;
				return true;
			}
		);

		$this->make_sut()->reset();

		$this->assertSame(
			array(
				'_mock_mailbox_fixtures_connection_is_remote_deleted',
				'_mock_mailbox_fixtures_connection_is_remote_read',
				'_mock_mailbox_fixtures_connection_is_remote_unread',
			),
			$deleted_keys
		);
	}
}
