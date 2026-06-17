<?php
/**
 * Single email view: metaboxes, immutability, status management, order notes.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use WP_Post;

/**
 * Renders the single email edit screen: custom metaboxes, read-only enforcement, status management, and log notes.
 */
class Single_Email_View {

	use LoggerAwareTrait;

	/**
	 * CPT slug, cached from settings.
	 *
	 * @var string
	 */
	protected string $post_type;

	/**
	 * Cache of BH_Email instances keyed by post ID.
	 *
	 * @var array<int, BH_Email>
	 */
	protected array $emails = array();

	/**
	 * Constructor.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings                Plugin settings.
	 * @param API_Interface                      $api                     Main API for remote actions.
	 * @param Email_WP_Post_Repository           $email_wp_post_repository Email repository.
	 * @param LoggerInterface                    $logger                  PSR-3 logger.
	 */
	public function __construct(
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		protected API_Interface $api,
		protected Email_WP_Post_Repository $email_wp_post_repository,
		LoggerInterface $logger,
	) {
		$this->setLogger( $logger );
		$this->post_type = $this->settings->get_emails_cpt_underscored_20();
	}

	/**
	 * Returns the BH_Email for the given post, loading it if not already cached.
	 *
	 * @param WP_Post $post The email post to look up.
	 */
	private function get_email_for_post( WP_Post $post ): BH_Email {
		if ( ! isset( $this->emails[ $post->ID ] ) ) {
			$this->emails[ $post->ID ] = $this->email_wp_post_repository->find_by_post_id( $post->ID );
		}
		return $this->emails[ $post->ID ];
	}

	/**
	 * Add custom metaboxes and remove irrelevant defaults.
	 *
	 * @hooked add_meta_boxes_{$post_type}
	 *
	 * @param WP_Post $post The email post being edited.
	 */
	public function add_meta_boxes( WP_Post $post ): void {

		/**
		 * The email for the current post.
		 *
		 * @var BH_Email $email
		 */
		$email = $this->email_wp_post_repository->find_by_post_id( $post->ID );
		unset( $post );

		remove_meta_box( 'submitdiv', $this->post_type, 'side' );
		remove_meta_box( 'slugdiv', $this->post_type, 'normal' );
		remove_meta_box( 'postcustom', $this->post_type, 'normal' );
		remove_meta_box( 'postexcerpt', $this->post_type, 'normal' );
		remove_meta_box( 'trackbacksdiv', $this->post_type, 'normal' );
		remove_meta_box( 'commentstatusdiv', $this->post_type, 'normal' );
		remove_meta_box( 'commentsdiv', $this->post_type, 'normal' );

		add_meta_box(
			'bh-email-local-status',
			__( 'Local status', 'bh-wp-mailboxes' ),
			$this->render_local_status_metabox( ... ),
			$this->post_type,
			'side',
			'high'
		);

		add_meta_box(
			'bh-email-remote-status',
			__( 'Remote status', 'bh-wp-mailboxes' ),
			$this->render_remote_status_metabox( ... ),
			$this->post_type,
			'side',
			'high'
		);

		add_meta_box(
			'bh-email-headers',
			__( 'Email Headers', 'bh-wp-mailboxes' ),
			$this->render_headers_metabox( ... ),
			$this->post_type,
			'normal',
			'high'
		);

		$body_html = $email->body_html;
		if ( is_string( $body_html ) && '' !== $body_html ) {
			add_meta_box(
				'bh-email-content-html',
				__( 'Email Content – HTML', 'bh-wp-mailboxes' ),
				$this->render_html_content_metabox( ... ),
				$this->post_type,
				'normal',
				'default'
			);
		}

		$body_text = $email->body_plain_text;
		if ( is_string( $body_text ) && '' !== $body_text ) {
			add_meta_box(
				'bh-email-content-plain',
				__( 'Email Content – Plain Text', 'bh-wp-mailboxes' ),
				$this->render_plain_content_metabox( ... ),
				$this->post_type,
				'normal',
				'default'
			);
		}

		add_meta_box(
			'bh-email-attachments',
			__( 'Attachments', 'bh-wp-mailboxes' ),
			$this->render_attachments_metabox( ... ),
			$this->post_type,
			'side',
			'default'
		);

		add_meta_box(
			'bh-email-log-notes',
			__( 'Email Log', 'bh-wp-mailboxes' ),
			$this->render_log_notes_metabox( ... ),
			$this->post_type,
			'side',
			'default'
		);
	}

