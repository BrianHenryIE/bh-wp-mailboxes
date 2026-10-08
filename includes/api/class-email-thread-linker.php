<?php
/**
 * Assigns each newly saved email to a conversation thread.
 *
 * Threads are discovered from the RFC 5322 reference headers alone:
 * - `Message-ID`: this email's id.
 * - `In-Reply-To`: the id of the email replied to (the immediate parent).
 * - `References`: the parent's `References` plus the parent's id, i.e. the ancestor chain, oldest first.
 *
 * No header is guaranteed to name the thread root (clients truncate `References`, or send only
 * `In-Reply-To`), so, as in JWZ threading, an email joins every thread that shares *any* id with it,
 * in either direction:
 * - an existing email whose `Message-ID` is among this email's ids (an ancestor or sibling we hold), found
 *   through the indexed `post_name` slug the repository already stores; and
 * - an existing email whose `In-Reply-To`/`References` include one of this email's ids (a descendant, or a
 *   sibling that shares an ancestor we never stored), found through the `in_reply_to`/`references` post meta.
 *
 * Matching on all of `References` (not just `In-Reply-To`) is what makes an inbox-only store work: two
 * customer replies to our (unstored) outgoing mail still share the customer's original id. Emails may
 * arrive out of order, so when an email bridges two existing threads they are merged.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc5322#section-3.6.4
 * @see https://www.jwz.org/doc/threading.html
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\API\Model\Email_Thread_Link_Result;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_Thread_Taxonomy;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use WP_Error;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\Header\IdHeader;
use ZBateson\MailMimeParser\IMessage;

/**
 * Links emails into thread taxonomy terms, merging threads when a late-arriving email bridges them.
 */
class Email_Thread_Linker {

	use LoggerAwareTrait;

	/**
	 * Post meta key holding each `In-Reply-To` id (one row per id).
	 */
	public const META_KEY_IN_REPLY_TO = 'in_reply_to';

	/**
	 * Post meta key holding each `References` id (one row per id).
	 */
	public const META_KEY_REFERENCES = 'references';

	/**
	 * How many References ids are matched on: the first plus the most recent. See {@see self::bound_references()}.
	 */
	public const MAX_MATCHED_REFERENCES = 20;

	/**
	 * Constructor.
	 *
	 * @param string                            $post_type          The emails CPT slug.
	 * @param BH_Email_Thread_Taxonomy          $taxonomy           The thread taxonomy for that post type.
	 * @param ?Email_Account_WP_Post_Repository $account_repository Lists the mailbox's accounts so a thread can span accounts;
	 *                                                              when omitted only the saving account's emails are matched by Message-ID.
	 * @param ?LoggerInterface                  $logger             PSR-3 logger.
	 */
	public function __construct(
		protected string $post_type,
		protected BH_Email_Thread_Taxonomy $taxonomy,
		protected ?Email_Account_WP_Post_Repository $account_repository = null,
		?LoggerInterface $logger = null,
	) {
		$this->setLogger( $logger ?? new NullLogger() );
	}

	/**
	 * The thread taxonomy this linker writes to.
	 */
	public function get_taxonomy(): BH_Email_Thread_Taxonomy {
		return $this->taxonomy;
	}

	/**
	 * Strip the angle brackets and whitespace from a Message-ID so ids compare equal however a client wrote them.
	 *
	 * @param string $message_id A raw header id, e.g. `<abc@example.com>`.
	 */
	public static function normalize_message_id( string $message_id ): string {
		return trim( $message_id, " \t\n\r\0\x0B<>" );
	}

	/**
	 * The normalized, de-duplicated ids in an id header (`In-Reply-To` or `References`), in header order.
	 *
	 * @param IMessage $message     The parsed email.
	 * @param string   $header_name `HeaderConsts::IN_REPLY_TO` or `HeaderConsts::REFERENCES`.
	 *
	 * @return string[]
	 */
	public static function get_header_ids( IMessage $message, string $header_name ): array {
		$header = $message->getHeader( $header_name );
		if ( ! ( $header instanceof IdHeader ) ) {
			return array();
		}

		$ids = array_map( self::normalize_message_id( ... ), $header->getIds() );

		return array_values( array_unique( array_filter( $ids, fn( string $id ): bool => '' !== $id ) ) );
	}

