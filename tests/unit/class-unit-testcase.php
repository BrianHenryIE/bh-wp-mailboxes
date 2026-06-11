<?php

namespace BrianHenryIE\WP_Mailboxes;

use BrianHenryIE\ColorLogger\ColorLogger;
use Psr\Log\LoggerInterface;
use Codeception\Test\Unit;
use Psr\Log\Test\TestLogger;
use WP_Mock;
use function Patchwork\restoreAll;

class Unit_Testcase extends Unit {

	/**
	 * @var TestLogger|ColorLogger Enables asserting log messages in tests.
	 */
	protected TestLogger $logger;

	protected function setup(): void {
		parent::setup();

		WP_Mock::setUsePatchwork( true );
		WP_Mock::setUp();

		WP_Mock::passthruFunction( 'sanitize_title' );
		WP_Mock::passthruFunction( 'sanitize_key' );

		$this->logger = new ColorLogger();

		\Patchwork\redefine(
			'constant',
			fn( string $constant_name ) => 'YEAR_IN_SECONDS' === $constant_name
					? 60 * 60 * 365
					: \Patchwork\relay( func_get_args() )
		);
	}

	#[\Override]
	protected function tearDown(): void {
		parent::_tearDown();
		WP_Mock::tearDown();
		restoreAll();
	}
}
