<?php
/**
 * Threading emails by their Message-ID / In-Reply-To / References headers, through the repository's save path.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_Thread_Taxonomy;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Mockery;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\Email_Thread_Linker
 */
class Email_Thread_Linker_WPUnit_Test extends WPUnit_Testcase {

	private const POST_TYPE = 'thread_test_email';

	/** @var BH_WP_Mailboxes_Settings_Interface&Mockery\MockInterface */
	protected $settings;

	/** @var BH_Email_Thread_Taxonomy The thread taxonomy under test, registered per test. */
	protected BH_Email_Thread_Taxonomy $taxonomy;

	/** @var BH_Email_Account The account emails are filed under. */
	protected BH_Email_Account $account;

	protected function setUp(): void {
		parent::setUp();

		$this->settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$this->settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( self::POST_TYPE );
		$this->settings->allows( 'get_emails_cpt_friendly_name' )->andReturn( 'Thread Test Emails' );
		$this->settings->allows( 'get_rest_namespace' )->andReturn( null );

		$cpt = new BH_Email_CPT( $this->settings, $this->logger );
		$cpt->register_cpt();
		$cpt->register_post_statuses();

		// As wired in BH_WP_Mailboxes_Hooks: emptied threads clean themselves up.
		$this->taxonomy = new BH_Email_Thread_Taxonomy( self::POST_TYPE, $this->logger );
		$this->taxonomy->register_taxonomy();
		add_action( 'deleted_term_relationships', $this->taxonomy->delete_empty_terms( ... ), 10, 3 );

		$this->account = BH_Email_Account_Fixture::make(
			post_id: 456,
			post_type: 'thread_test_account',
			connection_type_class: 'SomeConnection',
			email_address: 'inbox@example.com',
			display_name: 'Inbox',
		);
	}

	protected function tearDown(): void {
		remove_all_actions( 'deleted_term_relationships' );
		unregister_taxonomy( $this->taxonomy->get_taxonomy_name() );
		// Otherwise the next setUp's register_cpt() logs "already registered".
		unregister_post_type( self::POST_TYPE );
		parent::tearDown();
	}

	protected function make_repository( ?Email_Thread_Linker $linker = null ): Email_WP_Post_Repository {
		return new Email_WP_Post_Repository( self::POST_TYPE, new BH_Email_Factory( $this->logger ), $this->logger, $linker );
	}

	/**
	 * A minimal RFC 5322 message with the given threading headers.
	 *
	 * @param string   $message_id  The Message-ID (without angle brackets).
	 * @param string   $subject     The Subject header.
	 * @param ?string  $in_reply_to The In-Reply-To id (without angle brackets), or null for none.
	 * @param string[] $references  The References ids (without angle brackets), oldest first.
	 * @param string   $date        The RFC 2822 Date header.
	 */
	protected function eml( string $message_id, string $subject, ?string $in_reply_to = null, array $references = array(), string $date = 'Mon, 01 Sep 2025 10:00:00 +0000' ): string {
		$headers  = "From: Customer <customer@example.org>\r\n";
		$headers .= "To: inbox@example.com\r\n";
		$headers .= "Subject: {$subject}\r\n";
		$headers .= "Date: {$date}\r\n";
		$headers .= "Message-ID: <{$message_id}>\r\n";
		if ( null !== $in_reply_to ) {
			$headers .= "In-Reply-To: <{$in_reply_to}>\r\n";
		}
		if ( array() !== $references ) {
			$headers .= 'References: ' . implode( ' ', array_map( fn( string $id ): string => "<{$id}>", $references ) ) . "\r\n";
		}
		$headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n";
		return $headers . "Body of {$subject}\r\n";
	}

	protected function save( Email_WP_Post_Repository $repository, string $raw, ?BH_Email_Account $account = null ): BH_Email {
		/** @var IMessage $message */
		$message = ( new MailMimeParser() )->parse( $raw, true );
		return $repository->save_new(
			new Fetched_Email( $message, new Remote_Email_Coordinates( message_id: $message->getMessageId() ?? '' ) ),
			$this->settings,
			$account ?? $this->account,
		);
	}

