<?php
/**
 * A fixtures-backed `Email_Connection_Interface` for demos and E2E tests.
 *
 * Reads emails from a directory of `.eml` files. Per-user read/unread/deleted state is recorded in
 * user meta and can be cleared via `reset()`.
 *
 * To be used in the Playground demo plugin and E2E tests.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections;

use BrianHenryIE\WP_Mailboxes\API\Email_Connection_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use DateTimeInterface;
use Illuminate\Support\Collection;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * A fixtures-backed email connection for demos and E2E tests.
 *
 * Reads emails from a directory of `.eml` files and records read/unread/deleted operations per-user
 * in user meta.
 */
class Mock_Mailbox_Fixtures_Connection implements Email_Connection_Interface, Supports_Fetching {

	/**
	 * Absolute path to the directory of `.eml` email fixtures.
	 *
	 * @var string
	 */
	protected string $fixtures_directory = __DIR__ . '/fixtures';

	/**
	 * The option that, when it holds an account's email address, makes {@see self::retrieve_emails()} throw for
	 * that account. Lets an E2E test simulate a connection failure for one account without breaking others.
	 *
	 * @var string
	 */
	public const FAIL_ACCOUNT_OPTION = 'bh_wp_mailboxes_fixtures_fail_account';

	/**
	 * The account most recently resolved to this connection, captured so {@see self::retrieve_emails()} — which
	 * receives no account argument — knows which account it is fetching for.
	 *
	 * @var ?BH_Email_Account
	 */
	protected ?BH_Email_Account $last_resolved_account = null;

	/**
	 * Constructor.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $mailbox_settings       The mailbox settings (provides the CPT name).
	 * @param Email_Account_Settings_Interface   $email_account_settings The account settings (provides the email address).
	 * @param Email_WP_Post_Repository           $email_repository       Repository used to build emails from fixtures.
	 */
	public function __construct(
		protected BH_WP_Mailboxes_Settings_Interface $mailbox_settings,
		protected Email_Account_Settings_Interface $email_account_settings,
		protected Email_WP_Post_Repository $email_repository,
	) {
		$this->register_hooks();
	}

	/**
	 * Register the WordPress hooks this connection uses.
	 */
	public function register_hooks(): void {
		add_filter( 'bh_wp_mailboxes_connection_for_account', array( $this, 'connection' ), 10, 3 );
		add_filter( 'get_post_metadata', array( $this, 'meta_filter' ), 10, 5 );
		add_action( 'manage_posts_extra_tablenav', array( $this, 'print_extra_table_controls_at_top' ), 10, 1 );
		add_action( 'load-edit.php', array( $this, 'handle_reset_submission' ) );
	}

