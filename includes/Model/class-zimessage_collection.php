<?php
/**
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Model;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;
use ZBateson\MailMimeParser\IMessage;

/**
 * Strongly-typed collection of parsed MIME messages.
 *
 * @implements IteratorAggregate<int, IMessage>
 */
class ZImessage_Collection implements Countable, IteratorAggregate {

	/** @var IMessage[] */
	protected array $messages = array();

	public function add( IMessage $message ): void {
		$this->messages[] = $message;
	}

	public function get( int $index ): IMessage {
		return $this->messages[ $index ];
	}

	public function count(): int {
		return count( $this->messages );
	}

	/** @return ArrayIterator<int, IMessage> */
	public function getIterator(): Traversable {
		return new ArrayIterator( $this->messages );
	}
}