	/**
	 * Assign a just-saved email to its thread, creating or merging threads as needed.
	 *
	 * Call after the email's `in_reply_to`/`references` meta has been written, so later emails can find it.
	 *
	 * @param int              $post_id    The saved email's post id.
	 * @param string           $message_id The email's Message-ID (or the connection's stable fallback id).
	 * @param IMessage         $message    The parsed email, for its `In-Reply-To` and `References` headers.
	 * @param BH_Email_Account $account    The account the email was filed under.
	 */
	public function link( int $post_id, string $message_id, IMessage $message, BH_Email_Account $account ): Email_Thread_Link_Result {

		$this->taxonomy->register_taxonomy();
		$taxonomy = $this->taxonomy->get_taxonomy_name();

		$own_id      = self::normalize_message_id( $message_id );
		$in_reply_to = self::get_header_ids( $message, HeaderConsts::IN_REPLY_TO );
		$references  = self::get_header_ids( $message, HeaderConsts::REFERENCES );

		$ids = array_values(
			array_unique(
				array_filter(
					array_merge( array( $own_id ), $in_reply_to, self::bound_references( $references ) ),
					fn( string $id ): bool => '' !== $id
				)
			)
		);

		$related_post_ids = array() === $ids
			? array()
			: $this->find_related_post_ids( $ids, $this->get_account_email_addresses( $account ), $post_id );

		// One query: which thread terms do the related emails already belong to, and which related emails have none
		// (emails stored before threading existed, since there is no backfill).
		$term_ids            = array();
		$related_with_a_term = array();
		if ( array() !== $related_post_ids ) {
			$relationships = wp_get_object_terms( $related_post_ids, $taxonomy, array( 'fields' => 'all_with_object_id' ) );
			if ( $relationships instanceof WP_Error ) {
				$this->logger->warning(
					'Failed to read thread terms for related emails: {error}',
					array(
						'error'   => $relationships->get_error_message(),
						'post_id' => $post_id,
					)
				);
			} else {
				foreach ( $relationships as $term ) {
					// `all_with_object_id` adds a dynamic `object_id` to each WP_Term.
					$object_id = get_object_vars( $term )['object_id'] ?? null;
					if ( ! is_numeric( $object_id ) ) {
						continue;
					}
					$term_ids[]            = $term->term_id;
					$related_with_a_term[] = (int) $object_id;
				}
			}
		}
		$term_ids = array_values( array_unique( $term_ids ) );

		$is_new_thread   = array() === $term_ids;
		$merged_post_ids = array();

		if ( $is_new_thread ) {
			// The oldest id we know of is the best guess at the root; it only seeds the slug.
			$root_id = $references[0] ?? $in_reply_to[0] ?? $own_id;
			$term_id = $this->create_term( $root_id, $message->getSubject() ?? '', $taxonomy );
		} else {
			// Keep the oldest thread; the email bridges the others into it. Term ids are auto-increment, so the
			// lowest id is the earliest-created thread (not necessarily the one holding the earliest-sent email).
			$term_id = min( $term_ids );
			foreach ( $term_ids as $other_term_id ) {
				if ( $other_term_id === $term_id ) {
					continue;
				}
				$merged_post_ids = array_merge( $merged_post_ids, $this->move_thread( $other_term_id, $term_id, $taxonomy ) );
			}
		}

		$this->set_thread( $post_id, $term_id, $taxonomy );

		foreach ( array_diff( $related_post_ids, $related_with_a_term ) as $unthreaded_post_id ) {
			$this->set_thread( $unthreaded_post_id, $term_id, $taxonomy );
		}

		return new Email_Thread_Link_Result(
			term_id: $term_id,
			is_new_thread: $is_new_thread,
			merged_post_ids: array_values( array_unique( $merged_post_ids ) ),
		);
	}

	/**
	 * Keep the References ids worth matching on: the first (normally the thread root) and the last few (the
	 * nearest ancestors), so a very long thread cannot make the lookup query arbitrarily large.
	 *
	 * The full header is still stored and still seeds the thread's root; only the matching is bounded. An email
	 * whose own id falls in the dropped middle stays reachable through its neighbours in the chain.
	 *
	 * @param string[] $references Normalized References ids, oldest first.
	 *
	 * @return string[]
	 */
	public static function bound_references( array $references ): array {
		if ( count( $references ) <= self::MAX_MATCHED_REFERENCES ) {
			return $references;
		}

		return array_merge(
			array_slice( $references, 0, 1 ),
			array_slice( $references, -( self::MAX_MATCHED_REFERENCES - 1 ) )
		);
	}