	/**
	 * Handle the "Reset" button submitted from the fixtures emails list table.
	 *
	 * Clears the current user's per-user fixture state, then redirects back to the list table
	 * without the submission parameters so a refresh does not re-trigger the reset.
	 *
	 * @hooked load-edit.php
	 */
	public function handle_reset_submission(): void {

		if ( ! isset( $_GET['reset-fixtures'] ) ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce_reset_fixtures'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce_reset_fixtures'] ) ), 'bh-wp-mailboxes-reset-fixtures' ) ) {
			return;
		}

		$this->reset();

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . $this->mailbox_settings->get_emails_cpt_underscored_20() ) );
		exit;
	}

	/**
	 * A short human-readable name for this connection type.
	 */
	public function get_friendly_name(): string {
		return 'Fixtures';
	}

	/**
	 * Return our custom connection (this) for accounts configured to use it.
	 *
	 * @hooked bh_wp_mailboxes_connection_for_account
	 *
	 * @see API::get_connection_for_email_account()
	 *
	 * @param mixed|Email_Connection_Interface|null $value Existing filtered value – begins as null – should be `Email_Connection_Interface` but WordPress does not enforce types in filters.
	 * @param string                                $plugin_slug Is the API instance from this plugin (otherwise it may be a different, incompatible version).
	 * @param BH_Email_Account                      $email_account The account whose email is being checked.
	 *
	 * @return mixed|Email_Connection_Interface|null
	 */
	public function connection( mixed $value, string $plugin_slug, BH_Email_Account $email_account ): mixed {
		if ( $this->mailbox_settings->get_plugin_slug() === $plugin_slug
			&& get_class( $this ) === $email_account->connection_type_class ) {
			// Remember which account this connection was just resolved for, so retrieve_emails() (which gets
			// no account argument) can honour a per-account simulated-failure flag.
			$this->last_resolved_account = $email_account;
			return $this;
		}

		return $value;
	}

	/**
	 * Prints extra table nav controls at the top of the list table.
	 *
	 * @hooked manage_posts_extra_tablenav
	 * @param string $which The location of the extra table nav markup: 'top' or 'bottom'.
	 */
	public function print_extra_table_controls_at_top( string $which ): void {

		// Only add to our cpt edit screen.
		$screen    = get_current_screen();
		$post_type = $this->mailbox_settings->get_emails_cpt_underscored_20();
		if ( null === $screen || $screen->post_type !== $post_type ) {
			return;
		}

		if ( 'top' !== $which ) {
			return;
		}

		// Use a distinct nonce field name; Emails_List_Page renders its own `_wpnonce_checknow` in the
		// same form for the "Check now" button, and two fields with the same name would collide.
		wp_nonce_field( 'bh-wp-mailboxes-reset-fixtures', '_wpnonce_reset_fixtures' );
		echo '<button name="reset-fixtures" id="reset-fixtures" class="button button-primary">Reset</button>';
	}

	/**
	 * Override the read/deleted status meta of fixture emails on a per-user basis.
	 *
	 * @hooked get_post_metadata
	 *
	 * @param mixed  $value     The pre-filter meta value (null to fall through to the database).
	 * @param int    $object_id The post ID the meta is requested for.
	 * @param string $meta_key  The meta key being requested.
	 * @param bool   $single    Whether a single value was requested.
	 * @param string $meta_type The object type the meta belongs to (e.g. 'post').
	 *
	 * @return mixed 'yes'/'no' to override the stored status, otherwise the unchanged `$value`.
	 */
	public function meta_filter( $value, $object_id, $meta_key, $single = true, $meta_type = 'post' ) {

		// Per-user fixture state only applies to a logged-in user; without one (cron, unit tests)
		// get_user_meta() returns false rather than an array, so there is nothing to override.
		if ( 0 === get_current_user_id() ) {
			return $value;
		}

		if ( 'is_remote_deleted' === $meta_key ) {
			$user_remote_deleted_post_ids = get_user_meta( user_id: get_current_user_id(), key:'_mock_mailbox_fixtures_connection_is_remote_deleted', single: false );
			if ( in_array( (string) $object_id, $user_remote_deleted_post_ids, true ) ) {
				return 'yes';
			}
		}

		if ( 'is_remote_read' === $meta_key ) {
			$user_remote_read_post_ids = get_user_meta( user_id: get_current_user_id(), key:'_mock_mailbox_fixtures_connection_is_remote_read', single: false );
			if ( in_array( (string) $object_id, $user_remote_read_post_ids, true ) ) {
				return 'yes';
			}
			$user_remote_unread_post_ids = get_user_meta( user_id: get_current_user_id(), key:'_mock_mailbox_fixtures_connection_is_remote_unread', single: false );
			if ( in_array( (string) $object_id, $user_remote_unread_post_ids, true ) ) {
				return 'no';
			}
		}

		return $value;
	}

	/**
	 * Clear all per-user fixture state (read/unread/deleted) for the current user.
	 */
	public function reset(): void {
		delete_user_meta( get_current_user_id(), '_mock_mailbox_fixtures_connection_is_remote_deleted' );
		delete_user_meta( get_current_user_id(), '_mock_mailbox_fixtures_connection_is_remote_read' );
		delete_user_meta( get_current_user_id(), '_mock_mailbox_fixtures_connection_is_remote_unread' );
	}

	/**
	 * Read the email fixtures from disk as unsaved emails for the API to dedupe and save.
	 *
	 * @param DateTimeInterface $since_time The earliest time to retrieve emails from (ignored; all fixtures are returned).
	 *
	 * @return Collection<int, Fetched_Email>
	 * @throws \Exception When the E2E simulated-failure flag names the account being fetched.
	 */
	public function retrieve_emails( DateTimeInterface $since_time ): Collection {

		// E2E hook: when the fail-account option names the account being fetched, throw so the API records a
		// failed-login time (which surfaces the auth-failure admin notice). Scoped per-account so setting it
		// does not break other accounts' fetches running in parallel.
		$fail_for_account = get_option( self::FAIL_ACCOUNT_OPTION );
		if ( is_string( $fail_for_account ) && '' !== $fail_for_account
			&& null !== $this->last_resolved_account
			&& $this->last_resolved_account->get_account_email_address() === $fail_for_account ) {
			// A static, plain-text message: no interpolated output to escape (which the account address
			// would otherwise require under WPCS), and the API already logs which account was being fetched.
			throw new \Exception( 'Mock_Mailbox_Fixtures_Connection: simulated connection failure (fixtures fail flag is set for this account).' );
		}

		$files            = glob( $this->fixtures_directory . '/*.eml' ) ?: array();
		$email_collection = new Collection();
		$parser           = new MailMimeParser();
		foreach ( $files as $filepath ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local filesystem path, not a remote URL.
			$message = $parser->parse( (string) file_get_contents( $filepath ), true );
			$email_collection->add(
				new Fetched_Email(
					$message,
					new Remote_Email_Coordinates( message_id: $message->getMessageId() ?? '' ),
					false,
				)
			);
		}
		return $email_collection;
	}

	/**
	 * The fixtures live on disk, so a connection test just checks the directory is readable.
	 */
	public function test_connection(): bool {
		return is_readable( $this->fixtures_directory );
	}

	/**
	 * The fixtures connection tracks read status per-user.
	 */
	public function can_read_status(): bool {
		return true;
	}

	/**
	 * Whether the fixture email is marked read for the current user (per-user state; default unread).
	 *
	 * @param Remote_Email_Coordinates $coordinates The data required to address a single email.
	 */
	public function get_is_marked_read( Remote_Email_Coordinates $coordinates ): bool {

		$post_id = $this->get_post_id_for_coordinates( $coordinates );
		// meta_filter returns 'yes' (read), 'no' (unread) or null; only 'yes' means read.
		return 'yes' === $this->meta_filter( null, $post_id, 'is_remote_read' );
	}

	/**
	 * The fixtures connection supports changing read status per-user.
	 */
	public function can_mark_read(): bool {
		return true;
	}

	/**
	 * Record the read/unread status of a fixture email for the current user.
	 *
	 * @param Remote_Email_Coordinates $coordinates The data required to address a single email.
	 * @param bool                     $is_read     Mark as read, or false for unread.
	 */
	public function set_is_marked_read( Remote_Email_Coordinates $coordinates, bool $is_read = true ): void {

		$post_id  = $this->get_post_id_for_coordinates( $coordinates );
		$usermeta = (array) get_user_meta( get_current_user_id() );

		$add_meta_key = '_mock_mailbox_fixtures_connection_is_remote_' . ( $is_read ? 'read' : 'unread' );
		if ( ! isset( $usermeta[ $add_meta_key ] )
		|| ( is_array( $usermeta[ $add_meta_key ] ) && ! in_array( (string) $post_id, $usermeta[ $add_meta_key ], true ) )
		) {
			add_user_meta(
				user_id: get_current_user_id(),
				meta_key: $add_meta_key,
				meta_value: $post_id,
			);
		}

		$delete_meta_key = '_mock_mailbox_fixtures_connection_is_remote_' . ( $is_read ? 'unread' : 'read' );
		if ( isset( $usermeta[ $delete_meta_key ] ) ) {
			delete_user_meta(
				user_id: get_current_user_id(),
				meta_key: $delete_meta_key,
				meta_value: $post_id,
			);
		}
	}

	/**
	 * The fixtures connection supports "deleting" emails on the server (recorded per-user).
	 */
	public function can_delete_on_server(): bool {
		return true;
	}

	/**
	 * When the same user views this email in future, it should appear as deleted.
	 *
	 * @see BH_Email_Factory::from_wp_post()
	 * @see BH_Email::$is_remote_deleted
	 * $is_deleted_raw    = get_post_meta( $post_id, 'is_remote_deleted', true ); – 'yes'|'no'
	 *
	 * @param Remote_Email_Coordinates $coordinates The data required to address a single email.
	 */
	public function do_delete_on_server( Remote_Email_Coordinates $coordinates ): bool {

		// Add the post id to the meta list of deleted posts by this user.
		return (bool) add_user_meta(
			user_id: get_current_user_id(),
			meta_key: '_mock_mailbox_fixtures_connection_is_remote_deleted',
			meta_value: $this->get_post_id_for_coordinates( $coordinates )
		);
	}

	/**
	 * Resolve the post_id for an email by its account + Message-ID, via the indexed dedup slug.
	 *
	 * @param Remote_Email_Coordinates $coordinates The data required to address a single email.
	 *
	 * @return int The post ID, or 0 when no matching post is found.
	 */
	protected function get_post_id_for_coordinates( Remote_Email_Coordinates $coordinates ): int {

		$slug = Email_WP_Post_Repository::message_id_slug(
			account_email_address: $this->email_account_settings->get_account_email_address(),
			email_id: $coordinates->message_id
		);

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ID FROM %i WHERE post_name = %s AND post_type = %s LIMIT 1',
				$wpdb->posts,
				$slug,
				$this->mailbox_settings->get_emails_cpt_underscored_20()
			)
		);

		return is_numeric( $post_id ) ? (int) $post_id : 0;
	}
}
