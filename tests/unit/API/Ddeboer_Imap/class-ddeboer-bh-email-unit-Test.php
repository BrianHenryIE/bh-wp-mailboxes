<?php


namespace BrianHenryIE\WP_Mailboxes\API\Ddeboer_Imap;

use BrianHenryIE\WP_Mailboxes\BH_Email;
use Ddeboer\Imap\Message\BasicMessageInterface;
use Ddeboer\Imap\Message\EmailAddress;
use Ddeboer\Imap\Message\Headers;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\Ddeboer_Imap\Ddeboer_BH_Email
 */
class Ddeboer_BH_Email_Unit_Test extends \Codeception\Test\Unit {


	/**
	 * Basic test to parse an email.
	 */
	public function test_happy(): void {

		$ddeboer_email    = $this->makeEmpty(
			BasicMessageInterface::class,
			array(
				'getId'       => '<CAHHEuQd+-K=UQmg_zNR_Y+NBT8qL3ctHWdzAWOJb7egaK4E-Qg@mail.gmail.com>',
				'getHeaders'  => new Headers(
					(object) array(
						'date'            => 'Mon, 20 Dec 2021 22:15:47 -0800',
						'subject'         => 'Test',
						'message_id'      => '<CAHHEuQd+-K=UQmg_zNR_Y+NBT8qL3ctHWdzAWOJb7egaK4E-Qg@mail.gmail.com>',
						'toaddress'       => 'support@brianhenryie.com',
						'to'              =>
						array(
							0 =>
								(object) array(
									'mailbox'  => 'support',
									'host'     => 'brianhenryie.com',
									'personal' => null,
								),
						),
						'fromaddress'     => 'Brian Henry <brianhenryie@gmail.com>',
						'from'            =>
						array(
							0 =>
								(object) array(
									'personal' => 'Brian Henry',
									'mailbox'  => 'brianhenryie',
									'host'     => 'gmail.com',
								),
						),
						'reply_toaddress' => 'Brian Henry <brianhenryie@gmail.com>',
						'reply_to'        =>
						array(
							0 =>
								(object) array(
									'personal' => 'Brian Henry',
									'mailbox'  => 'brianhenryie',
									'host'     => 'gmail.com',
								),
						),
						'senderaddress'   => 'Brian Henry <brianhenryie@gmail.com>',
						'sender'          =>
						array(
							0 =>
								(object) array(
									'personal' => 'Brian Henry',
									'mailbox'  => 'brianhenryie',
									'host'     => 'gmail.com',
								),
						),
						'recent'          => ' ',
						'unseen'          => ' ',
						'flagged'         => ' ',
						'answered'        => ' ',
						'deleted'         => ' ',
						'draft'           => ' ',
						'msgno'           => '1',
						'maildate'        => '21-Dec-2021 01:16:08 -0500',
						'size'            => '6346',
						'udate'           => 1640067368,
					)
				),
				'getFrom'     => new EmailAddress( 'brianhenryie', 'gmail.com', 'Brian Henry' ),
				'getSubject'  => 'Test',
				'getBodyText' => 'body text',
				'getBodyHtml' => 'body html',
			)
		);
		$this->from_email = $ddeboer_email->getFrom()->getAddress();
		$this->from_name  = $ddeboer_email->getFrom()->getName();

		$cpt                      = 'test';
		$mailbox_category_term_id = 123;

		$result = new \BrianHenryIE\WP_Mailboxes\API\Ddeboer_Imap\Ddeboer_BH_Email( $ddeboer_email, $cpt, $mailbox_category_term_id );

		$this->assertInstanceOf( BH_Email::class, $result );
	}
}
