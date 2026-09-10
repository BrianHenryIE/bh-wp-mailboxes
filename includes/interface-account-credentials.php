<?php
/**
 * Credentials for an email account's connection.
 *
 * Implementations serialise themselves for the credentials store: {@see \JsonSerializable::jsonSerialize()}
 * must return the credential values (never configuration such as environment variable names or file
 * paths) plus a `type` key identifying the kind (`imap`, `gmail`) so the store can rebuild them.
 * {@see \BrianHenryIE\WP_Mailboxes\Connections\Imap\IMAP_Credentials_Json_Trait} and
 * {@see \BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Google_API_Credentials_Json_Trait} implement it
 * through the interfaces' getters.
 *
 * @see \BrianHenryIE\WP_Mailboxes\Connections\Imap\IMAP_Credentials_Interface
 * @see \BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Google_API_Credentials_Interface
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

use JsonSerializable;

interface Account_Credentials_Interface extends JsonSerializable {

	/**
	 * The credential values plus a `type` key, for the credentials store.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array;
}