	/**
	 * The ids of every post in the email's thread, ascending.
	 *
	 * @param BH_Email $email The email whose thread to inspect.
	 *
	 * @return int[]
	 */
	protected function thread_post_ids( BH_Email $email ): array {
		$term_ids = wp_get_object_terms( $email->post_id, $this->taxonomy->get_taxonomy_name(), array( 'fields' => 'ids' ) );
		$this->assertIsArray( $term_ids );
		$this->assertCount( 1, $term_ids, 'Every email should belong to exactly one thread.' );
		$posts = get_objects_in_term( (int) $term_ids[0], $this->taxonomy->get_taxonomy_name() );
		$this->assertIsArray( $posts );
		sort( $posts );
		return array_map( 'intval', $posts );
	}

	/**
	 * @covers ::link
	 */
	public function test_single_email_gets_its_own_thread(): void {
		$repository = $this->make_repository();

		$email = $this->save( $repository, $this->eml( 'root@example.org', 'Order 123' ) );

		$this->assertNotNull( $email->thread_term_id );
		$this->assertSame( array( $email->post_id ), $this->thread_post_ids( $email ) );

		$thread = $repository->find_thread( $email );
		$this->assertSame( $email->thread_term_id, $thread->term_id );
		$this->assertCount( 1, $thread->emails );
		$this->assertFalse( $thread->has_related_emails() );
		$this->assertSame( sha1( 'root@example.org' ), $thread->slug );
	}

	/**
	 * @covers ::link
	 */
	public function test_reply_joins_the_root_thread(): void {
		$repository = $this->make_repository();

		$root  = $this->save( $repository, $this->eml( 'root@example.org', 'Order 123' ) );
		$reply = $this->save( $repository, $this->eml( 'reply-1@example.org', 'Re: Order 123', 'root@example.org', array( 'root@example.org' ), 'Mon, 01 Sep 2025 11:00:00 +0000' ) );

		$this->assertSame( $root->thread_term_id, $reply->thread_term_id );
		$this->assertSame( array( $root->post_id, $reply->post_id ), $this->thread_post_ids( $reply ) );
	}

	/**
	 * The parent arriving after the child (fetch limits, several accounts) must still join one thread.
	 *
	 * @covers ::link
	 */
	public function test_root_arriving_after_reply_joins_the_reply_thread(): void {
		$repository = $this->make_repository();

		$reply = $this->save( $repository, $this->eml( 'reply-1@example.org', 'Re: Order 123', 'root@example.org', array( 'root@example.org' ) ) );
		$root  = $this->save( $repository, $this->eml( 'root@example.org', 'Order 123' ) );

		$this->assertSame( $reply->thread_term_id, $root->thread_term_id );
		$this->assertSame( array( $reply->post_id, $root->post_id ), $this->thread_post_ids( $root ) );
		$this->assertFalse( $this->logger->hasErrorRecords() );
	}

	/**
	 * Inbox-only: two customer replies to our (never stored) outgoing mail share only the References chain.
	 *
	 * @covers ::link
	 */
	public function test_siblings_sharing_an_unstored_parent_share_a_thread(): void {
		$repository = $this->make_repository();

		$first  = $this->save( $repository, $this->eml( 'customer-2@example.org', 'Re: Order 123', 'our-reply@shop.example.com', array( 'customer-1@example.org', 'our-reply@shop.example.com' ) ) );
		$second = $this->save( $repository, $this->eml( 'customer-3@example.org', 'Re: Order 123', 'our-reply@shop.example.com', array( 'customer-1@example.org', 'our-reply@shop.example.com' ) ) );

		$this->assertSame( $first->thread_term_id, $second->thread_term_id );
	}

