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

class BH_Email implements Saved_Post {

	/**
	 * Constructor.
	 *
	 * @param string                $post_type           The CPT slug.
	 * @param int                   $account_category_id The mailbox taxonomy term ID.
	 * @param string                $email_id            The server-assigned message UID.
	 * @param string                $subject             Email subject.
	 * @param string                $from_email          Sender email address.
	 * @param ?string               $from_name           Sender display name.
	 * @param string                $body_plain_text      Plain-text body.
	 * @param string                $body_html           HTML body.
	 * @param array<string, string> $headers             All parsed headers.
	 * @param array<string, mixed>  $meta_data           Provider-specific metadata.
	 * @param ?DateTimeInterface    $received_at         When the email was received/sent.
	 * @param ?int                  $post_id             WordPress post ID once saved.
	 * @param string                $post_status         WordPress post status.
	 * @param ?bool                 $is_read             Whether the email has been read on the remote server (null = unknown).
	 */
	public function __construct(
		protected string $post_type,
		protected int $account_category_id,
		protected string $email_id,
		protected string $subject,
		protected string $from_email,
		protected ?string $from_name = null,
		protected string $body_plain_text = '',
		protected string $body_html = '',
		protected array $headers = array(),
		protected array $meta_data = array(),
		protected ?DateTimeInterface $received_at = null,
		protected ?int $post_id = null,
		protected string $post_status = 'publish',
		protected ?bool $is_read = null,
	) {}

	public function get_post_type(): string {
		return $this->post_type;
	}

	public function get_account_category_id(): int {
		return $this->account_category_id;
	}

	public function get_email_id(): string {
		return $this->email_id;
	}

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

	public function get_received_at(): ?DateTimeInterface {
		return $this->received_at;
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

	/**
	 * Reconstruct a BH_Email from a saved CPT post.
	 *
	 * @deprecated Use Email_WP_Post_Repository::find_by_post_id() instead.
	 */
	#[\Deprecated( 'Use Email_WP_Post_Repository::find_by_post_id() instead.' )]
	public static function create_from_cpt( \WP_Post $cpt_email ): BH_Email {

		$post_id    = $cpt_email->ID;
		$email_id   = get_post_meta( $post_id, 'email_id', true );
		$from_email = get_post_meta( $post_id, 'from_email', true );
		$from_name  = get_post_meta( $post_id, 'from_name', true );

		$headers      = array();
		$header_names = get_post_meta( $post_id, 'headers', true );
		if ( is_array( $header_names ) ) {
			foreach ( $header_names as $header_name ) {
				$header_value = get_post_meta( $post_id, $header_name, true );
				if ( ! empty( $header_value ) ) {
					$headers[ $header_name ] = $header_value;
				}
			}
		}

		return new self(
			post_type:           $cpt_email->post_type,
			account_category_id: 0,
			email_id:            is_string( $email_id ) ? $email_id : '',
			subject:             $cpt_email->post_title,
			from_email:          is_string( $from_email ) ? $from_email : '',
			from_name:           is_string( $from_name ) && '' !== $from_name ? $from_name : null,
			body_plain_text:     $cpt_email->post_content,
			headers:             $headers,
			post_id:             $post_id,
			post_status:         $cpt_email->post_status,
		);
	}
}
