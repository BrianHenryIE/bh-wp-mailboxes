<?php

namespace BrianHenryIE\WP_Mailboxes;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Fixture;
use Mockery;
use Psr\Log\LoggerInterface;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Psr\Log\Test\TestLogger;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

class WPUnit_Testcase extends WPTestCase {

	/**
	 * Test logger for assertions and console output.
	 *
	 * @var LoggerInterface|TestLogger|ColorLogger
	 */
	protected TestLogger $logger;

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
}