	/**
	 * An email that references two existing threads merges them into the older one; the emptied term is deleted
	 * and the moved emails get a notice-level log entry.
	 *
	 * @covers ::link
	 * @covers \BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_Thread_Taxonomy::delete_empty_terms
	 */
	public function test_bridging_email_merges_threads(): void {
		$repository = $this->make_repository();

		$a = $this->save( $repository, $this->eml( 'a1@example.org', 'Thread A' ) );
		$b = $this->save( $repository, $this->eml( 'b1@example.org', 'Thread B', null, array( 'shared-ancestor@example.org' ) ) );
		$this->assertNotSame( $a->thread_term_id, $b->thread_term_id );
		$term_a = (int) $a->thread_term_id;
		$term_b = (int) $b->thread_term_id;

		$bridge = $this->save( $repository, $this->eml( 'c1@example.org', 'Re: Thread A', 'a1@example.org', array( 'shared-ancestor@example.org', 'a1@example.org' ) ) );

		$this->assertSame( min( $term_a, $term_b ), $bridge->thread_term_id );
		$this->assertSame( array( $a->post_id, $b->post_id, $bridge->post_id ), $this->thread_post_ids( $bridge ) );

		$this->assertEmpty( term_exists( max( $term_a, $term_b ), $this->taxonomy->get_taxonomy_name() ), 'The merged-away thread term should be deleted.' );

		$moved_post_id = $term_a < $term_b ? $b->post_id : $a->post_id;
		$notes         = get_comments(
			array(
				'post_id' => $moved_post_id,
				'type'    => 'bh_email_log',
			)
		);
		$merge_notes   = array_filter( $notes, fn( $note ) => str_contains( (string) $note->comment_content, 'Merged into the thread' ) );
		$this->assertCount( 1, $merge_notes );
		$this->assertSame( 'notice', get_comment_meta( (int) reset( $merge_notes )->comment_ID, 'bh_email_log_level', true ) );

		$bridge_notes = get_comments(
			array(
				'post_id' => $bridge->post_id,
				'type'    => 'bh_email_log',
			)
		);
		$this->assertNotEmpty( array_filter( $bridge_notes, fn( $note ) => str_contains( (string) $note->comment_content, 'Bridged 1 email' ) ) );
	}

	/**
	 * An email stored before threading existed (no term) is pulled into the thread when a reply to it arrives.
	 *
	 * @covers ::link
	 */
	public function test_reply_threads_a_pre_existing_unthreaded_email(): void {
		$repository = $this->make_repository();

		$legacy = $this->save( $repository, $this->eml( 'legacy@example.org', 'Old email' ) );
		wp_delete_object_term_relationships( $legacy->post_id, $this->taxonomy->get_taxonomy_name() );
		$this->assertNull( $repository->find_by_post_id( $legacy->post_id )->thread_term_id );

		$reply = $this->save( $repository, $this->eml( 'reply@example.org', 'Re: Old email', 'legacy@example.org', array( 'legacy@example.org' ) ) );

		$this->assertSame( array( $legacy->post_id, $reply->post_id ), $this->thread_post_ids( $reply ) );
	}

	/**
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository::find_thread
	 */
	public function test_find_thread_orders_oldest_first_and_excludes_trash(): void {
		$repository = $this->make_repository();

		$reply  = $this->save( $repository, $this->eml( 'reply@example.org', 'Re: Order', 'root@example.org', array( 'root@example.org' ), 'Tue, 02 Sep 2025 09:00:00 +0000' ) );
		$root   = $this->save( $repository, $this->eml( 'root@example.org', 'Order', null, array(), 'Mon, 01 Sep 2025 09:00:00 +0000' ) );
		$second = $this->save( $repository, $this->eml( 'reply-2@example.org', 'Re: Order', 'reply@example.org', array( 'root@example.org', 'reply@example.org' ), 'Wed, 03 Sep 2025 09:00:00 +0000' ) );

		$thread = $repository->find_thread( $reply );
		$this->assertTrue( $thread->has_related_emails() );
		$this->assertSame(
			array( $root->post_id, $reply->post_id, $second->post_id ),
			array_map( fn( BH_Email $email ): int => $email->post_id, $thread->emails )
		);

		wp_trash_post( $second->post_id );
		$thread = $repository->find_thread( $reply );
		$this->assertSame(
			array( $root->post_id, $reply->post_id ),
			array_map( fn( BH_Email $email ): int => $email->post_id, $thread->emails )
		);
	}

