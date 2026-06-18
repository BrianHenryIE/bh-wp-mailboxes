<?php
/**
 * An example implementation of `Email_Fetcher_Interface` that can be used to provide fixtures for testing.
 *
 * Reads from a directory of text files containing emails. Operations on emails are saved per-user in wp_options
 * and can be reset.
 *
 * To be used in Playground demo plugin and E2E tests.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Providers;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Email_Provider_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use DateTimeInterface;
use Illuminate\Support\Collection;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * A fixtures-backed email provider for demos and E2E tests.
 *
 * Reads emails from a directory of JSON files and records read/deleted operations per-user in user meta.
 */
class Mock_Mailbox_Fixtures_Provider implements Email_Provider_Interface {

	/**
	 * Absolute path to the directory of `.json` email fixtures.
	 *
	 * @var string
	 */
	protected string $fixtures_directory = __DIR__ . '/fixtures';

	/**
	 * The credentials passed to the provider (unused by the fixtures provider).
	 *
	 * @var Account_Credentials_Interface
	 */
	protected Account_Credentials_Interface $credentials;

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
	 * Register the WordPress hooks this provider uses.
	 */
	public function register_hooks(): void {
		add_filter( 'bh_wp_mailboxes_provider_for_account', array( $this, 'provider' ), 10, 3 );
		add_filter( 'get_post_metadata', array( $this, 'meta_filter' ), 10, 5 );
		add_action( 'manage_posts_extra_tablenav', array( $this, 'print_extra_table_controls_at_top' ), 10, 1 );
	}

	/**
	 * Return our custom provider (this) for accounts configured to use it.
	 *
	 * @hooked bh_wp_mailboxes_provider_for_account
	 *
	 * @see API::get_provider_for_email_account()
	 *
	 * @param mixed|Email_Provider_Interface|null $value Existing filtered value – begins as null – should be `Email_Provider_Interface` but WordPress does not enforce types in filters.
	 * @param string                              $plugin_slug Is the API instance from this plugin (otherwise it may be a different, incompatible version).
	 * @param BH_Email_Account                    $email_account The account whose email is being checked.
	 *
	 * @return mixed|Email_Provider_Interface|null
	 */
	public function provider( mixed $value, string $plugin_slug, BH_Email_Account $email_account ): mixed {
		if ( $this->mailbox_settings->get_plugin_slug() === $plugin_slug
			&& get_class( $this ) === $email_account->provider_type_class ) {
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

		wp_nonce_field( 'bh-wp-mailboxes-reset-fixtures', '_wpnonce_checknow' );
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
	public function meta_filter( $value, $object_id, $meta_key, $single = true, $meta_type = 'user' ) {

		// Per-user fixture state only applies to a logged-in user; without one (cron, unit tests)
		// get_user_meta() returns false rather than an array, so there is nothing to override.
		if ( 0 === get_current_user_id() ) {
			return $value;
		}

		if ( 'is_remote_deleted' === $meta_key ) {
			$user_remote_deleted_post_ids = get_user_meta( user_id: get_current_user_id(), key:'_mock_mailbox_fixtures_provider_is_remote_deleted', single: false );
			if ( in_array( (string) $object_id, $user_remote_deleted_post_ids, true ) ) {
				return 'yes';
			}
		}

		if ( 'is_remote_read' === $meta_key ) {
			$user_remote_read_post_ids = get_user_meta( user_id: get_current_user_id(), key:'_mock_mailbox_fixtures_provider_is_remote_read', single: false );
			if ( in_array( (string) $object_id, $user_remote_read_post_ids, true ) ) {
				return 'yes';
			}
			$user_remote_unread_post_ids = get_user_meta( user_id: get_current_user_id(), key:'_mock_mailbox_fixtures_provider_is_remote_unread', single: false );
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
		delete_user_meta( get_current_user_id(), '_mock_mailbox_fixtures_provider_is_remote_deleted' );
		delete_user_meta( get_current_user_id(), '_mock_mailbox_fixtures_provider_is_remote_read' );
		delete_user_meta( get_current_user_id(), '_mock_mailbox_fixtures_provider_is_remote_unread' );
	}

	/**
	 * Store the credentials for later use by this fixtures provider.
	 *
	 * @param Account_Credentials_Interface $credentials The account credentials (unused by the fixtures provider).
	 */
	public function set_credentials( Account_Credentials_Interface $credentials ): void {
		$this->credentials = $credentials;
	}

	/**
	 * Read the email fixtures from disk as unsaved emails for the API to dedupe and save.
	 *
	 * @param DateTimeInterface $since_time The earliest time to retrieve emails from (ignored; all fixtures are returned).
	 *
	 * @return Collection<int, Fetched_Email>
	 */
	public function retrieve_emails( DateTimeInterface $since_time ): Collection {
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
	 * The fixtures provider tracks read status per-user.
	 */
	public function can_read_status(): bool {
		return true;
	}

	/**
	 * Fixture emails are always reported as read.
	 *
	 * @param Remote_Email_Coordinates $coordinates The data required to address a single email.
	 */
	public function get_is_marked_read( Remote_Email_Coordinates $coordinates ): bool {

		$post_id = $this->get_post_id_for_coordinates( $coordinates );
		// meta_filter returns 'yes' (read), 'no' (unread) or null; only 'yes' means read.
		return 'yes' === $this->meta_filter( null, $post_id, 'is_remote_read' );
	}

	/**
	 * The fixtures provider supports changing read status per-user.
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
		$usermeta = get_user_meta( get_current_user_id() );

		$add_meta_key = '_mock_mailbox_fixtures_provider_is_remote_' . ( $is_read ? 'read' : 'unread' );
		if ( ! isset( $usermeta[ $add_meta_key ] )
		|| ( isset( $usermeta[ $add_meta_key ] ) && ! in_array( (string) $post_id, $usermeta[ $add_meta_key ], true ) )
		) {
			add_user_meta(
				user_id: get_current_user_id(),
				meta_key: $add_meta_key,
				meta_value: $post_id,
			);
		}

		$delete_meta_key = '_mock_mailbox_fixtures_provider_is_remote_' . ( $is_read ? 'unread' : 'read' );
		if ( isset( $usermeta[ $delete_meta_key ] ) ) {
			delete_user_meta(
				user_id: get_current_user_id(),
				meta_key: $delete_meta_key,
				meta_value: $post_id,
			);
		}
	}

	/**
	 * The fixtures provider supports "deleting" emails on the server (recorded per-user).
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
			meta_key: '_mock_mailbox_fixtures_provider_is_remote_deleted',
			meta_value: $this->get_post_id_for_coordinates( $coordinates )
		);
	}

	/**
	 * Use the message id and wp_post guid to get the post_id.
	 *
	 * @param Remote_Email_Coordinates $coordinates The data required to address a single email.
	 *
	 * @return int The post ID, or 0 when no matching post is found.
	 */
	protected function get_post_id_for_coordinates( Remote_Email_Coordinates $coordinates ): int {

		$post_guid = Email_WP_Post_Repository::guid_for(
			post_type: $this->mailbox_settings->get_emails_cpt_underscored_20(),
			account_email_address: $this->email_account_settings->get_account_email_address(),
			email_id: $coordinates->message_id
		);

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE guid = %s", $post_guid ) );

		return is_numeric( $post_id ) ? (int) $post_id : 0;
	}
}
