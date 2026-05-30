<?php

namespace BrianHenryIE\WP_Mailboxes\Providers\Gmail_API;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Model\Access_Token;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Model\Credentials_Web;
use stdClass;

interface Google_API_Credentials_Interface extends Account_Credentials_Interface {

	/**
	 * @return stdClass
	 */
	public function get_project_credentials(): Credentials_Web;

	public function get_access_token(): ?Access_Token;
}