	/**
	 * @covers \BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_Thread_Taxonomy::delete_empty_terms
	 */
	public function test_deleting_the_last_email_removes_the_thread_term(): void {
		$repository = $this->make_repository();

		$email   = $this->save( $repository, $this->eml( 'solo@example.org', 'Solo' ) );
		$term_id = (int) $email->thread_term_id;
		$this->assertNotEmpty( term_exists( $term_id, $this->taxonomy->get_taxonomy_name() ) );

		wp_delete_post( $email->post_id, true );

		$this->assertNotInstanceOf( \WP_Term::class, get_term( $term_id, $this->taxonomy->get_taxonomy_name() ) );
	}

	/**
	 * With the account repository, a reply filed under a second account finds the root saved under the first.
	 *
	 * @covers ::link
	 * @covers ::get_account_email_addresses
	 */
	public function test_threads_span_accounts_when_account_repository_is_available(): void {
		$other_account = $this->make_other_account();

		$account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$account_repository->allows( 'get_all' )->andReturn( array( $this->account, $other_account ) );

		$linker     = new Email_Thread_Linker( self::POST_TYPE, $this->taxonomy, $account_repository, $this->logger );
		$repository = $this->make_repository( $linker );

		[ $parent, $truncated_reply ] = $this->save_truncated_reply_in_other_account( $repository, $other_account );

		$this->assertSame( $parent->thread_term_id, $truncated_reply->thread_term_id );
	}

	/**
	 * Without the account repository, a parent is only found by its Message-ID within the saving account.
	 *
	 * The reply's References was truncated to its direct parent, so the parent's Message-ID is the only link.
	 *
	 * @covers ::link
	 */
	public function test_without_account_repository_message_id_match_is_scoped_to_the_account(): void {
		$repository = $this->make_repository();

		[ $parent, $truncated_reply ] = $this->save_truncated_reply_in_other_account( $repository, $this->make_other_account() );

		$this->assertNotSame( $parent->thread_term_id, $truncated_reply->thread_term_id );
	}

	/**
	 * A second account in the mailbox.
	 */
	protected function make_other_account(): BH_Email_Account {
		return BH_Email_Account_Fixture::make(
			post_id: 457,
			post_type: 'thread_test_account',
			connection_type_class: 'SomeConnection',
			email_address: 'sales@example.com',
			display_name: 'Sales',
		);
	}

	/**
	 * Save a parent (part of a longer thread) under the default account, then a reply to it under another
	 * account whose References lists only the parent, so the parent's Message-ID is the only shared id.
	 *
	 * @param Email_WP_Post_Repository $repository    The repository to save through.
	 * @param BH_Email_Account         $other_account The account the reply is filed under.
	 *
	 * @return array{0: BH_Email, 1: BH_Email}
	 */
	protected function save_truncated_reply_in_other_account( Email_WP_Post_Repository $repository, BH_Email_Account $other_account ): array {
		$parent = $this->save( $repository, $this->eml( 'parent@example.org', 'Re: Order 123', 'root@example.org', array( 'root@example.org' ) ), $this->account );
		$reply  = $this->save( $repository, $this->eml( 'reply@example.org', 'Re: Order 123', 'parent@example.org', array( 'parent@example.org' ) ), $other_account );

		return array( $parent, $reply );
	}

