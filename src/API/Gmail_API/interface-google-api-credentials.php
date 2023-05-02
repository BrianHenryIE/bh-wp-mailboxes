<?php

namespace BrianHenryIE\WP_Mailboxes\API\Gmail_API;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use stdClass;

interface Google_API_Credentials_Interface extends Account_Credentials_Interface {

	/**
	 * @return stdClass
	 */
	public function get_project_credentials(): array;

	public function get_access_token(): ?array;

}
