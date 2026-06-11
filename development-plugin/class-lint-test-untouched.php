<?php
/**
 * Task 1.5 test artifact — intentional lint errors in a file NOT touched by the test PR.
 * Delete after task 1.5 validation is complete.
 *
 * phpcs violations: bad variable name (phpcbf cannot fix naming).
 * phpstan violation: return type mismatch.
 */

// phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- test artifact, not a real class file.
namespace BrianHenryIE\WP_Mailboxes_Development_Plugin;

$BadVariableName = 'lint-test-untouched'; // phpcs: Squiz.NamingConventions.ValidVariableName.NotCamelCaps

/**
 * Intentional phpstan return-type mismatch — do not fix.
 *
 * @return int
 */
function lint_test_untouched_returns_wrong_type(): int {
	return 'this is a string, not an int';
}
