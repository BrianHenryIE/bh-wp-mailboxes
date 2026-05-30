<?php
/**
 * Plain object representing an email stored/to be stored in the custom post type.
 *
 * https://datatracker.ietf.org/doc/html/rfc5322
 *
 * TODO: attachments.
 */

namespace BrianHenryIE\WP_Mailboxes\Model;

use DateTime;
use WP_Post;

class BH_Email {

	protected ?int $post_id = null;

	/**
	 * The custom post type defined when instantiating bh-wp-mailboxes.
	 * e.g. bh-wc-venmo-gateway-emails
	 */
	protected string $post_type;

	protected string $post_status = 'publish'; // | saved | linked ... what ???

	/**
	 * A custom taxonomy is used to distinguish multiple email accounts.
	 */
	protected int $account_category_id;

	/**
	 * Email id is the uid from the server. It is used in the WordPress guid field.
	 *
	 * @var string
	 */
	protected string $email_id;

	/**
	 * @var array<string, string>
	 */
	protected array $headers = array();

	/**
	 * The email part of `Brian Henry <brianhenryie@gmail.com>`.
	 */
	protected string $from_email;

	/**
	 * The name part of `Brian Henry <brianhenryie@gmail.com>`.
	 * Sometimes there is only the email address.
	 */
	protected ?string $from_name = null;

	protected string $subject;

	protected string $body_plain_text;

	protected string $body_html;

	/** @var array<string, mixed> */
	protected array $meta_data = array();

	/**
	 * @return string[]
	 */
	public function get_headers(): array {
		return $this->headers;
	}

	/**
	 * @return string
	 */
	public function get_from_email(): string {
		return $this->from_email;
	}

	/**
	 * @return string
	 */
	public function get_subject(): string {
		return $this->subject;
	}

	/**
	 * @return string
	 */
	public function get_body_plain_text(): string {
		return $this->body_plain_text;
	}

	/**
	 * @return string
	 */
	public function get_body_html(): string {
		return $this->body_html;
	}



	public static function create_from_cpt( WP_Post $cpt_email ): BH_Email {

		$bh_email = new BH_Email();

		$post_id           = $cpt_email->ID;
		$bh_email->post_id = $post_id;

		$bh_email->post_type = $cpt_email->post_type;
		$bh_email->subject   = $cpt_email->post_title;

		$bh_email->email_id   = get_post_meta( $post_id, 'email_id', true );
		$bh_email->from_email = get_post_meta( $post_id, 'from_email', true );
		$bh_email->from_name  = get_post_meta( $post_id, 'from_name', true );

		$header_names = get_post_meta( $post_id, 'headers', true );
		foreach ( $header_names as $header_name ) {
			$header_value = get_post_meta( $post_id, $header_name, true );
			if ( ! empty( $header_value ) ) {
				$bh_email->headers[ $header_name ] = $header_value;
			}
		}
		return $bh_email;
	}

	/**
	 * @return int The WordPress post id.
	 */
	public function save(): int {

		$post_id = $this->post_id;

		// See have we already downloaded this email.
		if ( empty( $post_id ) ) {
			$wp_guid = $this->get_guid_for_wordpress();
			$post_id = $this->query_post_id_with_guid( $wp_guid );
		}

		// post_author ... we should search wp_users for a matching email address and list their emails on their admin user profile.

		$meta = $this->meta_data;

		// Store the headers as individual meta values, and keep the keys as a list to distinguish from other metadata.
		$meta['email_id']   = $this->email_id;
		$meta['from_email'] = $this->get_from_email();
		$meta['from_name']  = $this->from_name;

		$meta['headers'] = array_keys( $this->get_headers() );

		foreach ( $this->get_headers() as $header_name => $value ) {
			$meta[ $header_name ] = $value;
		}

		// TODO: This works for IMAP, will it work for gmail?
		$email_unixtime = $meta['udate'];

		$args = array(
			'post_title'    => $this->subject,
			'post_name'     => sanitize_title( $this->subject ),                                            // The alternate name of the entry (slug) will be used in the Url.
			'post_content'  => $this->body_plain_text,                                // post content
			'post_date'     => gmdate( 'Y-m-d H:i:s', $email_unixtime ),                                           // The time the post was created.
		// 'post_date_gmt'  => Y-m-d H:i:s,                                           // The time the post was created in GMT time zone.
		// 'post_excerpt'   => <an excerpt>,                                          // excerpt text
			'post_status'   => $this->post_status, // The status of the post being created.
			'post_type'     => $this->post_type,
			'post_category' => array( $this->account_category_id ),                           // Category to which the post belongs to.
			'meta_input'    => $meta,
			'guid'          => $this->get_guid_for_wordpress(),
		);

		if ( ! is_null( $post_id ) ) {
			$args['ID'] = $this->post_id;
		}

		$post_id = wp_insert_post( $args );

		$this->post_id = $post_id;

		return $post_id;
	}

	protected function get_guid_for_wordpress(): string {
		$site_url = get_site_url();
		return "{$site_url}|" . sanitize_key( $this->email_id );
	}

	/**
	 * @see https://stackoverflow.com/a/27054880/336146
	 * TODO: CACHE!
	 *
	 * @param string $guid
	 */
	protected function query_post_id_with_guid( string $guid ): ?int {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE guid=%s", $guid ) );
	}


	/**
	 *
	 * @return DateTime
	 */
	public function get_received_time(): DateTime {
		// TODO: not implemented
		return new DateTime();
	}

	/**
	 * TODO: Flag/list of posts the email was used for. e.g. do not delete this email/cpt-post if it is in use.
	 */
	// array linked_posts
}
