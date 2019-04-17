<?php


namespace App\Synchronizer;


use App\Mail\WrongSubscription;
use App\Synchronizer\Mapper\Mapper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailchimpToCrmSynchronizer {
	/**
	 * Mailchimp webhook event types
	 *
	 * @see https://developer.mailchimp.com/documentation/mailchimp/guides/about-webhooks/
	 */
	private const MC_EMAIL_UPDATE = 'upemail';
	private const MC_CLEANED_EMAIL = 'cleaned';
	private const MC_SUBSCRIBE = 'subscribe';
	private const MC_UNSUBSCRIBE = 'unsubscribe';
	private const MC_PROFILE_UPDATE = 'profile';

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var int
	 */
	private $userId;

	/**
	 * @var CrmClient
	 */
	private $crmClient;

	/**
	 * Synchronizer constructor.
	 *
	 * @param Config $config
	 * @param int $userId
	 *
	 * @throws \App\Exceptions\ConfigException
	 */
	public function __construct( Config $config, int $userId ) {
		$this->config = $config;
		$this->userId = $userId;

		$this->crmClient = new CrmClient( $config->getCrmCredentials() );
	}

	/**
	 * Sync single record from mailchimp to the crm. Usually called via mailchimp webhook.
	 *
	 * @param array $mcData
	 *
	 * @throws \App\Exceptions\ConfigException
	 * @throws \App\Exceptions\ParseMailchimpDataException
	 */
	public function syncSingle( array $mcData ) {
		$mapper  = new Mapper( $this->config->getFieldMaps() );
		$crmData = $mapper->mailchimpToCrm( $mcData );

		$recordId = $this->calculateMailchimpsContactId( $mcData['data']['email'] );

		Log::debug( sprintf(
			"Sync single record from Mailchimp to CRM\nRecord id: %s\nWebhook event: %s",
			$recordId,
			$mcData['type']
		) );

		switch ( $mcData['type'] ) {
			case self::MC_SUBSCRIBE:
				// send mail to dataOwner, that he should
				// add the subscriber to webling not mailchimp
				$this->sendMailSubscribeOnlyInWebling( $this->config->getDataOwner(), $crmData );

				return;

			case self::MC_UNSUBSCRIBE:
				// get contact from crm
				// set all subscriptions, that are configured in this config to NO
				$crmData = $this->crmClient->get( $crmData['id'] );
				$crmData = $this->unsubscribeAll( $crmData );
				break;

			case self::MC_CLEANED_EMAIL:
				// set email1to invalid
				// add note 'email set to invalid because it bounced in mailchimp'
				$crmData                 = $this->crmClient->get( $crmData['id'] );
				$crmData['emailStatus']  = 'invalid';
				$crmData['notesCountry'] .= sprintf( "\n%s: Mailchimp reported the email as invalid. Email status changed.", date( 'Y-m-d H:i' ) );
				break;

			case self::MC_PROFILE_UPDATE:
				// get contact from mailchimp (so we have the interessts (groups) in a usable format)
				// update email1, subscriptions
				$mailchimpClient = new MailChimpClient( $this->config->getMailchimpCredentials() );
				$mcData          = $mailchimpClient->get( $recordId );
				$crmData         = $mapper->mailchimpToCrm( $mcData );
				break;

			case self::MC_EMAIL_UPDATE:
				// update email1
				$crmData = $this->crmClient->get( $crmData['id'] );
				$crmData = $this->updateEmail( $mcData, $crmData );
				break;

			default:
				// log: this type is not supported
				Log::notice( sprintf(
					"%s was called with an undefined webhook event: %s",
					__METHOD__,
					$mcData['type']
				) );
		}

		$this->crmClient->put( $crmData );

		Log::debug( sprintf(
			"Sync successful (record id: %d)",
			$recordId
		) );
	}

	/**
	 * Calculate the id of the contact in mailchimp
	 *
	 * @see https://developer.mailchimp.com/documentation/mailchimp/guides/manage-subscribers-with-the-mailchimp-api/
	 *
	 * @param string $email
	 *
	 * @return string MD5 hash of the lowercase email address
	 */
	private function calculateMailchimpsContactId( string $email ) {
		$email = trim( $email );
		$email = strtolower( $email );

		return md5( $email );
	}

	/**
	 * Inform data owner that he should only add contact in the crm not in mailchimp
	 *
	 * @param array $dataOwner
	 * @param array $crmData
	 */
	private function sendMailSubscribeOnlyInWebling( array $dataOwner, array $crmData ) {
		$mailData                   = new \stdClass();
		$mailData->dataOwnerName    = $dataOwner['name'];
		$mailData->contactFirstName = $crmData['firstName'];
		$mailData->contactLastName  = $crmData['lastName'];
		$mailData->contactEmail     = $crmData['email1'];
		$mailData->adminEmail       = env( 'ADMIN_EMAIL' );

		Mail::to( $dataOwner['email'] )
		    ->send( new WrongSubscription( $mailData ) );
	}
}