<?php
/**
 * Credentials store backed by the WordPress Secrets API.
 *
 * Uses `wp_set_secret()` / `wp_get_secret()` / `wp_delete_secret()` from the library's own copy of the API,
 * loaded from vendor by {@see \BrianHenryIE\WP_Mailboxes\Secrets_API_Loader} (consumers prefix the copy's
 * names at build time, so this is independent of core's or an activated plugin's implementation). The
 * copy resolves its provider the way the API does: libsodium encryption over the options store by
 * default, or whatever a `secrets.php` drop-in installs.
 *
 * One secret per account, named `{plugin-slug}/{accounts-post-type}-{hash of the email address}`, holding
 * the credentials' own JSON representation ({@see Account_Credentials_Interface::jsonSerialize()}): a
 * `type` key (`imap` or `gmail`) plus the credential fields, rebuilt by the matching class's
 * `from_array()`. The Secrets API encrypts the value at rest; the name is derived, not stored, so
 * listing a plugin's secrets shows which accounts have credentials without revealing addresses.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Gmail_Credentials;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\Imap_Credentials;
use InvalidArgumentException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use RuntimeException;
use WP_Error;

/**
 * Keeps credentials' JSON in the Secrets API.
 */
class Secrets_Credentials_Store implements Credentials_Store_Interface {

	use LoggerAwareTrait;

	/**
	 * The classes that rebuild stored credentials, keyed by their `type`.
	 *
	 * @var array<string, class-string>
	 */
	const TYPES = array(
		Imap_Credentials::TYPE  => Imap_Credentials::class,
		Gmail_Credentials::TYPE => Gmail_Credentials::class,
	);

	/**
	 * Constructor.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings Provides the plugin slug (the secret namespace) and the accounts post type.
	 * @param LoggerInterface                    $logger   PSR-3 logger.
	 */
	public function __construct(
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		LoggerInterface $logger,
	) {
		$this->setLogger( $logger );
	}

	/**
	 * The API's functions exist ({@see Secrets_API_Loader::load()} has included the library's copy).
	 */
	public function is_available(): bool {
		return function_exists( 'wp_get_secret' ) && function_exists( 'wp_set_secret' ) && function_exists( 'wp_delete_secret' );
	}


	/**
	 * The secret's name for an account: `{plugin-slug}/{accounts-post-type}-{sha256 of the lowercased address}`.
	 *
	 * Secret names allow only lowercase alphanumerics, hyphens and underscores in each of the two
	 * segments, so the plugin slug is normalised and the address is hashed.
	 *
	 * @param BH_Email_Account $account The account.
	 */
	public function get_secret_name( BH_Email_Account $account ): string {
		$namespace = $this->normalise_segment( $this->settings->get_plugin_slug() );
		$key       = $this->normalise_segment( $this->settings->get_email_accounts_cpt_underscored_20() )
			. '-' . substr( hash( 'sha256', strtolower( trim( $account->email_address ) ) ), 0, 32 );

		return $namespace . '/' . $key;
	}

	/**
	 * Read and deserialise the account's credentials.
	 *
	 * @param BH_Email_Account $account The account.
	 */
	public function get( BH_Email_Account $account ): ?Account_Credentials_Interface {
		if ( ! $this->is_available() ) {
			$this->logger->error( 'The Secrets API is not available; cannot read credentials for ' . $account->email_address . '.' );
			return null;
		}

		$secret = wp_get_secret( $this->get_secret_name( $account ) );

		if ( is_null( $secret ) ) {
			return null;
		}

		if ( $secret instanceof WP_Error ) {
			$this->logger->error(
				'Failed to read credentials for ' . $account->email_address . ': ' . $secret->get_error_message(),
				array( 'error' => $secret )
			);
			return null;
		}

		$revealed = $secret->reveal();
		$data     = json_decode( is_string( $revealed ) ? $revealed : '', true );

		if ( ! is_array( $data ) ) {
			$this->logger->error( 'The saved credentials for ' . $account->email_address . ' are not valid JSON.' );
			return null;
		}

		try {
			return $this->from_array( $data );
		} catch ( \Throwable $throwable ) {
			$this->logger->error(
				'The saved credentials for ' . $account->email_address . ' could not be deserialised: ' . $throwable->getMessage(),
				array( 'exception' => $throwable )
			);
			return null;
		}
	}

