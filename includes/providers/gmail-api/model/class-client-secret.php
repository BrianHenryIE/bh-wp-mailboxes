<?php

namespace BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Model;

readonly class Client_Secret {
	public function __construct(
		public OAuth_Client_Credentials $installed,
		public OAuth_Client_Credentials $web,
	) {
	}
}
