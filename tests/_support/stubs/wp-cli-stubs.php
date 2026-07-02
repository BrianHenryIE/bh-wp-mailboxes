<?php
/**
 * Minimal WP_CLI stubs for unit tests.
 *
 * `WP_CLI` and `WP_CLI\ExitException` are only present when running under WP-CLI, so the unit suite
 * provides lightweight stand-ins: `error()` throws (to halt like the real command) and `log()` /
 * `success()` record their output for assertions.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace WP_CLI {

	if ( ! class_exists( ExitException::class ) ) {
		/**
		 * Thrown by the WP_CLI::error() stub to halt the command.
		 */
		class ExitException extends \Exception {}
	}

	if ( ! class_exists( Utils::class ) ) {
		/**
		 * Records calls to the WP_CLI\Utils\format_items() stub for assertions.
		 *
		 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		 */
		class Utils {

			/**
			 * Each format_items() call, as array{format:string,items:mixed,fields:mixed}.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public static array $format_items = array();
		}
	}
}

namespace WP_CLI\Utils {

	if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
		/**
		 * Record a format_items() call.
		 *
		 * @param string          $format The output format.
		 * @param mixed           $items  The items to output.
		 * @param string|string[] $fields The fields to output.
		 */
		function format_items( $format, $items, $fields ): void {
			\WP_CLI\Utils::$format_items[] = array(
				'format' => $format,
				'items'  => $items,
				'fields' => $fields,
			);
		}
	}
}

namespace {

	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * Lightweight WP_CLI stub.
		 *
		 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		 */
		class WP_CLI {

			/**
			 * Messages passed to log().
			 *
			 * @var string[]
			 */
			public static array $logs = array();

			/**
			 * Messages passed to success().
			 *
			 * @var string[]
			 */
			public static array $success = array();

			/**
			 * Stubbed command registration.
			 *
			 * @param string                $name     The command name.
			 * @param callable|array|string $callable The command handler.
			 */
			public static function add_command( $name, $callable ): void {}

			/**
			 * Record a log message.
			 *
			 * @param string $message The message.
			 */
			public static function log( $message ): void {
				self::$logs[] = $message;
			}

			/**
			 * Record a success message.
			 *
			 * @param string $message The message.
			 */
			public static function success( $message ): void {
				self::$success[] = $message;
			}

			/**
			 * Halt the command, mirroring WP_CLI::error().
			 *
			 * @param string $message The error message.
			 *
			 * @throws \WP_CLI\ExitException Always.
			 */
			public static function error( $message ): void {
				throw new \WP_CLI\ExitException( (string) $message );
			}
		}
	}
}
