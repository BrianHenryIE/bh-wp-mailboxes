<?php
/**
 * WPUnit tests for the mailbox-scoped capability model.
 *
 * Two mailboxes are registered (as two consuming plugins would), so the tests can prove that a grant on
 * one mailbox does not leak to the other, and that per-post checks fail closed for posts of other types.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\WP_Mailboxes\BH_Email_Account_CPT;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\WP_Includes\Mailbox_Capabilities
 */
class Mailbox_Capabilities_WPUnit_Test extends WPUnit_Testcase {

	/**
	 * The capability layers registered for the two mailboxes, keyed by mailbox name.
	 *
	 * @var array<string, Mailbox_Capabilities>
	 */
	protected array $mailboxes = array();

	/**
	 * The filter callbacks added, so tearDown can remove them.
	 *
	 * @var callable[]
	 */
	protected array $filters = array();

	public function setUp(): void {
		parent::setUp();

		foreach ( array( 'a', 'b' ) as $mailbox ) {
			$settings = $this->make_settings( $mailbox );

			$capabilities = new Mailbox_Capabilities( $settings );
			$filter       = $capabilities->map_meta_cap( ... );
			add_filter( 'map_meta_cap', $filter, 10, 4 );
			$this->filters[] = $filter;

			( new BH_Email_CPT( $settings, $this->logger ) )->register_cpt();
			( new BH_Email_CPT( $settings, $this->logger ) )->register_post_statuses();
			( new BH_Email_Account_CPT( $settings, $this->logger ) )->register_cpt();

			$this->mailboxes[ $mailbox ] = $capabilities;
		}
	}

