<?php
/**
 * Plain object representing an email, stored or to be stored in the custom post type.
 *
 * https://datatracker.ietf.org/doc/html/rfc5322
 *
 * TODO: attachments.
 */

namespace BrianHenryIE\WP_Mailboxes\Model;

use BrianHenryIE\WP_Mailboxes\Repository\Saved_Post;
use DateTimeInterface;
use ZBateson\MailMimeParser\IMessage;

readonly class BH_Email implements Saved_Post {

	/**
	 * Constructor.
	 *
	 * @param int                   $post_id             WP Posts table saved id.
	 * @param string                $post_type           The CPT slug.
	 * @param IMessage              $imessage            The parsed email (excluding attachments).
	 * @param string                $message_id          The Message Id header, to use as as a uid.
	 * @param string                $subject             Email subject.
	 * @param string                $from_email          Sender email address.
	 * @param ?string               $from_name           Sender display name.
	 * @param string                $original_mime_message The original raw MIME message as a string, excluding attachments.
	 * @param ?string               $body_plain_text     Plain-text body.
	 * @param ?string               $body_html           HTML body.
	 * @param array<int>            $attachment_ids      Post IDs of attachments.
	 * @param array<string, string> $headers             All parsed headers.
	 * @param array<string, mixed>  $meta_data           Provider-specific metadata.
	 * @param ?DateTimeInterface    $sent_at         When the email was received/sent.
	 * @param ?DateTimeInterface    $downloaded_at       Aka. post publish time.
	 * @param ?DateTimeInterface    $last_updated        The wp_post last updated time.
	 * @param string                $post_status         WordPress post status.
	 * @param ?bool                 $is_remote_read      Whether the email has been read on the remote server (null = unknown).
	 * @param ?bool                 $is_remote_deleted   Whether the email has been read on the remote server (null = unknown).
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
		public array $attachment_ids = array(),
		// public array $meta_data = array(),
		public ?DateTimeInterface $sent_at = null,
		public ?DateTimeInterface $downloaded_at = null,
		public ?DateTimeInterface $last_updated = null,
		public string $post_status = 'unread',
		public ?bool $is_remote_read = null,
		public ?bool $is_remote_deleted = null,
	) {}

	public function get_post_type(): string {
		return $this->post_type;
	}

	// public function get_account_category_id(): int {
	// return $this->account_category_id;
	// }

	/** @return array<string, string> */
	public function get_headers(): array {
		return $this->headers;
	}

	public function get_from_email(): string {
		return $this->from_email;
	}

	public function get_from_name(): ?string {
		return $this->from_name;
	}

	public function get_subject(): string {
		return $this->subject;
	}

	public function get_body_plain_text(): string {
		return $this->body_plain_text;
	}

	public function get_body_html(): string {
		return $this->body_html;
	}

	/** @return array<string, mixed> */
	public function get_meta_data(): array {
		return $this->meta_data;
	}

	public function get_sent_at(): ?DateTimeInterface {
		return $this->sent_at;
	}

	public function get_post_id(): int {
		return $this->post_id ?? 0;
	}

	public function get_post_status(): string {
		return $this->post_status;
	}

	public function get_is_read(): ?bool {
		return $this->is_read;
	}
}
