<?php
/**
 * Unit tests for the Gmail access token value object and its JSON/file parsing.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Plain json_encode() and temp files are fine in tests.

use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use RuntimeException;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\Access_Token
 */
class Access_Token_Unit_Test extends Unit_Testcase {

	private const TOKEN_JSON = '{"access_token":"ya29.token","expires_in":3599,"scope":"https://www.googleapis.com/auth/gmail.readonly","token_type":"Bearer","created":1700000000,"refresh_token":"1//refresh"}';

	/**
	 * Directories created by the current test.
	 *
	 * @var string[]
	 */
	private array $dirs = array();

	protected function tearDown(): void {
		foreach ( $this->dirs as $dir ) {
			array_map( 'unlink', glob( $dir . '/*' ) ?: array() );
			rmdir( $dir );
		}
		$this->dirs = array();
		parent::tearDown();
	}

	/**
	 * Write a throwaway token file and return its path.
	 *
	 * @param string $contents The file contents.
	 */
	private function make_file( string $contents ): string {
		$dir = sys_get_temp_dir() . '/bh-wp-mailboxes-token-' . uniqid();
		mkdir( $dir );
		$this->dirs[] = $dir;
		file_put_contents( $dir . '/access_token.json', $contents );

		return $dir . '/access_token.json';
	}

	/**
	 * @covers ::__construct
	 */
	public function test_exposes_its_fields(): void {
		$sut = new Access_Token( 'ya29.token', 3599, 'scope', 'Bearer', 1700000000, '1//refresh' );

		$this->assertSame( 'ya29.token', $sut->access_token );
		$this->assertSame( 3599, $sut->expires_in );
		$this->assertSame( 'scope', $sut->scope );
		$this->assertSame( 'Bearer', $sut->token_type );
		$this->assertSame( 1700000000, $sut->created );
		$this->assertSame( '1//refresh', $sut->refresh_token );
	}

	/**
	 * Google's token JSON, as written by the authorisation flow.
	 *
	 * @covers ::from_json
	 */
	public function test_from_json(): void {
		$sut = Access_Token::from_json( json_decode( self::TOKEN_JSON ) );

		$this->assertSame( 'ya29.token', $sut->access_token );
		$this->assertSame( 3599, $sut->expires_in );
		$this->assertSame( 'https://www.googleapis.com/auth/gmail.readonly', $sut->scope );
		$this->assertSame( 'Bearer', $sut->token_type );
		$this->assertSame( 1700000000, $sut->created );
		$this->assertSame( '1//refresh', $sut->refresh_token );
	}

	/**
	 * The credentials store rebuilds a token from its own properties cast to an object; the
	 * connection's refresh path builds one from an array the same way.
	 *
	 * @covers ::from_json
	 */
	public function test_from_json_round_trips_the_objects_own_properties(): void {
		$original = new Access_Token( 'ya29.token', 3599, 'scope', 'Bearer', 1700000000, '1//refresh' );

		$this->assertEquals( $original, Access_Token::from_json( (object) get_object_vars( $original ) ) );
		$this->assertEquals( $original, Access_Token::from_json( json_decode( (string) json_encode( $original ) ) ) );
	}

	/**
	 * @covers ::from_file
	 */
	public function test_from_file_reads_a_token_file(): void {
		$sut = Access_Token::from_file( $this->make_file( self::TOKEN_JSON ) );

		$this->assertSame( 'ya29.token', $sut->access_token );
		$this->assertSame( '1//refresh', $sut->refresh_token );
		$this->assertSame( 1700000000, $sut->created );
	}

	/**
	 * @covers ::from_file
	 */
	public function test_from_file_rejects_invalid_json(): void {
		\WP_Mock::passthruFunction( 'esc_html' );
		$file = $this->make_file( 'not json' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'did not contain valid JSON' );

		Access_Token::from_file( $file );
	}

	/**
	 * @covers ::from_file
	 */
	public function test_from_file_rejects_an_unreadable_file(): void {
		\WP_Mock::passthruFunction( 'esc_html' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to read access token file' );

		// Suppress the fopen() warning PHPUnit would otherwise convert into an error before the exception.
		@Access_Token::from_file( sys_get_temp_dir() . '/bh-wp-mailboxes-missing-' . uniqid() . '.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
