<?php
/**
 * Plain object representing an email, stored or to be stored in the custom post type.
 *
 * See https://datatracker.ietf.org/doc/html/rfc5322
 *
 * TODO: attachments.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Model;

use BrianHenryIE\WP_Mailboxes\API\Repositories\Saved_Post;
use DateTimeInterface;
use ZBateson\MailMimeParser\IMessage;

/**
 * Immutable value object for a saved email.
 */
readonly class BH_Email implements Saved_Post {

	/**
	 * Constructor.
	 *
	 * @param int                $post_id               WP Posts table saved id.
	 * @param string             $post_type             The CPT slug.
	 * @param IMessage           $imessage              The parsed email (excluding attachments).
	 * @param string             $message_id            The Message Id header, to use as a uid.
	 * @param string             $subject               Email subject.
	 * @param string             $from_email            Sender email address.
	 * @param ?string            $from_name             Sender display name.
	 * @param string             $original_mime_message The original raw MIME message as a string, excluding attachments.
	 * @param ?string            $body_plain_text       Plain-text body.
	 * @param ?string            $body_html             HTML body.
	 * @param array<int>         $attachment_ids        Post IDs of attachments.
	 * @param ?DateTimeInterface $sent_at               When the email was received/sent.
	 * @param ?DateTimeInterface $downloaded_at         Aka. post publish time.
	 * @param ?DateTimeInterface $last_updated          The wp_post last updated time.
	 * @param string             $post_status           WordPress post status.
	 * @param ?bool              $is_remote_read        Whether the email has been read on the remote server (null = unknown).
	 * @param ?bool              $is_remote_deleted     Whether the email has been deleted on the remote server (null = unknown).
	 */
	public function __construct(
		public int $post_id,
		public string $post_type,
		public IMessage $imessage,
		public string $message_id,
		public string $subject,
		public string $from_email,
		public ?string $from_name = null,
		public string $original_mime_message = '',
		public ?string $body_plain_text = '',
		public ?string $body_html = '',
		public ?array $attachment_ids = null, // `null` when configured not to save attachments.
		// public array $meta_data = array(), // TODO: add meta data support.
		public ?DateTimeInterface $sent_at = null, // `null` implies an issue parsing the date.
		public ?DateTimeInterface $downloaded_at = null,
		public ?DateTimeInterface $last_updated = null, // I'm not sure this can be null.
		public string $post_status = 'unread',
		public ?bool $is_remote_read = null,
		public ?bool $is_remote_deleted = null,
	) {}

	/**
	 * Returns the CPT slug for this email.
	 */
	public function get_post_type(): string {
		return $this->post_type;
	}

	/**
	 * Returns the sender's email address.
	 */
	public function get_from_email(): string {
		return $this->from_email;
	}

	/**
	 * Returns the email subject line.
	 */
	public function get_subject(): string {
		return $this->subject;
	}

	/**
	 * Returns the date/time the email was sent, or null if unknown.
	 */
	public function get_sent_at(): ?DateTimeInterface {
		return $this->sent_at;
	}

	/**
	 * Returns the WordPress post ID for this email.
	 */
	public function get_post_id(): int {
		return $this->post_id;
	}

	/**
	 * Returns the WordPress post status for this email.
	 */
	public function get_post_status(): string {
		return $this->post_status;
	}
}