	/**
	 * A reply that starts its own thread reuses the term already created for the same root Message-ID,
	 * whatever its subject.
	 *
	 * Regression: `wp_insert_term()` only reports an existing term when the name also matches, so a reply
	 * with a different subject ("Re: …") silently got a second term with a `-2` slug.
	 *
	 * @covers ::link
	 * @covers ::create_term
	 */
	public function test_new_thread_reuses_the_term_for_the_same_root_whatever_the_subject(): void {
		// Without the account repository the reply, filed under another account, cannot find the root by its
		// Message-ID; the shared root id in the slug is what joins them.
		$repository = $this->make_repository();

		$root  = $this->save( $repository, $this->eml( 'root@example.org', 'Order 123' ), $this->account );
		$reply = $this->save( $repository, $this->eml( 'reply@example.org', 'Re: Order 123', 'root@example.org' ), $this->make_other_account() );

		$this->assertSame( $root->thread_term_id, $reply->thread_term_id );

		$thread_terms = get_terms(
			array(
				'taxonomy'   => $this->taxonomy->get_taxonomy_name(),
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$this->assertIsArray( $thread_terms );
		$this->assertCount( 1, $thread_terms, 'No second, `-2` term was created.' );
	}

	/**
	 * Store an email post the way the repository does (MIME aside), without linking it, so link() can be
	 * called on it directly.
	 *
	 * @param string   $message_id  The Message-ID (without angle brackets).
	 * @param string[] $references  The References ids (without angle brackets).
	 *
	 * @return array{0: int, 1: IMessage}
	 */
	protected function insert_unlinked_email( string $message_id, array $references = array() ): array {
		/** @var IMessage $message */
		$message = ( new MailMimeParser() )->parse( $this->eml( $message_id, "Subject {$message_id}", $references[ count( $references ) - 1 ] ?? null, $references ), true );

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'bh_email_new',
				'post_title'  => "Subject {$message_id}",
				'post_name'   => Email_WP_Post_Repository::message_id_slug( $this->account->get_account_email_address(), $message_id ),
			)
		);
		$this->assertGreaterThan( 0, $post_id );
		foreach ( $references as $reference ) {
			add_post_meta( $post_id, Email_Thread_Linker::META_KEY_REFERENCES, $reference );
		}

		return array( $post_id, $message );
	}

	/**
	 * Linking reports whether it started a new thread, and which emails it moved when merging.
	 *
	 * @covers ::link
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Model\Email_Thread_Link_Result::__construct
	 */
	public function test_link_reports_new_threads_and_merged_emails(): void {
		$linker = new Email_Thread_Linker( self::POST_TYPE, $this->taxonomy, null, $this->logger );

		[ $a_post_id, $a_message ] = $this->insert_unlinked_email( 'a@example.org' );
		$a_result                  = $linker->link( $a_post_id, 'a@example.org', $a_message, $this->account );
		$this->assertTrue( $a_result->is_new_thread );
		$this->assertSame( array(), $a_result->merged_post_ids );

		[ $b_post_id, $b_message ] = $this->insert_unlinked_email( 'b@example.org' );
		$b_result                  = $linker->link( $b_post_id, 'b@example.org', $b_message, $this->account );
		$this->assertTrue( $b_result->is_new_thread );
		$this->assertNotSame( $a_result->term_id, $b_result->term_id );

		// References both: bridges the two threads into the older one.
		[ $c_post_id, $c_message ] = $this->insert_unlinked_email( 'c@example.org', array( 'a@example.org', 'b@example.org' ) );
		$c_result                  = $linker->link( $c_post_id, 'c@example.org', $c_message, $this->account );
		$this->assertFalse( $c_result->is_new_thread );
		$this->assertSame( $a_result->term_id, $c_result->term_id );
		$this->assertSame( array( $b_post_id ), $c_result->merged_post_ids );
	}

	/**
	 * When the mailbox's accounts cannot be listed, linking still works for the saving account, with a warning.
	 *
	 * @covers ::get_account_email_addresses
	 */
	public function test_account_listing_failure_falls_back_to_the_saving_account(): void {
		$account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );
		$account_repository->allows( 'get_all' )->andThrow( new \RuntimeException( 'Database unavailable.' ) );

		$linker     = new Email_Thread_Linker( self::POST_TYPE, $this->taxonomy, $account_repository, $this->logger );
		$repository = $this->make_repository( $linker );

		$root  = $this->save( $repository, $this->eml( 'root@example.org', 'Order 123' ) );
		$reply = $this->save( $repository, $this->eml( 'reply@example.org', 'Re: Order 123', 'root@example.org', array( 'root@example.org' ) ) );