	/**
	 * Enqueue CSS and JS for the single email edit screen.
	 *
	 * Uses __DIR__ (filesystem path) rather than plugin_dir_url() so assets load
	 * correctly regardless of whether the library is mounted inside wp-content/plugins/
	 * or mapped to another location (e.g. a wp-env volume at the site root).
	 *
	 * @hooked admin_enqueue_scripts
	 */
	public function enqueue_scripts(): void {

		$screen = get_current_screen();
		if ( is_null( $screen ) || $this->post_type !== $screen->id || 'post' !== $screen->base ) {
			return;
		}

		$css_path = __DIR__ . '/css/single-email-view.css';
		if ( is_readable( $css_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local filesystem path, not a remote URL.
			wp_add_inline_style( 'wp-admin', (string) file_get_contents( $css_path ) );
		}

		// pointer-events:none (CSS) blocks clicks; readonly blocks keyboard input.
		wp_add_inline_script(
			'post',
			'jQuery( function( $ ) { $( "#title" ).prop( "readonly", true ).attr( "tabindex", "-1" ); } );',
			'after'
		);

		// The AJAX actions are scoped to this instance's emails CPT (see BH_WP_Mailboxes_Hooks::define_single_email_view_hooks()),
		// so the JS must post the matching, suffixed action names.
		$js_settings = wp_json_encode(
			array(
				'postId'                => (int) get_the_ID(),
				'nonce'                 => wp_create_nonce( 'bh-wp-mailboxes-remote-action' ),
				'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
				'markReadAction'        => 'bh_wp_mailboxes_mark_read_' . $this->post_type,
				'markUnreadAction'      => 'bh_wp_mailboxes_mark_unread_' . $this->post_type,
				'deleteOnServerAction'  => 'bh_wp_mailboxes_delete_on_server_' . $this->post_type,
				'getRemoteStatusAction' => 'bh_wp_mailboxes_get_remote_status_' . $this->post_type,
			)
		);
		if ( is_string( $js_settings ) ) {
			wp_add_inline_script( 'post', 'var bhWpMailboxesSingleEmail = ' . $js_settings . ';', 'after' );
		}

		$js_path = __DIR__ . '/js/single-email-view.js';
		if ( is_readable( $js_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local filesystem path, not a remote URL.
			wp_add_inline_script( 'post', (string) file_get_contents( $js_path ), 'after' );
		}
	}

	// -------------------------------------------------------------------------
	// Metabox render methods
	// -------------------------------------------------------------------------

	/**
	 * Render the Local Status metabox: when the email was downloaded/updated locally, and its local status.
	 *
	 * @param WP_Post $post The email post being edited.
	 */
	public function render_local_status_metabox( WP_Post $post ): void {

		$email = $this->get_email_for_post( $post );
		unset( $post );

		$statuses = array(
			'bh_email_new'       => __( 'New', 'bh-wp-mailboxes' ),
			'bh_email_processed' => __( 'Processed', 'bh-wp-mailboxes' ),
			'bh_email_saved'     => __( 'Saved', 'bh-wp-mailboxes' ),
		);

		$current_status = $email->local_status;

		echo '<div class="submitbox" id="bh-email-status-box">';

		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		// Downloaded at: when the email was fetched into WordPress (post_date).
		$downloaded = $email->downloaded_at ? wp_date( $date_format, $email->downloaded_at->getTimestamp() ) : '';
		echo '<p><strong>' . esc_html__( 'Downloaded at:', 'bh-wp-mailboxes' ) . '</strong> ' . esc_html( (string) $downloaded ) . '</p>';

		// Updated at: last time the post record was modified (post_modified).
		$updated = $email->last_updated ? wp_date( $date_format, $email->last_updated->getTimestamp() ) : '';
		echo '<p><strong>' . esc_html__( 'Updated at:', 'bh-wp-mailboxes' ) . '</strong> ' . esc_html( (string) $updated ) . '</p>';

		// Local status selector.
		echo '<p><label for="bh-post-status"><strong>' . esc_html__( 'Status:', 'bh-wp-mailboxes' ) . '</strong></label></p>';
		echo '<select id="bh-post-status" name="post_status">';
		foreach ( $statuses as $value => $label ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns safe HTML attribute.
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current_status, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		// Show legacy status if not one of the custom ones.
		if ( ! array_key_exists( $current_status, $statuses ) ) {
			$this->logger->warning( 'Problem with email status. Unexpectedly found ' . $current_status );
			echo '<option value="' . esc_attr( $current_status ) . '" selected="selected">' . esc_html( ucfirst( $current_status ) ) . '</option>';
		}
		echo '</select>';
		echo '<input type="hidden" name="hidden_post_status" value="' . esc_attr( $current_status ) . '">';

		echo '<div id="publishing-action">';
		submit_button( __( 'Save', 'bh-wp-mailboxes' ), 'primary', 'save', false );
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the Remote Status metabox: when the email was sent, its read/deleted state on the server,
	 * and the buttons to change that state.
	 *
	 * The read/deleted badges are rendered from cached local meta but shown dimmed with a spinner; the
	 * JS refreshes them from the server on load (see single-email-view.js).
	 *
	 * @param WP_Post $post The email post being edited.
	 */
	public function render_remote_status_metabox( WP_Post $post ): void {

		$email = $this->get_email_for_post( $post );
		unset( $post );

		$is_read           = $email->is_remote_read;
		$deleted_on_server = $email->is_remote_deleted;

		$email_account = $this->api->get_email_account_for_email( $email );
		$provider      = $email_account ? $this->api->get_provider_for_email_account( $email_account ) : null;

		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		// Sent: the email "Date" header.
		$sent = $email->sent_at ? wp_date( $date_format, $email->sent_at->getTimestamp() ) : '';
		echo '<p><strong>' . esc_html__( 'Sent:', 'bh-wp-mailboxes' ) . '</strong> ' . esc_html( (string) $sent ) . '</p>';

		if ( $provider?->can_read_status() ) {
			$badges = $this->get_remote_status_html( $is_read, $deleted_on_server );
			// `is-loading` dims the badges and the spinner indicates the live refresh in progress.
			echo '<div class="bh-email-remote-status is-loading">';
			echo '<span class="spinner is-active" aria-hidden="true"></span>';
			echo '<span class="bh-email-remote-badges">' . wp_kses_post( $badges ) . '</span>';
			echo '</div>';
		}

		// Remote action buttons — shown only when the mailbox supports them.
		if ( $provider?->can_mark_read() && ! $email->is_remote_deleted ) {
			if ( $is_read ) {
				echo '<p><button id="bh-email-mark-unread" class="button">' . esc_html__( 'Mark as unread on server', 'bh-wp-mailboxes' ) . '</button></p>';
			} else {
				echo '<p><button id="bh-email-mark-read" class="button">' . esc_html__( 'Mark as read on server', 'bh-wp-mailboxes' ) . '</button></p>';
			}
		}

		if ( $provider?->can_delete_on_server() && ! $email->is_remote_deleted ) {
			echo '<p><button id="bh-email-delete-on-server" class="button button-link-delete">' . esc_html__( 'Delete on server', 'bh-wp-mailboxes' ) . '</button></p>';
		}
	}

	/**
	 * Render the Email Headers metabox.
	 *
	 * @param WP_Post $post The email post being edited.
	 */
	public function render_headers_metabox( WP_Post $post ): void {

		$email = $this->get_email_for_post( $post );
		unset( $post );

		$headers = $email->imessage->getAllHeaders();

		if ( empty( $headers ) ) {
			echo '<p>' . esc_html__( 'No headers found.', 'bh-wp-mailboxes' ) . '</p>';
			return;
		}

		// A grid (rather than a table) so name/value pairs sit horizontally on desktop and stack
		// vertically on narrow screens (see single-email-view.css).
		echo '<dl class="bh-email-headers-grid">';

		foreach ( $headers as $header ) {

			$parts = explode( ':', (string) $header, 2 );
			$name  = $parts[0];
			$value = $parts[1] ?? '';

			if ( '' !== trim( $value ) ) {
				echo '<dt>' . esc_html( $name ) . '</dt>';
				echo '<dd>' . esc_html( trim( $value ) ) . '</dd>';
			}
		}

		echo '</dl>';
	}

	/**
	 * Render the HTML email content in a sandboxed iframe.
	 *
	 * @param WP_Post $post The email post being edited.
	 */
	public function render_html_content_metabox( WP_Post $post ): void {

		$email = $this->get_email_for_post( $post );
		unset( $post );

		$body_html = $email->body_html;

		if ( ! is_string( $body_html ) || '' === $body_html ) {
			echo '<p>' . esc_html__( 'No HTML content.', 'bh-wp-mailboxes' ) . '</p>';
			return;
		}

		echo '<iframe class="bh-email-html-body" srcdoc="' . esc_attr( $body_html ) . '" sandbox="allow-same-origin" style="width:100%;border:0;min-height:200px;" title="' . esc_attr__( 'Email HTML content', 'bh-wp-mailboxes' ) . '"></iframe>';
	}

	/**
	 * Render the plain-text email body.
	 *
	 * @param WP_Post $post The email post being edited.
	 */
	public function render_plain_content_metabox( WP_Post $post ): void {

		$email = $this->get_email_for_post( $post );
		unset( $post );

		$body_plain_text = $email->body_plain_text;

		if ( null === $body_plain_text || '' === $body_plain_text ) {
			echo '<p>' . esc_html__( 'No plain text content.', 'bh-wp-mailboxes' ) . '</p>';
			return;
		}

		$srcdoc = '<pre style="white-space:pre-wrap;word-break:break-word;font-family:monospace;margin:0;padding:12px;">' . esc_html( $body_plain_text ) . '</pre>';
		echo '<iframe class="bh-email-plain-body" srcdoc="' . esc_attr( $srcdoc ) . '" sandbox="allow-same-origin" style="width:100%;border:0;min-height:200px;" title="' . esc_attr__( 'Email plain text content', 'bh-wp-mailboxes' ) . '"></iframe>';
	}

	/**
	 * Render the Attachments metabox listing files attached to this email post.
	 *
	 * @param WP_Post $post The email post being edited.
	 */
	public function render_attachments_metabox( WP_Post $post ): void {

		$email = $this->get_email_for_post( $post );
		unset( $post );

		// NB: The post type of the private uploads attachments is not `attachments`.
		$attachment_ids = $email->attachment_ids;

		// `null` means the mailbox is not configured to save attachments (the directory is empty or the
		// Private_Uploads dependency is missing). If the email actually carried attachments, they were
		// discarded rather than saved.
		if ( is_null( $attachment_ids ) ) {
			$had_attachments = count( $email->imessage->getAllAttachmentParts() ) > 0;
			$message         = $had_attachments
				? __( 'Attachments discarded.', 'bh-wp-mailboxes' )
				: __( 'No attachments.', 'bh-wp-mailboxes' );
			echo '<p class="bh-email-attachments--empty">' . esc_html( $message ) . '</p>';
			return;
		}

		if ( 0 === count( $attachment_ids ) ) {
			echo '<p class="bh-email-attachments--empty">' . esc_html__( 'No attachments.', 'bh-wp-mailboxes' ) . '</p>';
			return;
		}

		echo '<ul class="bh-email-attachments-list">';
		foreach ( $attachment_ids as $attachment_id ) {
			$url           = wp_get_attachment_url( $attachment_id );
			$attached_file = get_attached_file( $attachment_id );
			$attachment    = get_post( $attachment_id );
			$filename      = basename( $attached_file ? $attached_file : ( $attachment ? $attachment->post_title : '' ) );
			echo '<li>';
			if ( $url ) {
				echo '<a href="' . esc_url( $url ) . '" download>' . esc_html( $filename ) . '</a>';
			} else {
				echo esc_html( $filename );
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Render the Email Log metabox: existing log notes + "Add Note" form.
	 *
	 * @param WP_Post $post The email post being edited.
	 */
	public function render_log_notes_metabox( WP_Post $post ): void {

		// Newest first.
		$notes = get_comments(
			array(
				'post_id' => $post->ID,
				'type'    => 'bh_email_log',
				'order'   => 'DESC',
				'status'  => 'approve',
			)
		);

		$notes = is_array( $notes ) ? $notes : array();

		echo '<ul class="bh-email-log-notes">';
		if ( empty( $notes ) ) {
			echo '<li class="bh-email-log-note--empty">' . esc_html__( 'No log entries yet.', 'bh-wp-mailboxes' ) . '</li>';
		}
		foreach ( $notes as $note ) {
			if ( ! ( $note instanceof \WP_Comment ) ) {
				continue;
			}

			$level = get_comment_meta( (int) $note->comment_ID, 'bh_email_log_level', true );
			$level = in_array( $level, array( 'info', 'notice', 'warning', 'error' ), true ) ? (string) $level : 'info';

			$date_formatted = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $note->comment_date );

			echo '<li class="bh-email-log-note bh-email-log-note--' . esc_attr( $level ) . '">';
			echo '<div class="bh-email-log-note__content"><p>' . wp_kses_post( $note->comment_content ) . '</p></div>';
			echo '<p class="bh-email-log-note__meta">';
			echo '<time datetime="' . esc_attr( $note->comment_date ) . '">' . esc_html( (string) $date_formatted ) . '</time>';

			// When a logged-in user performed the action (rather than cron, which runs as user 0),
			// show their name below the message.
			$user_id = (int) $note->user_id;
			if ( $user_id > 0 ) {
				$user = get_userdata( $user_id );
				if ( $user instanceof \WP_User ) {
					echo ' <span class="bh-email-log-note__user">' . esc_html( $user->display_name ) . '</span>';
				}
			}

			echo '</p>';
			echo '</li>';
		}
		echo '</ul>';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build HTML badge(s) reflecting remote read/deleted status.
	 *
	 * @param ?bool $is_read           Whether the email is marked read on the remote server, or null if unknown.
	 * @param ?bool $is_remote_deleted Whether the email has been deleted on the remote server, or null if unknown.
	 *
	 * @return string HTML string safe for use with wp_kses_post().
	 */
	protected function get_remote_status_html( ?bool $is_read, ?bool $is_remote_deleted ): string {

		$parts = array();

		if ( null !== $is_read ) {
			$parts[] = $is_read
				? '<span class="bh-email-badge bh-email-badge--read">' . esc_html__( 'Read on server', 'bh-wp-mailboxes' ) . '</span>'
				: '<span class="bh-email-badge bh-email-badge--unread">' . esc_html__( 'Unread on server', 'bh-wp-mailboxes' ) . '</span>';
		}

		if ( $is_remote_deleted ) {
			$parts[] = '<span class="bh-email-badge bh-email-badge--deleted">' . esc_html__( 'Deleted on server', 'bh-wp-mailboxes' ) . '</span>';
		}

		return implode( ' ', $parts );
	}
}