	/**
	 * Serialise and store the account's credentials.
	 *
	 * @param BH_Email_Account              $account     The account.
	 * @param Account_Credentials_Interface $credentials IMAP or Gmail credentials.
	 *
	 * @throws InvalidArgumentException When the credentials serialise to a type the store cannot rebuild.
	 * @throws RuntimeException When the Secrets API is unavailable or the write fails.
	 */
	public function save( BH_Email_Account $account, Account_Credentials_Interface $credentials ): void {
		$data = $credentials->jsonSerialize();
		$type = $data['type'] ?? null;

		if ( ! is_string( $type ) || ! array_key_exists( $type, self::TYPES ) ) {
			throw new InvalidArgumentException(
				'Only ' . esc_html( implode( ', ', array_keys( self::TYPES ) ) ) . ' credentials can be stored; ' . esc_html( get_class( $credentials ) ) . ' serialised as ' . esc_html( is_string( $type ) ? $type : gettype( $type ) ) . '.'
			);
		}

		if ( ! $this->is_available() ) {
			throw new RuntimeException( 'The Secrets API is not available; credentials cannot be saved.' );
		}

		$result = wp_set_secret( $this->get_secret_name( $account ), (string) wp_json_encode( $data ) );

		if ( $result instanceof WP_Error ) {
			$this->logger->error(
				'Failed to save credentials for ' . $account->email_address . ': ' . $result->get_error_message(),
				array( 'error' => $result )
			);
			throw new RuntimeException( 'Failed to save credentials: ' . esc_html( $result->get_error_message() ) );
		}

		$this->logger->info( 'Saved ' . $type . ' credentials for ' . $account->email_address . '.' );
	}

	/**
	 * Delete the account's credentials.
	 *
	 * @param BH_Email_Account $account The account.
	 *
	 * @throws RuntimeException When the Secrets API is unavailable or the delete fails.
	 */
	public function delete( BH_Email_Account $account ): void {
		if ( ! $this->is_available() ) {
			throw new RuntimeException( 'The Secrets API is not available; credentials cannot be deleted.' );
		}

		$result = wp_delete_secret( $this->get_secret_name( $account ) );

		if ( $result instanceof WP_Error ) {
			$this->logger->error(
				'Failed to delete credentials for ' . $account->email_address . ': ' . $result->get_error_message(),
				array( 'error' => $result )
			);
			throw new RuntimeException( 'Failed to delete credentials: ' . esc_html( $result->get_error_message() ) );
		}
	}

	/**
	 * Rebuild the credentials object from the stored representation, via the class registered for its `type`.
	 *
	 * @param array<mixed> $data The decoded JSON.
	 *
	 * @throws InvalidArgumentException When the type is unknown or a required field is missing.
	 */
	protected function from_array( array $data ): Account_Credentials_Interface {
		$type = $data['type'] ?? '';

		if ( ! is_string( $type ) || ! array_key_exists( $type, self::TYPES ) ) {
			throw new InvalidArgumentException( 'Unknown credentials type: ' . esc_html( is_string( $type ) ? $type : gettype( $type ) ) . '.' );
		}

		return self::TYPES[ $type ]::from_array( $data );
	}

	/**
	 * Make a value usable as a secret-name segment: lowercase alphanumerics, hyphens and underscores,
	 * not starting or ending with either.
	 *
	 * @param string $value The plugin slug or post type.
	 */
	protected function normalise_segment( string $value ): string {
		$segment = (string) preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( $value ) );
		$segment = trim( $segment, '-_' );

		return '' === $segment ? 'bh-wp-mailboxes' : $segment;
	}
}
