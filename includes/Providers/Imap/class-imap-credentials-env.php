<?php
/**
 * TODO: mention vlucas/phpdotenv
 */

namespace BrianHenryIE\WP_Mailboxes\Providers\Imap;

class Imap_Credentials_Env implements IMAP_Credentials_Interface {

	/** @var array<string,string> */
	protected array $map = array();

	/**
	 * @throws \Exception
	 */
	public function __construct(
		protected string $server = 'IMAP_SERVER',
		protected string $username = 'IMAP_USERNAME',
		protected string $password = 'IMAP_PASSWORD',
		protected string $encryption = 'IMAP_ENCRYPTION',
	) {
		if ( ! isset( $_ENV ) ) {
			throw new \Exception( '$_ENV is not set. Please ensure environment variables are properly loaded.' );
		}

		$this->map['server']     = $this->server;
		$this->map['username']   = $this->username;
		$this->map['password']   = $this->password;
		$this->map['encryption'] = $this->encryption;

		$this->server     = $_ENV[ $this->server ];
		$this->username   = $_ENV[ $this->username ];
		$this->password   = $_ENV[ $this->password ];
		$this->encryption = $_ENV[ $this->encryption ];

		$this->validate();
	}

	/**
	 * TODO: Add example error message here.
	 *
	 * @throws \Exception
	 */
	protected function validate(): void {
		$missing = array();
		if ( empty( $this->server ) ) {
			$missing[] = 'server';
		}
		if ( empty( $this->username ) ) {
			$missing[] = 'username';
		}
		if ( empty( $this->password ) ) {
			$missing[] = 'password';
		}
		$message = sprintf(
			'Required IMAP credential%s %s %s missing. Please set environmental variables %s.',
			count( $missing ) === 1 ? '' : 's',
			implode( ', ', $missing ),
			count( $missing ) === 1 ? 'is' : 'are',
			implode( ', ', array_map( fn( $key ) => $this->map[ $key ], $missing ) )
		);
		throw new \Exception( $message );
	}

	public function get_email_imap_server(): string {
		return $this->server;
	}

	public function get_email_account_username(): string {
		return $this->username;
	}

	public function get_email_account_password(): string {
		return $this->password;
	}

	public function get_encryption(): string {
		return $this->encryption;
	}
}
