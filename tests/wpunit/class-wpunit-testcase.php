<?php

namespace BrianHenryIE\WP_Mailboxes;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Factories\BH_Email_Factory;
use Mockery;
use Psr\Log\LoggerInterface;
use lucatume\WPBrowser\TestCase\WPTestCase;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

class WPUnit_Testcase extends WPTestCase {

	protected LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = new ColorLogger();
	}

	protected function get_installed_major_version( string $plugin_basename ): int {
		$plugin_headers = get_plugin_data( codecept_root_dir( WP_PLUGIN_DIR . '/' . $plugin_basename ) );
		if ( 1 === preg_match( '/(\d+)/', (string) $plugin_headers['Version'], $output_array ) ) {
			return (int) $output_array[1];
		} else {
			return -1;
		}
	}

	protected function is_activate_and_major_version( string $plugin_basename, int $major_version ): bool {
		$is_active = is_plugin_active( $plugin_basename );
		if ( ! $is_active ) {
			return false;
		}
		return $this->get_installed_major_version( $plugin_basename ) === $major_version;
	}

	protected function create_post_from_fixture(
		string $filepath,
		string $post_type,
	): int {
		$email_contents = file_get_contents( $filepath );

		$repo = new Email_WP_Post_Repository(
			$post_type,
			new BH_Email_Factory( $this->logger ),
			$this->logger
		);

		$parser = new MailMimeParser();
		/** @var IMessage $email */
		$email = $parser->parse( $email_contents, true );

		// BH_WP_Mailboxes_Settings_Interface $mailboxes,
		$mailboxes = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$mailboxes->expects( 'get_cpt_underscored_20' )->andReturn( $post_type );

		// Email_Account_Settings_Interface $email_account
		$email_account = Mockery::mock( Email_Account_Settings_Interface::class );
		$email_account->expects( 'get_account_email_address' )->andReturn( 'contact@bhwp.ie' );

		$bh_email = $repo->save_new( $email, $mailboxes, $email_account );

		return $bh_email->get_post_id();

		// $post = wp_insert_post(array(
		// 'post_type' => $post_type,
		// 'post_content' => $email_contents,
		// 'post_status' => 'new',
		// 'meta_input' => array(
		// 'email_id' =>
		// )
		// ));
		//
		// return $post;
	}
}
