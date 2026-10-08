<?php
/**
 * Tests for BH_Email_Thread_Taxonomy.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use Mockery;
use WP_Error;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_Thread_Taxonomy
 */
class BH_Email_Thread_Taxonomy_Unit_Test extends Unit_Testcase {

	/**
	 * @covers ::taxonomy_name_for_post_type
	 * @covers ::get_taxonomy_name
	 */
	public function test_taxonomy_name_is_post_type_suffixed(): void {
		$sut = new BH_Email_Thread_Taxonomy( 'my_plugin_emails', $this->logger );

		$this->assertSame( 'my_plugin_emails_thread', $sut->get_taxonomy_name() );
		$this->assertSame( 'e2e_email_thread', BH_Email_Thread_Taxonomy::taxonomy_name_for_post_type( 'e2e_email' ) );
		// Post types are at most 20 characters; taxonomy names at most 32.
		$this->assertLessThanOrEqual( 32, strlen( BH_Email_Thread_Taxonomy::taxonomy_name_for_post_type( str_repeat( 'a', 20 ) ) ) );
	}

	/**
	 * @covers ::register_taxonomy
	 */
	public function test_register_taxonomy_is_hidden_and_attached_to_the_post_type(): void {
		$sut = new BH_Email_Thread_Taxonomy( 'my_plugin_emails', $this->logger );

		\WP_Mock::userFunction( 'taxonomy_exists' )->andReturn( false );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );

		\WP_Mock::userFunction(
			'register_taxonomy',
			array(
				'times'  => 1,
				'args'   => array(
					'my_plugin_emails_thread',
					array( 'my_plugin_emails' ),
					Mockery::on(
						function ( array $args ): bool {
							return false === $args['public']
								&& false === $args['show_ui']
								&& false === $args['show_in_rest']
								&& false === $args['hierarchical']
								&& false === $args['rewrite']
								&& false === $args['query_var']
								&& '_update_generic_term_count' === $args['update_count_callback'];
						}
					),
				),
				'return' => Mockery::mock( \WP_Taxonomy::class ),
			)
		);

		$sut->register_taxonomy();
	}

	/**
	 * @covers ::register_taxonomy
	 */
	public function test_register_taxonomy_is_a_no_op_when_already_registered(): void {
		$sut = new BH_Email_Thread_Taxonomy( 'my_plugin_emails', $this->logger );

		\WP_Mock::userFunction( 'taxonomy_exists' )->andReturn( true );
		\WP_Mock::userFunction( 'register_taxonomy' )->never();

		$sut->register_taxonomy();
	}

	/**
	 * @covers ::register_taxonomy
	 */
	public function test_register_taxonomy_logs_error_on_failure(): void {
		$sut = new BH_Email_Thread_Taxonomy( 'my_plugin_emails', $this->logger );

		$wp_error = Mockery::mock( WP_Error::class );
		$wp_error->shouldReceive( 'get_error_message' )->andReturn( 'Registration failed.' );

		\WP_Mock::userFunction( 'taxonomy_exists' )->andReturn( false );
		\WP_Mock::userFunction( 'register_taxonomy' )->andReturn( $wp_error );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( true );

		$sut->register_taxonomy();

		$this->assertTrue( $this->logger->hasErrorThatContains( 'Failed to register email thread taxonomy' ) );
	}

	/**
	 * @covers ::delete_empty_terms
	 */
	public function test_delete_empty_terms_ignores_other_taxonomies(): void {
		$sut = new BH_Email_Thread_Taxonomy( 'my_plugin_emails', $this->logger );

		\WP_Mock::userFunction( 'get_term_by' )->never();
		\WP_Mock::userFunction( 'wp_delete_term' )->never();

		$sut->delete_empty_terms( 1, array( 5 ), 'category' );
	}
}
