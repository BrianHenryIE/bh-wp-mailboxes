<?php
/**
 * DTO to accept primitive data in the constructor, map those variable names to wp_post fields, to get an array for
 * `get_post()` etc. queries.
 *
 * Enums are parsed to their backing value.
 *
 * Extend this class to suit a specific post_type; use `::to_wp_post_array()` in calls to `wp_update_post()`
 * etc., and `::to_wp_query_args()` for `WP_Query`.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Queries;

use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Abstract query object mapping domain data to WP_Post fields.
 *
 * @phpstan-type WpUpdatePostArray array{ID?: int, post_type?:string, post_status?:string, post_name?: string, post_author?: int, post_date?: string, post_date_gmt?: string, post_content?: string, post_content_filtered?: string, post_title?: string, post_excerpt?: string, meta_input?:array<string,mixed>}
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
	 * Build the normalised WP_Post field array shared by both public conversions.
	 *
	 * Maps domain values to WP_Post columns and `meta_input`, converts enums/bools/dates/arrays to their
	 * stored representation, and drops null values. This is the write shape (a `$postarr` for
	 * wp_insert_post()/wp_update_post()); {@see self::to_wp_query_args()} adapts it for WP_Query.
	 *
	 * TODO: need a convention for excluding fields that the caller knows aren't important/helpful.
	 *
	 * @return WpUpdatePostArray
	 * @throws InvalidArgumentException When an unknown field is used.
	 */
	protected function normalise_fields(): array {

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

	/**
	 * Returns the post array (`$postarr`) for wp_insert_post() / wp_update_post().
	 *
	 * @return WpUpdatePostArray
	 * @throws InvalidArgumentException When an unknown field is used.
	 */
	public function to_wp_post_array(): array {
		return $this->normalise_fields();
	}

	/**
	 * Returns the arguments for a WP_Query, adapted from the write-shaped {@see self::normalise_fields()}.
	 *
	 * `meta_input` key/values become a `meta_query`; WP_Query silently ignores `meta_input`. The
	 * `post_name` slug is dropped: it is a lossy sanitisation of its source value, and combining `name`
	 * with custom post statuses makes WP_Query treat the request as a single-post lookup with default
	 * `publish`-only statuses. Query on the exact meta value instead.
	 *
	 * @return array<string,mixed> WP_Query arguments.
	 * @throws InvalidArgumentException When an unknown field is used.
	 */
	public function to_wp_query_args(): array {

		$query_args = $this->normalise_fields();

		unset( $query_args['post_name'] );

		if ( isset( $query_args['meta_input'] ) ) {
			$meta_query = array();
			foreach ( $query_args['meta_input'] as $meta_key => $meta_value ) {
				$meta_query[] = array(
					'key'   => $meta_key,
					'value' => $meta_value,
				);
			}
			if ( ! empty( $meta_query ) ) {
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Lookups are infrequent and the set is tiny.
				$query_args['meta_query'] = $meta_query;
			}
			unset( $query_args['meta_input'] );
		}

		return $query_args;
	}
}