	/**
	 * Stored emails connected to any of the given ids, in either direction.
	 *
	 * Two halves joined by UNION, so each can use an index: `post_name` for the emails the ids name, and
	 * `meta_key` for the emails whose In-Reply-To or References contain one of the ids. (`meta_value` is a
	 * LONGTEXT column without an index, so the second half filters the reference rows rather than seeking.)
	 *
	 * @param string[] $ids                     Normalized Message-IDs: the email's own, its In-Reply-To and its References.
	 * @param string[] $account_email_addresses Accounts whose emails may hold one of those ids (for the post_name slug lookup).
	 * @param int      $exclude_post_id         The email being linked.
	 *
	 * @return int[] Post ids.
	 */
	protected function find_related_post_ids( array $ids, array $account_email_addresses, int $exclude_post_id ): array {
		/**
		 * The WordPress database ORM.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		$slugs = array();
		foreach ( $account_email_addresses as $account_email_address ) {
			foreach ( $ids as $id ) {
				$slugs[] = Email_WP_Post_Repository::message_id_slug( $account_email_address, $id );
			}
		}
		$slugs = array_values( array_unique( $slugs ) );

		$id_placeholders   = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$slug_placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The interpolated strings are only `%s` placeholder lists; every value is passed to prepare() in one array, which phpcs cannot count.
		$sql = $wpdb->prepare(
			"SELECT p.ID
			FROM %i p
			WHERE p.post_name IN ({$slug_placeholders})
			AND p.post_type = %s
			AND p.ID != %d
			UNION
			SELECT p.ID
			FROM %i pm
			INNER JOIN %i p ON p.ID = pm.post_id
			WHERE pm.meta_key IN (%s, %s)
			AND pm.meta_value IN ({$id_placeholders})
			AND p.post_type = %s
			AND p.ID != %d",
			array_merge(
				array( $wpdb->posts ),
				$slugs,
				array( $this->post_type, $exclude_post_id, $wpdb->postmeta, $wpdb->posts, self::META_KEY_IN_REPLY_TO, self::META_KEY_REFERENCES ),
				$ids,
				array( $this->post_type, $exclude_post_id )
			)
		);
		// phpcs:enable

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; a one-off lookup at save time.
		$results = $wpdb->get_col( $sql );

		return array_values( array_map( 'intval', array_filter( $results, 'is_numeric' ) ) );
	}

	/**
	 * The email addresses whose stored emails may be part of the thread: every account in the mailbox when the
	 * account repository is available, otherwise just the saving account.
	 *
	 * @param BH_Email_Account $account The account the email was filed under.
	 *
	 * @return string[]
	 */
	protected function get_account_email_addresses( BH_Email_Account $account ): array {
		$addresses = array( $account->get_account_email_address() );

		if ( ! is_null( $this->account_repository ) ) {
			try {
				foreach ( $this->account_repository->get_all() as $mailbox_account ) {
					$addresses[] = $mailbox_account->get_account_email_address();
				}
			} catch ( Throwable $exception ) {
				$this->logger->warning(
					'Failed to list mailbox accounts for thread linking; matching only the saving account: {error}',
					array( 'error' => $exception->getMessage() )
				);
			}
		}

		return array_values( array_unique( array_filter( $addresses, fn( string $address ): bool => '' !== $address ) ) );
	}

	/**
	 * Create the thread term, or reuse it if the root id has already produced one.
	 *
	 * The existing term is looked up by slug first. Relying on `wp_insert_term()`'s `term_exists` error is not
	 * enough: WordPress only reports it when the name (the subject) also matches, and otherwise silently creates
	 * a second term with a `-2` slug, so threading would depend on the subject line.
	 *
	 * The lookup and the insert are not atomic: two emails of a new thread saved at the same moment (e.g. parallel
	 * fetches) can both miss the lookup. The second insert then gets the `-2` slug, so it is checked afterwards:
	 * the duplicate is deleted and the email joins the term the other request created.
	 *
	 * @param string           $root_id  The id believed to be the thread's root; hashed into the slug.
	 * @param string           $subject  The first email's subject, used as the display name only.
	 * @param non-empty-string $taxonomy The thread taxonomy.
	 *
	 * @throws \RuntimeException When WordPress refuses to create the term.
	 */
	protected function create_term( string $root_id, string $subject, string $taxonomy ): int {
		$slug = sha1( $root_id );

		$existing_term = get_term_by( 'slug', $slug, $taxonomy );
		if ( $existing_term instanceof \WP_Term ) {
			return $existing_term->term_id;
		}

		$name = trim( $subject );
		if ( '' === $name ) {
			$name = $root_id;
		}
		$name = mb_substr( $name, 0, 200 );

		$result = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );

		if ( $result instanceof WP_Error ) {
			// The slug is already a thread (same root id and name): join it.
			$existing = $result->get_error_data( 'term_exists' );
			if ( is_numeric( $existing ) ) {
				return (int) $existing;
			}
			$this->logger->error(
				'Failed to create email thread term: {error}',
				array(
					'error' => $result->get_error_message(),
					'slug'  => $slug,
				)
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output: the message is for logs and callers, which escape on display.
			throw new \RuntimeException( 'Failed to create email thread term: ' . $result->get_error_message() );
		}

		$term_id = (int) $result['term_id'];

		$created_term = get_term( $term_id, $taxonomy );
		if ( $created_term instanceof \WP_Term && $created_term->slug !== $slug ) {
			// Another request created the thread between the lookup and the insert; WordPress gave this one a
			// unique `-2` slug. Use theirs.
			$concurrent_term = get_term_by( 'slug', $slug, $taxonomy );
			if ( $concurrent_term instanceof \WP_Term ) {
				wp_delete_term( $term_id, $taxonomy );
				$this->logger->info(
					'Email thread {slug} was created concurrently; joined it rather than keeping duplicate term {duplicate_term_id}.',
					array(
						'slug'              => $slug,
						'duplicate_term_id' => $term_id,
						'term_id'           => $concurrent_term->term_id,
					)
				);
				return $concurrent_term->term_id;
			}
		}

		return $term_id;
	}

	/**
	 * Move every email in one thread into another. The emptied term is deleted by
	 * {@see BH_Email_Thread_Taxonomy::delete_empty_terms()} when its last relationship is removed.
	 *
	 * @param int    $from_term_id The thread being merged away.
	 * @param int    $into_term_id The thread being kept.
	 * @param string $taxonomy     The thread taxonomy.
	 *
	 * @return int[] The post ids moved; an email that could not be moved is logged and left out.
	 */
	protected function move_thread( int $from_term_id, int $into_term_id, string $taxonomy ): array {
		$post_ids = get_objects_in_term( $from_term_id, $taxonomy );
		if ( $post_ids instanceof WP_Error ) {
			$this->logger->warning(
				'Failed to list emails in thread {term_id} for merging: {error}',
				array(
					'term_id' => $from_term_id,
					'error'   => $post_ids->get_error_message(),
				)
			);
			return array();
		}

		$moved = array();
		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( $this->set_thread( $post_id, $into_term_id, $taxonomy ) ) {
				$moved[] = $post_id;
			}
		}

		$this->logger->info(
			'Merged email thread {from_term_id} into {into_term_id} ({count} of {total} emails).',
			array(
				'from_term_id' => $from_term_id,
				'into_term_id' => $into_term_id,
				'count'        => count( $moved ),
				'total'        => count( $post_ids ),
			)
		);

		return $moved;
	}

	/**
	 * Replace an email's thread term.
	 *
	 * @param int    $post_id  The email.
	 * @param int    $term_id  Its thread.
	 * @param string $taxonomy The thread taxonomy.
	 *
	 * @return bool Whether the email is now in the thread.
	 */
	protected function set_thread( int $post_id, int $term_id, string $taxonomy ): bool {
		$result = wp_set_object_terms( $post_id, $term_id, $taxonomy, false );
		if ( $result instanceof WP_Error ) {
			$this->logger->warning(
				'Failed to assign email {post_id} to thread {term_id}: {error}',
				array(
					'post_id' => $post_id,
					'term_id' => $term_id,
					'error'   => $result->get_error_message(),
				)
			);
			return false;
		}
		return true;
	}
}
