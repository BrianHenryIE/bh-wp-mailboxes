<?php
/**
 * WPUnit tests for the development plugin's Demo mailbox: registering it creates its accounts and seeds the
 * example emails.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_Thread_Taxonomy;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections\Demo_Delivered_Connection;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections\Demo_Fetching_Connection;
use WP_Post;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Demo_Mailbox
 */
class Demo_Mailbox_WPUnit_Test extends WPUnit_Testcase {

	private const EMAILS_CPT = 'demo_email';

	private const ACCOUNTS_CPT = 'demo_accounts';

	/**
	 * Register the Demo mailbox and run `init`, which registers its post types and seeds it.
	 */
	protected function register_and_init(): Demo_Mailbox {
		// Only the Demo mailbox's own `init` callbacks should run.
		remove_all_actions( 'init' );

		$demo_mailbox = new Demo_Mailbox( $this->logger );
		$demo_mailbox->register();

		do_action( 'init' );

		return $demo_mailbox;
	}

	protected function tearDown(): void {
		// Saved attachments are files in the uploads directory, which the database rollback does not remove.
		foreach ( $this->get_emails() as $email ) {
			foreach ( $this->get_attachment_ids( $email->ID ) as $attachment_id ) {
				$file = get_attached_file( $attachment_id );
				if ( is_string( $file ) && file_exists( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}

		unregister_taxonomy( BH_Email_Thread_Taxonomy::taxonomy_name_for_post_type( self::EMAILS_CPT ) );

		parent::tearDown();
	}

	/**
	 * The attachment post ids saved for an email.
	 *
	 * @param int $email_post_id The email's post id.
	 *
	 * @return int[]
	 */
	protected function get_attachment_ids( int $email_post_id ): array {
		$json = get_post_meta( $email_post_id, 'attachment_ids', true );
		$ids  = json_decode( is_string( $json ) ? $json : '' );

		return is_array( $ids ) ? array_values( array_filter( $ids, 'is_int' ) ) : array();
	}

	/**
	 * The Demo mailbox's emails, oldest post first.
	 *
	 * @return WP_Post[]
	 */
	protected function get_emails(): array {
		if ( ! post_type_exists( self::EMAILS_CPT ) ) {
			return array();
		}

		/** @var WP_Post[] $posts */
		$posts = get_posts(
			array(
				'post_type'   => self::EMAILS_CPT,
				'post_status' => 'any',
				'numberposts' => -1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);
		return $posts;
	}

	/**
	 * The mailbox's account posts, keyed by email address.
	 *
	 * @return array<string, WP_Post>
	 */
	protected function get_accounts(): array {
		$accounts = array();
		/** @var WP_Post $post */
		foreach ( get_posts(
			array(
				'post_type'   => self::ACCOUNTS_CPT,
				'post_status' => 'any',
				'numberposts' => -1,
			)
		) as $post ) {
			$email_address = get_post_meta( $post->ID, 'email_address', true );

			$accounts[ is_string( $email_address ) ? $email_address : '' ] = $post;
		}
		return $accounts;
	}

	/**
	 * The settings use the Demo names and keep REST off, so the site still advertises only the Fixtures and E2E
	 * ingress endpoints.
	 *
	 * @covers ::make_settings
	 */
	public function test_make_settings(): void {
		$settings = Demo_Mailbox::make_settings();

		$this->assertSame( self::EMAILS_CPT, $settings->get_emails_cpt_underscored_20() );
		$this->assertSame( self::ACCOUNTS_CPT, $settings->get_email_accounts_cpt_underscored_20() );
		$this->assertNull( $settings->get_rest_namespace() );
	}

	/**
	 * Registering creates a fetching account and a receive-only account.
	 *
	 * @covers ::register
	 * @covers ::seed
	 */
	public function test_creates_a_fetching_and_a_receive_only_account(): void {
		$this->register_and_init();

		$accounts = $this->get_accounts();

		$this->assertCount( 2, $accounts );
		$this->assertArrayHasKey( Demo_Mailbox::FETCHING_ACCOUNT_EMAIL_ADDRESS, $accounts );
		$this->assertArrayHasKey( Demo_Mailbox::DELIVERED_ACCOUNT_EMAIL_ADDRESS, $accounts );
		$this->assertSame( Demo_Fetching_Connection::class, get_post_meta( $accounts[ Demo_Mailbox::FETCHING_ACCOUNT_EMAIL_ADDRESS ]->ID, 'connection_type_class', true ) );
		$this->assertSame( Demo_Delivered_Connection::class, get_post_meta( $accounts[ Demo_Mailbox::DELIVERED_ACCOUNT_EMAIL_ADDRESS ]->ID, 'connection_type_class', true ) );
	}

	/**
	 * The fetching account's emails arrive through a real fetch, and the receive-only account's are saved as
	 * deliveries; each is filed under its own account.
	 *
	 * @covers ::seed
	 * @covers ::seed_fetched_emails
	 * @covers ::seed_delivered_emails
	 */
	public function test_seeds_each_account_with_its_example_emails(): void {
		$this->register_and_init();

		$accounts = $this->get_accounts();

		$subjects_by_account = array();
		foreach ( $this->get_emails() as $email ) {
			$subjects_by_account[ $email->post_parent ][] = $email->post_title;
		}
		foreach ( $subjects_by_account as &$subjects ) {
			sort( $subjects );
		}
		unset( $subjects );

		$this->assertSame(
			array(
				'Do you deliver to Galway?',
				'Order #1042 arrived damaged',
				'Packing list for shipment 7781',
				'Re: Order #1042 arrived damaged',
				'Your invoice INV-2026-0412 from Acme Supplies',
			),
			$subjects_by_account[ $accounts[ Demo_Mailbox::FETCHING_ACCOUNT_EMAIL_ADDRESS ]->ID ] ?? null
		);
		$this->assertSame(
			array(
				'New order #1043 received',
				'Re: Order #1042 arrived damaged',
			),
			$subjects_by_account[ $accounts[ Demo_Mailbox::DELIVERED_ACCOUNT_EMAIL_ADDRESS ]->ID ] ?? null
		);

		// Seeding by fetching updates the account's fetch record, as "Check now" would.
		$this->assertSame( '5', get_post_meta( $accounts[ Demo_Mailbox::FETCHING_ACCOUNT_EMAIL_ADDRESS ]->ID, 'total_emails_saved_count', true ) );
	}

	/**
	 * The damaged-order conversation is one thread across both accounts, though the shop's replies were never
	 * stored; the other emails are each alone.
	 *
	 * @covers ::seed
	 * @covers ::make_email_repository
	 */
	public function test_conversation_is_one_thread_across_both_accounts(): void {
		$this->register_and_init();

		$taxonomy = BH_Email_Thread_Taxonomy::taxonomy_name_for_post_type( self::EMAILS_CPT );

		$conversation_terms = array();
		$conversation_posts = array();
		foreach ( $this->get_emails() as $email ) {
			$term_ids = wp_get_object_terms( $email->ID, $taxonomy, array( 'fields' => 'ids' ) );
			$this->assertIsArray( $term_ids );
			$this->assertCount( 1, $term_ids, "Every demo email is threaded: {$email->post_title}" );

			if ( str_contains( $email->post_title, 'Order #1042' ) ) {
				$conversation_terms[] = $term_ids[0];
				$conversation_posts[] = $email;
			} else {
				$thread_post_ids = get_objects_in_term( (int) $term_ids[0], $taxonomy );
				$this->assertIsArray( $thread_post_ids );
				$this->assertCount( 1, $thread_post_ids, "Alone in its thread: {$email->post_title}" );
			}
		}

		$this->assertCount( 3, $conversation_posts );
		$this->assertCount( 1, array_unique( $conversation_terms ), 'The three conversation emails share one thread.' );
		$this->assertCount( 2, array_unique( array_map( fn( WP_Post $post ): int => $post->post_parent, $conversation_posts ) ), 'The thread spans both accounts.' );
	}

	/**
	 * The attachment email's CSV is saved.
	 *
	 * @covers ::seed_fetched_emails
	 */
	public function test_attachment_is_saved(): void {
		$this->register_and_init();

		$packing_list = current( array_filter( $this->get_emails(), fn( WP_Post $post ): bool => 'Packing list for shipment 7781' === $post->post_title ) );
		$this->assertInstanceOf( WP_Post::class, $packing_list );

		$attachment_ids = $this->get_attachment_ids( $packing_list->ID );
		$this->assertCount( 1, $attachment_ids );

		$file = get_attached_file( $attachment_ids[0] );
		$this->assertIsString( $file );
		$this->assertSame( 'packing-list-7781.csv', basename( $file ) );
	}

	/**
	 * Seeding only acts for a missing account: running it again adds nothing, and a deleted account is re-created
	 * with its emails.
	 *
	 * @covers ::seed
	 */
	public function test_seeding_again_only_restores_missing_accounts(): void {
		$demo_mailbox = $this->register_and_init();
		$this->assertCount( 7, $this->get_emails() );

		$demo_mailbox->seed();
		$this->assertCount( 2, $this->get_accounts(), 'No duplicate accounts.' );
		$this->assertCount( 7, $this->get_emails(), 'No duplicate emails.' );

		$delivered_account = $this->get_accounts()[ Demo_Mailbox::DELIVERED_ACCOUNT_EMAIL_ADDRESS ];
		foreach ( $this->get_emails() as $email ) {
			if ( $email->post_parent === $delivered_account->ID ) {
				wp_delete_post( $email->ID, true );
			}
		}
		wp_delete_post( $delivered_account->ID, true );
		$this->assertCount( 5, $this->get_emails() );

		$demo_mailbox->seed();

		$this->assertArrayHasKey( Demo_Mailbox::DELIVERED_ACCOUNT_EMAIL_ADDRESS, $this->get_accounts() );
		$this->assertCount( 7, $this->get_emails() );
	}
}
