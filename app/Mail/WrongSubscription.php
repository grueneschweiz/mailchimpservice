<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class WrongSubscription extends Mailable {
	use Queueable, SerializesModels;

	/**
	 * @var Object
	 */
	public $mail;

	/**
	 * Create a new message instance.
	 *
	 * @param Object $mailData make sure the following properties are available
	 * - dataOwnerName
	 * - contactFirstName
	 * - contactLastName
	 * - contactEmail
	 * - adminEmail
	 *
	 * @return void
	 */
	public function __construct( $mailData ) {
		$this->mail = $mailData;
	}

	/**
	 * Build the message.
	 *
	 * @return $this
	 */
	public function build() {
		return $this->replyTo( env( 'ADMIN_EMAIL' ) )
		            ->subject( 'Mailchimp Webling Sync Error' )
		            ->text( 'mails.wrong_subscription_plain' );
	}
}
