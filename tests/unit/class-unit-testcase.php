<?php

namespace BrianHenryIE\WP_Mailboxes;

use BrianHenryIE\ColorLogger\ColorLogger;
use Psr\Log\LoggerInterface;
use Codeception\Test\Unit;
use WP_Mock;
use function Patchwork\restoreAll;

class Unit_Testcase extends Unit {

	protected LoggerInterface $logger;

	protected function setup(): void {
		parent::setup();

		WP_Mock::setUsePatchwork( true );
		WP_Mock::setUp();

		WP_Mock::passthruFunction( 'sanitize_title' );

		$this->logger = new ColorLogger();

		// YEAR_IN_SECONDS
		\Patchwork\redefine(
			'constant',
			function ( string $constant_name ) {
				return 'YEAR_IN_SECONDS' === $constant_name
					? 60 * 60 * 365
					: \Patchwork\relay( func_get_args() );
			}
		);
	}

	protected function tearDown(): void {
		parent::_tearDown();
		WP_Mock::tearDown();
		restoreAll();
	}
}