		$this->assertSame( $root->thread_term_id, $reply->thread_term_id );
		$this->assertTrue( $this->logger->hasWarningThatContains( 'Failed to list mailbox accounts for thread linking' ) );
	}

	/**
	 * A failure while threading never stops the email itself being saved.
	 *
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository::save_new
	 */
	public function test_email_is_saved_when_linking_fails(): void {
		$linker = Mockery::mock( Email_Thread_Linker::class );
		$linker->allows( 'link' )->andThrow( new \RuntimeException( 'Taxonomy unavailable.' ) );

		$email = $this->save( $this->make_repository( $linker ), $this->eml( 'root@example.org', 'Order 123' ) );

		$this->assertGreaterThan( 0, $email->post_id );
		$this->assertNull( $email->thread_term_id );
		$this->assertTrue( $this->logger->hasErrorThatContains( 'Failed to link email {post_id} into a thread' ) );
	}

	/**
	 * An email without a thread (stored before threading existed) is its own one-email thread.
	 *
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository::find_thread
	 */
	public function test_find_thread_for_an_unthreaded_email_returns_it_alone(): void {
		$repository = $this->make_repository();

		$email = $this->save( $repository, $this->eml( 'legacy@example.org', 'Old email' ) );
		wp_delete_object_term_relationships( $email->post_id, $this->taxonomy->get_taxonomy_name() );
		$email = $repository->find_by_post_id( $email->post_id );

		$thread = $repository->find_thread( $email );

		$this->assertSame( 0, $thread->term_id );
		$this->assertSame( array( $email ), $thread->emails );
		$this->assertFalse( $thread->has_related_emails() );
	}

	/**
	 * Deleting one email of a thread keeps the thread for the others.
	 *
	 * @covers \BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_Thread_Taxonomy::delete_empty_terms
	 */
	public function test_deleting_one_email_keeps_the_thread_for_the_rest(): void {
		$repository = $this->make_repository();

		$root  = $this->save( $repository, $this->eml( 'root@example.org', 'Order 123' ) );
		$reply = $this->save( $repository, $this->eml( 'reply@example.org', 'Re: Order 123', 'root@example.org', array( 'root@example.org' ) ) );

		wp_delete_post( $reply->post_id, true );

		$this->assertNotEmpty( term_exists( (int) $root->thread_term_id, $this->taxonomy->get_taxonomy_name() ) );
		$this->assertSame( array( $root->post_id ), $this->thread_post_ids( $root ) );
	}

	/**
	 * Threading must not disturb the per-account status counts.
	 *
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository::count_by_status_for_account_email
	 */
	public function test_status_counts_unaffected_by_threading(): void {
		$repository = $this->make_repository();

		$this->save( $repository, $this->eml( 'root@example.org', 'Order 123' ) );
		$this->save( $repository, $this->eml( 'reply@example.org', 'Re: Order 123', 'root@example.org', array( 'root@example.org' ) ) );

		$this->assertSame( 2, $repository->count_by_status_for_account_email( $this->account )->new_count );
	}

	/**
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory::from_wp_post
	 */
	public function test_factory_hydrates_reference_headers(): void {
		$repository = $this->make_repository();

		$reply = $this->save( $repository, $this->eml( 'reply@example.org', 'Re: Order', 'root@example.org', array( 'ancestor@example.org', 'root@example.org' ) ) );

		$this->assertSame( array( 'root@example.org' ), $reply->in_reply_to );
		$this->assertSame( array( 'ancestor@example.org', 'root@example.org' ), $reply->references );
		$this->assertSame( array( 'ancestor@example.org', 'root@example.org' ), get_post_meta( $reply->post_id, Email_Thread_Linker::META_KEY_REFERENCES, false ) );
		$this->assertSame( array( 'root@example.org' ), get_post_meta( $reply->post_id, Email_Thread_Linker::META_KEY_IN_REPLY_TO, false ) );
	}
	/**
	 * Two emails of a new thread saved at the same moment both miss the slug lookup; the second insert then gets
	 * a `-2` slug. It must join the thread the other request created rather than keep a duplicate.
	 *
	 * The concurrent request is simulated by inserting the thread term from inside the lookup and reporting it
	 * missing, as if the other request inserted it just after this one looked. (`get_term_by()` suppresses the
	 * `get_terms` filter, so the lookup is intercepted on `terms_pre_query`, which short-circuits the query.)
	 *
	 * @covers ::create_term
	 */
	public function test_concurrently_created_thread_is_joined_not_duplicated(): void {
		$taxonomy = $this->taxonomy->get_taxonomy_name();
		$slug     = sha1( 'root@example.org' );

		$concurrent_term_id = 0;
		$simulate_race      = function ( $terms, \WP_Term_Query $term_query ) use ( &$simulate_race, &$concurrent_term_id, $taxonomy, $slug ) {
			if ( ! in_array( $slug, (array) ( $term_query->query_vars['slug'] ?? array() ), true ) ) {
				return $terms;
			}
			remove_filter( 'terms_pre_query', $simulate_race );

			$inserted = wp_insert_term( 'Order 123 (saved by the other request)', $taxonomy, array( 'slug' => $slug ) );
			$this->assertIsArray( $inserted );
			$concurrent_term_id = (int) $inserted['term_id'];

			return array();
		};
		add_filter( 'terms_pre_query', $simulate_race, 10, 2 );

		$email = $this->save( $this->make_repository(), $this->eml( 'root@example.org', 'Order 123' ) );

		$this->assertGreaterThan( 0, $concurrent_term_id, 'Sanity check: the race was simulated.' );
		$this->assertSame( $concurrent_term_id, $email->thread_term_id );

		$thread_terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$this->assertSame( array( $concurrent_term_id ), $thread_terms, 'The duplicate `-2` term was deleted.' );
		$this->assertTrue( $this->logger->hasInfoThatContains( 'was created concurrently' ) );
	}

	/**
	 * A long thread's References header is matched on its first id and most recent ids, so the lookup stays
	 * bounded; the whole header is still stored.
	 *
	 * @covers ::link
	 * @covers ::bound_references
	 */
	public function test_long_references_header_links_root_and_parent(): void {
		$repository = $this->make_repository();

		$references = array_map( fn( int $number ): string => "ref-{$number}@example.org", range( 1, 60 ) );

		$root   = $this->save( $repository, $this->eml( 'ref-1@example.org', 'Order 123' ) );
		$parent = $this->save( $repository, $this->eml( 'ref-60@example.org', 'Re: Order 123', 'ref-59@example.org' ) );
		$this->assertNotSame( $root->thread_term_id, $parent->thread_term_id, 'Sanity check: two threads until the reply arrives.' );

		$reply = $this->save( $repository, $this->eml( 'reply@example.org', 'Re: Order 123', 'ref-60@example.org', $references ) );

		$this->assertSame( array( $root->post_id, $parent->post_id, $reply->post_id ), $this->thread_post_ids( $reply ) );
		$stored_references = get_post_meta( $reply->post_id, Email_Thread_Linker::META_KEY_REFERENCES, false );
		$this->assertIsArray( $stored_references );
		$this->assertCount( 60, $stored_references, 'The whole header is stored.' );
	}

	/**
	 * A reply whose References was truncated to its parent, arriving before both the parent and the root, ends up
	 * in one thread with them once the parent (which references the root) arrives.
	 *
	 * @covers ::link
	 */
	public function test_truncated_reply_arriving_first_joins_once_its_parent_arrives(): void {
		$repository = $this->make_repository();

		$reply  = $this->save( $repository, $this->eml( 'reply@example.org', 'Re: Re: Order 123', 'parent@example.org', array( 'parent@example.org' ) ) );
		$root   = $this->save( $repository, $this->eml( 'root@example.org', 'Order 123' ) );
		$parent = $this->save( $repository, $this->eml( 'parent@example.org', 'Re: Order 123', 'root@example.org', array( 'root@example.org' ) ) );

		$this->assertSame( array( $reply->post_id, $root->post_id, $parent->post_id ), $this->thread_post_ids( $parent ) );
	}
}
