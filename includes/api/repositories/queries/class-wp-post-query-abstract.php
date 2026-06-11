<?php
/**
 * DTO to accept primitive data in the constructor, map those variable names to wp_post fields, to get an array for
 * `get_post()` etc. queries.
 *
 * Enums are parsed to their backing value.
 *
 * Extend this class to suit a specific post_type; use `::to_query_array()` in calls to `update_post()` etc.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories\Queries;

use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Abstract query object mapping domain data to WP_Post fields.
 *
 * @phpstan-type WpUpdatePostArray array{ID?: int, post_type?:string, post_status?:string, post_author?: int, post_date?: string, post_date_gmt?: string, post_content?: string, post_content_filtered?: string, post_title?: string, post_excerpt?: string, meta_input?:array<string,mixed>}
 */
abstract readonly class WP_Post_Query_Abstract {

	/**
	 * Constructor.
	 *
	 * @param string $post_type The CPT slug.
	 */
	public function __construct(
		protected string $post_type,
	) {
	}

	/**
	 * Set the `post_type` for the query.
	 */
	protected function get_post_type(): string {
		return $this->post_type;
	}

	/**
	 * Map data from object variable name to WP_Post field name.
	 *
	 * @see self::get_valid_keys()
	 *
	 * @return array<string,mixed> $map field_name : variable
	 */
	protected function get_wp_post_fields(): array {
		return array();
	}

	/**
	 * Map data from object variable name to post_meta key name.
	 *
	 * TODO: Document behavior: updates or appends?!
	 *
	 * @return array<string,mixed> meta_key : meta_value.
	 */
	abstract protected function get_meta_input(): array;

	/**
	 * Returns valid field names accepted by WP_Query.
	 *
	 * @return string[] List of valid field in the WP_Query.
	 *
	 * @see wordpress/wp-admin/includes/schema.php:159
	 */
	protected function get_valid_keys(): array {
		return array(
			'ID', // Indexed.
			'post_date', // Indexed 3rd `(post_type,post_status,post_date...)`.
			'post_type', // Indexed.
			'post_name', // (slug) Indexed.
			'post_content',
			'post_excerpt',
			'post_title',
			'post_status', // Indexed 2nd `(post_type,post_status...)`.
			'post_parent', // Indexed.
			'numberposts',
			'orderby',
			'order',
			'posts_per_page',
			'meta_input',
			'guid',
		);
	}

	/**
	 * TODO: need a convention for excluding fields that the caller knows aren't important/helpful.
	 *
	 * @return WpUpdatePostArray
	 * @throws InvalidArgumentException When an unknown field is used.
	 */
	public function to_query_array(): array {

		// TODO: are the field names case sensitive?
		$wp_post_fields = $this->get_wp_post_fields();

		foreach ( array_keys( $wp_post_fields ) as $field_name ) {
			if ( ! in_array( $field_name, $this->get_valid_keys(), true ) ) {
				throw new InvalidArgumentException( 'Invalid key: ' . esc_html( $field_name ) );
			}
		}

		$wp_post_fields['post_type'] = $this->post_type;

		$map_types_to_json = function ( $value ) {
			if ( $value instanceof BackedEnum ) {
				return $value->value;
			}
			if ( is_array( $value ) ) {
				return wp_json_encode( $value );
			}
			if ( is_bool( $value ) ) {
				return $value ? 'yes' : 'no';
			}
			if ( $value instanceof DateTimeInterface ) {
				return $value->format( DateTimeInterface::ATOM );
			}
			return $value;
		};

		$filter_null = fn( $value ) => ! is_null( $value );

		/**
		 * Array of wp_post field values after type conversion.
		 *
		 * @var WpUpdatePostArray $wp_post_fields
		 */
		$wp_post_fields = array_map(
			$map_types_to_json,
			$wp_post_fields,
		);

		$wp_post_fields['meta_input'] = array_map(
			$map_types_to_json,
			(array) $this->get_meta_input()
		);

		$wp_post_fields['meta_input'] = array_filter(
			$wp_post_fields['meta_input'],
			$filter_null
		);

		/**
		 * Array of wp_post field values after filtering out null values.
		 *
		 * @var WpUpdatePostArray $wp_post_fields
		 */
		$wp_post_fields = array_filter(
			$wp_post_fields,
			$filter_null
		);

		if ( empty( $wp_post_fields['meta_input'] ) ) {
			unset( $wp_post_fields['meta_input'] );
		}

		return $wp_post_fields;
	}
}