	public function tearDown(): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( 'map_meta_cap', $filter, 10 );
		}
		remove_all_filters( Mailbox_Capabilities::REQUIRED_CAPABILITY_FILTER );
		foreach ( array( 'a', 'b' ) as $mailbox ) {
			unregister_post_type( "mailbox_{$mailbox}_emails" );
			unregister_post_type( "mailbox_{$mailbox}_accounts" );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	protected function make_settings( string $mailbox ): BH_WP_Mailboxes_Settings_Interface {
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_plugin_slug' )->andReturn( "mailbox-{$mailbox}" );
		$settings->allows( 'get_emails_cpt_friendly_name' )->andReturn( "Mailbox {$mailbox} Emails" );
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( "mailbox_{$mailbox}_emails" );
		$settings->allows( 'get_email_accounts_cpt_friendly_name' )->andReturn( "Mailbox {$mailbox} Accounts" );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( "mailbox_{$mailbox}_accounts" );
		$settings->allows( 'get_rest_namespace' )->andReturn( null );
		return $settings;
	}

	protected function make_email( string $mailbox ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => "mailbox_{$mailbox}_emails",
				'post_status' => 'bh_email_new',
				'post_title'  => "Email in mailbox {$mailbox}",
			)
		);
	}

	protected function login_as( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Grant mailbox A's capabilities to subscribers, through the consumer-facing filter.
	 */
	protected function grant_mailbox_a_to_subscribers(): void {
		add_filter(
			Mailbox_Capabilities::REQUIRED_CAPABILITY_FILTER,
			fn( string $required, string $capability, string $post_type ): string => in_array( $post_type, array( 'mailbox_a_emails', 'mailbox_a_accounts' ), true ) ? 'read' : $required,
			10,
			3
		);
	}

	/**
	 * Registering with `capability_type` = the post type mints per-mailbox capability names.
	 *
	 * @coversNothing
	 */
	public function test_cpts_have_mailbox_scoped_capability_names(): void {
		$emails   = get_post_type_object( 'mailbox_a_emails' );
		$accounts = get_post_type_object( 'mailbox_a_accounts' );

		$this->assertSame( 'edit_mailbox_a_emails', $emails->cap->edit_post );
		$this->assertSame( 'edit_mailbox_a_emailss', $emails->cap->edit_posts );
		$this->assertSame( 'edit_others_mailbox_a_emailss', $emails->cap->edit_others_posts );
		$this->assertSame( 'delete_mailbox_a_emails', $emails->cap->delete_post );
		$this->assertSame( 'read_mailbox_a_emails', $emails->cap->read_post );
		$this->assertSame( 'edit_mailbox_a_accounts', $accounts->cap->edit_post );
		$this->assertTrue( $emails->map_meta_cap );
		$this->assertFalse( $emails->show_in_rest, 'Emails are never exposed through the core posts controller.' );
		$this->assertFalse( $accounts->show_in_rest );
	}

	/**
	 * Administrators have everything by default (the base capability is manage_options).
	 *
	 * @covers ::current_user_can_read_email
	 * @covers ::current_user_can_edit_email
	 * @covers ::current_user_can_delete_email
	 * @covers ::current_user_can_list_emails
	 * @covers ::current_user_can_create_email
	 * @covers ::current_user_can_manage_email_accounts
	 * @covers ::map_meta_cap
	 */
	public function test_administrator_can_everything_by_default(): void {
		$this->login_as( 'administrator' );
		$email = $this->make_email( 'a' );
		$sut   = $this->mailboxes['a'];

		$this->assertTrue( $sut->current_user_can_read_email( $email ) );
		$this->assertTrue( $sut->current_user_can_edit_email( $email ) );
		$this->assertTrue( $sut->current_user_can_delete_email( $email ) );
		$this->assertTrue( $sut->current_user_can_list_emails() );
		$this->assertTrue( $sut->current_user_can_create_email() );
		$this->assertTrue( $sut->current_user_can_manage_email_accounts() );

		// The raw WordPress checks the admin screens rely on resolve the same way.
		$this->assertTrue( current_user_can( 'edit_post', $email ) );
		$this->assertTrue( current_user_can( 'edit_mailbox_a_emailss' ) ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- The CPT-derived capability under test.
	}

	/**
	 * Nobody below the base capability has anything, whatever their role's post capabilities.
	 *
	 * @covers ::map_meta_cap
	 */
	public function test_editor_and_subscriber_have_nothing_by_default(): void {
		$email = $this->make_email( 'a' );
		$sut   = $this->mailboxes['a'];

		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$this->login_as( $role );
			$this->assertFalse( $sut->current_user_can_read_email( $email ), $role );
			$this->assertFalse( $sut->current_user_can_edit_email( $email ), $role );
			$this->assertFalse( $sut->current_user_can_delete_email( $email ), $role );
			$this->assertFalse( $sut->current_user_can_list_emails(), $role );
			$this->assertFalse( $sut->current_user_can_create_email(), $role );
			$this->assertFalse( $sut->current_user_can_manage_email_accounts(), $role );
			$this->assertFalse( current_user_can( 'read_post', $email ), "{$role}: a non-public status must not fall through to plain read." );
			$this->assertFalse( current_user_can( 'edit_post', $email ), $role );
		}
	}

	/**
	 * The filter lowers the requirement for one mailbox — the extension point consumers rely on.
	 *
	 * @covers ::map_meta_cap
	 */
	public function test_filter_can_grant_a_subscriber(): void {
		$this->grant_mailbox_a_to_subscribers();
		$this->login_as( 'subscriber' );
		$email = $this->make_email( 'a' );
		$sut   = $this->mailboxes['a'];

		$this->assertTrue( $sut->current_user_can_read_email( $email ) );
		$this->assertTrue( $sut->current_user_can_edit_email( $email ) );
		$this->assertTrue( $sut->current_user_can_delete_email( $email ) );
		$this->assertTrue( $sut->current_user_can_list_emails() );
		$this->assertTrue( $sut->current_user_can_create_email() );
		$this->assertTrue( $sut->current_user_can_manage_email_accounts() );
	}

	/**
	 * A grant on mailbox A gives nothing on mailbox B for the same user: scoping is real.
	 *
	 * @covers ::map_meta_cap
	 */
	public function test_grant_is_scoped_to_one_mailbox(): void {
		$this->grant_mailbox_a_to_subscribers();
		$this->login_as( 'subscriber' );
		$email_a = $this->make_email( 'a' );
		$email_b = $this->make_email( 'b' );

		$this->assertTrue( $this->mailboxes['a']->current_user_can_edit_email( $email_a ) );
		$this->assertTrue( $this->mailboxes['a']->current_user_can_manage_email_accounts() );

		$this->assertFalse( $this->mailboxes['b']->current_user_can_edit_email( $email_b ) );
		$this->assertFalse( $this->mailboxes['b']->current_user_can_read_email( $email_b ) );
		$this->assertFalse( $this->mailboxes['b']->current_user_can_list_emails() );
		$this->assertFalse( $this->mailboxes['b']->current_user_can_manage_email_accounts() );
		$this->assertFalse( current_user_can( 'edit_post', $email_b ) );
	}

	/**
	 * A per-post check with a post of another type (another mailbox, or any other CPT) fails, even for
	 * an administrator: a valid-looking id is not enough.
	 *
	 * @covers ::current_user_can_edit_email
	 * @covers ::current_user_can_read_email
	 * @covers ::current_user_can_delete_email
	 */
	public function test_per_post_checks_fail_for_other_post_types(): void {
		$this->login_as( 'administrator' );
		$email_b = $this->make_email( 'b' );
		$page    = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$sut     = $this->mailboxes['a'];

		foreach ( array( $email_b, $page, 999999 ) as $post_id ) {
			$this->assertFalse( $sut->current_user_can_read_email( $post_id ), (string) $post_id );
			$this->assertFalse( $sut->current_user_can_edit_email( $post_id ), (string) $post_id );
			$this->assertFalse( $sut->current_user_can_delete_email( $post_id ), (string) $post_id );
		}
	}

	/**
	 * The filter receives the capability, the post type (which mailbox) and the post id.
	 *
	 * @covers ::map_meta_cap
	 */
	public function test_filter_receives_capability_post_type_and_post_id(): void {
		$this->login_as( 'administrator' );
		$email = $this->make_email( 'a' );

		$seen = array();
		add_filter(
			Mailbox_Capabilities::REQUIRED_CAPABILITY_FILTER,
			function ( string $required, string $capability, string $post_type, ?int $post_id ) use ( &$seen ): string {
				$seen[] = array( $capability, $post_type, $post_id );
				return $required;
			},
			10,
			4
		);

		$this->mailboxes['a']->current_user_can_edit_email( $email );
		$this->mailboxes['a']->current_user_can_manage_email_accounts();

		$this->assertContains( array( 'edit_post', 'mailbox_a_emails', $email ), $seen );
		$this->assertContains( array( 'manage_mailbox_a_accounts', 'mailbox_a_accounts', null ), $seen );
	}

	/**
	 * A filter returning an empty value denies rather than grants.
	 *
	 * @covers ::map_meta_cap
	 */
	public function test_empty_filtered_capability_denies(): void {
		add_filter( Mailbox_Capabilities::REQUIRED_CAPABILITY_FILTER, '__return_empty_string' );
		$this->login_as( 'administrator' );

		$this->assertFalse( $this->mailboxes['a']->current_user_can_list_emails() );
	}

	/**
	 * Capabilities of other post types pass through the mapping untouched.
	 *
	 * @covers ::map_meta_cap
	 */
	public function test_unrelated_capabilities_are_untouched(): void {
		$this->login_as( 'editor' );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertTrue( current_user_can( 'edit_post', $page ) );
		$this->assertTrue( current_user_can( 'edit_pages' ) );
		$this->assertFalse( current_user_can( 'manage_options' ) );
	}
}
