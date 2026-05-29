<?php

namespace BrianHenryIE\WP_Mailboxes;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\BH_Email
 */
class BH_Email_Unit_Test extends \Codeception\Test\Unit {

	/**
	 */
	public function test_static_from_array(): void {

		$this->markTestSkipped( 'This method was moved to Gmail_BH_Email' );

		$arr = array(
			'cpt'                 => 'my-plugin-emails',
			'email_id'            => '<CAHHEuQd+-K=UQmg_zNR_Y+NBT8qL3ctHWdzAWOJb7egaK4E-Qg@mail.gmail.com>',
			'headers'             =>
				array(
					'date'            => 'Mon, 20 Dec 2021 22:15:47 -0800',
					'subject'         => 'Test',
					'message_id'      => '<CAHHEuQd+-K=UQmg_zNR_Y+NBT8qL3ctHWdzAWOJb7egaK4E-Qg@mail.gmail.com>',
					'toaddress'       => 'support@brianhenryie.com',
					'fromaddress'     => 'Brian Henry <brianhenryie@gmail.com>',
					'reply_toaddress' => 'Brian Henry <brianhenryie@gmail.com>',
					'senderaddress'   => 'Brian Henry <brianhenryie@gmail.com>',
					'msgno'           => 1,
					'maildate'        => '21-Dec-2021 01:16:08 -0500',
					'size'            => '6346',
					'udate'           => 1640067368,
				),
			'from_email'          => 'brianhenryie@gmail.com',
			'from_name'           => 'Brian Henry',
			'subject'             => 'Test',
			'meta_data'           => array(),
			'body_text'           => 'email


-- 
+1-628-241-5573
*BrianHenry.ie* <http://www.brianhenry.ie>
',
			'body_html'           => '<div dir="ltr"><table id="gmail-mailbox_routes" class="gmail-multirow gmail-table gmail-tablesorter" width="100%"><tbody><tr class="entry"><td id="email_7" class="gmail-user gmail-highlight_over">email<br></td><td id="gmail-domain_7" class="gmail-domain gmail-highlight_over"><br></td></tr></tbody></table><br clear="all"><br>-- <br><div dir="ltr" class="gmail_signature" data-smartmail="gmail_signature"><div dir="ltr"><div><div dir="ltr"><div><div dir="ltr">+1-628-241-5573<div><a href="http://www.brianhenry.ie" target="_blank"><i>BrianHenry.ie</i></a></div></div></div></div></div></div></div></div>
',
			'account_category_id' => 2,
		);

		$result = \BrianHenryIE\WP_Mailboxes\BH_Email::create_from_array( $arr );

		$this->assertEquals( 'brianhenryie@gmail.com', $result->get_from_email() );
	}
}
