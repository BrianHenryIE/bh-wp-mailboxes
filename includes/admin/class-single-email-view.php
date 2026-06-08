<?php
/**
 * Single email view: metaboxes, immutability, status management, order notes.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\Repository\Email_WP_Post_Repository;
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
		$this->post_type = $this->settings->get_cpt_underscored_20();
	}

	/**
	 * Add custom metaboxes and remove irrelevant defaults.
	 *
	 * @hooked add_meta_boxes_{$post_type}
	 *
	 * @param WP_Post $post The email post being edited.
	 */
	public function add_meta_boxes( WP_Post $post ): void {

		/** @var BH_Email $email */
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
			'bh-email-status',
			__( 'Email Status', 'bh-wp-mailboxes' ),
			$this->render_status_metabox( ... ),
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

		$body_html = $email->get_body_html();
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

		$body_text = $email->get_body_plain_text();
		if ( '' !== $body_text ) {
			add_meta_box(
				'bh-email-content-plain',
				__( 'Email Content – Plain Text', 'bh-wp-mailboxes' ),
				$this->render_plain_content_metabox( ... ),
				$this->post_type,
				'normal',
				'default'
			);
		}

		// $email->get_attachment_ids()
		// $attachments = get_posts(
		// array(
		// 'post_type'   => 'attachment',
		// 'post_parent' => $post->ID,
		// 'post_status' => 'any',
		// 'numberposts' => -1,
		// 'fields'      => 'ids',
		// )
		// );
		// if ( ! empty( $attachments ) ) {
		// add_meta_box(
		// 'bh-email-attachments',
		// __( 'Attachments', 'bh-wp-mailboxes' ),
		// $this->render_attachments_metabox( ... ),
		// $this->post_type,
		// 'side',
		// 'default'
		// );
		// }

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

		$js_settings = wp_json_encode(
			array(
				'postId'  => (int) get_the_ID(),
				'nonce'   => wp_create_nonce( 'bh-wp-mailboxes-remote-action' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
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

	/**
	 * Restore original title and content to prevent edits to immutable email fields.
	 *
	 * @hooked wp_insert_post_data (priority 10, accepted_args 2)
	 *
	 * @param array<string, mixed> $data    Sanitised post data about to be inserted.
	 * @param array<string, mixed> $postarr Raw post data from the edit form.
	 *
	 * @return array<string, mixed>
	 */
	public function prevent_content_edits( array $data, array $postarr ): array {

		$post_type = $data['post_type'] ?? '';
		if ( $this->post_type !== $post_type ) {
			return $data;
		}

		$post_id = (int) ( $postarr['ID'] ?? 0 );
		if ( 0 === $post_id ) {
			return $data;
		}

		$original = get_post( $post_id );
		if ( ! ( $original instanceof WP_Post ) ) {
			return $data;
		}

		$data['post_title']   = $original->post_title;
		$data['post_content'] = $original->post_content;

		return $data;
	}

	/**
	 * Insert a log note when the post status changes.
	 *
	 * @hooked post_updated (priority 10, accepted_args 3)
	 *
	 * @param int     $post_id     The post ID.
	 * @param WP_Post $post_after  Post object after the update.
	 * @param WP_Post $post_before Post object before the update.
	 */
	public function log_status_change( int $post_id, WP_Post $post_after, WP_Post $post_before ): void {

		if ( $this->post_type !== $post_after->post_type ) {
			return;
		}

		if ( $post_after->post_status === $post_before->post_status ) {
			return;
		}

		$this->api->insert_email_log_note(
			$post_id,
			sprintf(
				'Status changed from "%s" to "%s".',
				$post_before->post_status,
				$post_after->post_status
			)
		);
	}

	/**
	 * Mark email as read on the remote server.
	 *
	 * @hooked wp_ajax_bh_wp_mailboxes_mark_read
	 */
	public function ajax_mark_read(): void {
		$this->handle_remote_action( 'mark_read' );
	}

	/**
	 * Mark email as unread on the remote server.
	 *
	 * @hooked wp_ajax_bh_wp_mailboxes_mark_unread
	 */
	public function ajax_mark_unread(): void {
		$this->handle_remote_action( 'mark_unread' );
	}

	/**
	 * Delete the email on the remote server.
	 *
	 * @hooked wp_ajax_bh_wp_mailboxes_delete_on_server
	 */
	public function ajax_delete_on_server(): void {
		$this->handle_remote_action( 'delete_on_server' );
	}

	/**
	 * Shared AJAX handler for remote email actions.
	 *
	 * @param string $action One of: mark_read, mark_unread, delete_on_server.
	 */
	protected function handle_remote_action( string $action ): void {

		if ( ! isset( $_POST['_wpnonce'], $_POST['post_id'] )
			|| ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'bh-wp-mailboxes-remote-action' ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 403 );
		}

		$post_id = (int) $_POST['post_id'];
		$post    = get_post( $post_id );

		if ( ! ( $post instanceof WP_Post ) || $this->post_type !== $post->post_type ) {
			wp_send_json_error( array( 'message' => 'Invalid post.' ), 400 );
		}

		try {
			$email = $this->email_wp_post_repository->find_by_post_id( $post_id );

			match ( $action ) {
				'mark_read'        => $this->api->mark_email_read( $email ),
				'mark_unread'      => $this->api->mark_email_unread( $email ),
				'delete_on_server' => $this->api->delete_email_on_server( $email ),
			};
		} catch ( \Throwable $e ) {
			$this->logger->error( "Remote action '{$action}' failed: " . $e->getMessage(), array( 'post_id' => $post_id ) );
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}

		$is_read_raw       = get_post_meta( $post_id, 'bh_email_is_read', true );
		$deleted_on_server = get_post_meta( $post_id, 'bh_email_deleted_on_server', true );

		wp_send_json_success(
			array(
				'status_html' => $this->get_remote_status_html( $is_read_raw, $deleted_on_server ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Metabox render methods
	// -------------------------------------------------------------------------

	/**
	 * Render the Email Status metabox (replaces the default submitdiv).
	 *
	 * @param WP_Post $post The email post being edited.
	 */
	public function render_status_metabox( WP_Post $post ): void {

		$statuses = array(
			'bh_email_new'       => __( 'New', 'bh-wp-mailboxes' ),
			'bh_email_processed' => __( 'Processed', 'bh-wp-mailboxes' ),
			'bh_email_saved'     => __( 'Saved', 'bh-wp-mailboxes' ),
		);

		$current_status    = $post->post_status;
		$is_read_raw       = get_post_meta( $post->ID, 'bh_email_is_read', true );
		$deleted_on_server = get_post_meta( $post->ID, 'bh_email_deleted_on_server', true );

		$mailbox = $this->resolve_mailbox_for_post( $post->ID );

		echo '<div class="submitbox" id="bh-email-status-box">';

		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		// Received at: parsed from the email's Date header.
		$date_header = get_post_meta( $post->ID, 'Date', true );
		if ( is_string( $date_header ) && '' !== $date_header ) {
			$parsed = date_create( $date_header );
			if ( false !== $parsed ) {
				$received = wp_date( $date_format, $parsed->getTimestamp() );
				echo '<p><strong>' . esc_html__( 'Received at:', 'bh-wp-mailboxes' ) . '</strong> ' . esc_html( (string) $received ) . '</p>';
			}
		}

		// Downloaded at: when the email was fetched into WordPress (post_date).
		$downloaded = (string) mysql2date( $date_format, $post->post_date );
		echo '<p><strong>' . esc_html__( 'Downloaded at:', 'bh-wp-mailboxes' ) . '</strong> ' . esc_html( $downloaded ) . '</p>';

		// Updated at: last time the post record was modified (post_modified).
		$updated = (string) mysql2date( $date_format, $post->post_modified );
		echo '<p><strong>' . esc_html__( 'Updated at:', 'bh-wp-mailboxes' ) . '</strong> ' . esc_html( $updated ) . '</p>';

		// Local status selector.
		echo '<p><label for="bh-post-status"><strong>' . esc_html__( 'Status:', 'bh-wp-mailboxes' ) . '</strong></label></p>';
		echo '<select id="bh-post-status" name="post_status">';
		foreach ( $statuses as $value => $label ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns safe HTML attribute.
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current_status, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		// Show legacy status if not one of the custom ones.
		if ( ! array_key_exists( $current_status, $statuses ) ) {
			echo '<option value="' . esc_attr( $current_status ) . '" selected="selected">' . esc_html( ucfirst( $current_status ) ) . '</option>';
		}
		echo '</select>';
		echo '<input type="hidden" name="hidden_post_status" value="' . esc_attr( $current_status ) . '">';

		// Remote status badges.
		echo '<div class="bh-email-remote-status">' . wp_kses_post( $this->get_remote_status_html( $is_read_raw, $deleted_on_server ) ) . '</div>';

		// Remote action buttons — shown only when the mailbox supports them.
		if ( ! is_null( $mailbox ) && $mailbox->can_mark_read() ) {
			$is_read = '' !== $is_read_raw && (bool) $is_read_raw;
			if ( $is_read ) {
				echo '<p><button id="bh-email-mark-unread" class="button">' . esc_html__( 'Mark as unread on server', 'bh-wp-mailboxes' ) . '</button></p>';
			} else {
				echo '<p><button id="bh-email-mark-read" class="button">' . esc_html__( 'Mark as read on server', 'bh-wp-mailboxes' ) . '</button></p>';
			}
		}

		if ( ! is_null( $mailbox ) && $mailbox->can_delete_on_server() && '1' !== $deleted_on_server ) {
			echo '<p><button id="bh-email-delete-on-server" class="button button-link-delete">' . esc_html__( 'Delete on server', 'bh-wp-mailboxes' ) . '</button></p>';
		}

		echo '<div id="publishing-action">';
		submit_button( __( 'Save', 'bh-wp-mailboxes' ), 'primary', 'save', false );
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the Email Headers metabox.
	 *
	 * @param WP_Post $post The email post being edited.
	 */
	public function render_headers_metabox( WP_Post $post ): void {

		$header_names = get_post_meta( $post->ID, 'headers', true );
		if ( ! is_array( $header_names ) || empty( $header_names ) ) {
			echo '<p>' . esc_html__( 'No headers found.', 'bh-wp-mailboxes' ) . '</p>';
			return;
		}

		echo '<table class="widefat bh-email-headers-table">';
		echo '<tbody>';

		foreach ( $header_names as $name ) {
			$value = get_post_meta( $post->ID, $name, true );
			if ( is_string( $value ) && '' !== $value ) {
				echo '<tr>';
				echo '<th scope="row">' . esc_html( $name ) . '</th>';
				echo '<td>' . esc_html( $value ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody>';
		echo '</table>';
	}

	/**
	 * Render the HTML email content in a sandboxed iframe.
	 *
	 * @param WP_Post $post The email post being edited.
	 */
	public function render_html_content_metabox( WP_Post $post ): void {

		$body_html = get_post_meta( $post->ID, 'bh_email_body_html', true );
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

		if ( '' === $post->post_content ) {
			echo '<p>' . esc_html__( 'No plain text content.', 'bh-wp-mailboxes' ) . '</p>';
			return;
		}

		$srcdoc = '<pre style="white-space:pre-wrap;word-break:break-word;font-family:monospace;margin:0;padding:12px;">' . esc_html( $post->post_content ) . '</pre>';
		echo '<iframe class="bh-email-plain-body" srcdoc="' . esc_attr( $srcdoc ) . '" sandbox="allow-same-origin" style="width:100%;border:0;min-height:200px;" title="' . esc_attr__( 'Email plain text content', 'bh-wp-mailboxes' ) . '"></iframe>';
	}

	/**
	 * Render the Attachments metabox listing files attached to this email post.
	 *
	 * @param WP_Post $post The email post being edited.
	 */
	public function render_attachments_metabox( WP_Post $post ): void {

		$attachments = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_parent' => $post->ID,
				'post_status' => 'any',
				'numberposts' => -1,
			)
		);

		if ( empty( $attachments ) ) {
			echo '<p>' . esc_html__( 'No attachments.', 'bh-wp-mailboxes' ) . '</p>';
			return;
		}

		echo '<ul class="bh-email-attachments-list">';
		foreach ( $attachments as $attachment ) {
			$url           = wp_get_attachment_url( $attachment->ID );
			$attached_file = get_attached_file( $attachment->ID );
			$filename      = basename( $attached_file ? $attached_file : $attachment->post_title );
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

		$notes = get_comments(
			array(
				'post_id' => $post->ID,
				'type'    => 'bh_email_log',
				'order'   => 'ASC',
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
			$date_formatted = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $note->comment_date );
			echo '<li class="bh-email-log-note">';
			echo '<div class="bh-email-log-note__content"><p>' . wp_kses_post( $note->comment_content ) . '</p></div>';
			echo '<p class="bh-email-log-note__meta"><time datetime="' . esc_attr( $note->comment_date ) . '">' . esc_html( (string) $date_formatted ) . '</time></p>';
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
	 * @param mixed $is_read_raw       Raw meta value for bh_email_is_read.
	 * @param mixed $deleted_on_server Raw meta value for bh_email_deleted_on_server.
	 *
	 * @return string HTML string safe for use with wp_kses_post().
	 */
	protected function get_remote_status_html( mixed $is_read_raw, mixed $deleted_on_server ): string {

		$parts = array();

		if ( '' !== $is_read_raw ) {
			$is_read = (bool) $is_read_raw;
			$parts[] = $is_read
				? '<span class="bh-email-badge bh-email-badge--read">' . esc_html__( 'Read on server', 'bh-wp-mailboxes' ) . '</span>'
				: '<span class="bh-email-badge bh-email-badge--unread">' . esc_html__( 'Unread on server', 'bh-wp-mailboxes' ) . '</span>';
		}

		if ( '1' === $deleted_on_server ) {
			$parts[] = '<span class="bh-email-badge bh-email-badge--deleted">' . esc_html__( 'Deleted on server', 'bh-wp-mailboxes' ) . '</span>';
		}

		return implode( ' ', $parts );
	}

	/**
	 * Resolve the Mailbox_Settings_Interface for the mailbox account the given post belongs to.
	 *
	 * Returns null when the account cannot be matched.
	 *
	 * @param int $post_id The email CPT post ID.
	 *
	 * @return ?Mailbox_Settings_Interface
	 */
	protected function resolve_mailbox_for_post( int $post_id ): ?Mailbox_Settings_Interface {

		$terms = wp_get_post_terms( $post_id, 'bh-wp-mailbox-account' );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return null;
		}

		$term_id = $terms[0]->term_id;

		foreach ( $this->settings->get_configured_mailbox_settings() as $mailbox ) {
			$slug            = sanitize_title( $mailbox->get_account_unique_friendly_name() );
			$term            = get_term_by( 'slug', $slug, 'bh-wp-mailbox-account' );
			$mailbox_term_id = $term instanceof \WP_Term ? $term->term_id : 0;
			if ( $mailbox_term_id === $term_id ) {
				return $mailbox;
			}
		}

		return null;
	}
}
