<?php


namespace App\Synchronizer;


use App\Exceptions\ConfigException;
use App\Http\Controllers\RestApi\CrmClient;
use App\Http\Controllers\RestApi\MailChimpClient;
use App\Mail\WrongSubscription;
use App\Synchronizer\Mapper\FieldMaps\FieldMapGroup;
use App\Synchronizer\Mapper\Mapper;
use GuzzleHttp\Exception\RequestException;
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

		$crmCred = $config->getCrmCredentials();

		$this->crmClient = new CrmClient( $crmCred['clientId'], $crmCred['clientSecret'], $crmCred['url'] );
	}

	/**
	 * Sync single record from mailchimp to the crm. Usually called via mailchimp webhook.
	 *
	 * @param array $mcData
	 *
	 * @throws \App\Exceptions\ConfigException
	 * @throws \App\Exceptions\ParseMailchimpDataException
	 * @throws RequestException
	 * @throws \App\Exceptions\ParseCrmDataException
	 * @throws \Exception
	 */
	public function syncSingle( array $mcData ) {
		$mapper  = new Mapper( $this->config->getFieldMaps() );
		$crmData = $mapper->mailchimpToCrm( $mcData );

		$recordId = MailChimpClient::calculateSubscriberId( $mcData['data']['email'] );

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
				$get     = $this->crmClient->get( '/member/' . $crmData['id'] );
				$crmData = json_decode( (string) $get->getBody() );
				$crmData = $this->unsubscribeAll( $crmData );
				break;

			case self::MC_CLEANED_EMAIL:
				// set email1 to invalid
				// add note 'email set to invalid because it bounced in mailchimp'
				$get                     = $this->crmClient->get( '/member/' . $crmData['id'] );
				$crmData                 = json_decode( (string) $get->getBody() );
				$crmData['emailStatus']  = 'invalid';
				$crmData['notesCountry'] .= sprintf( "\n%s: Mailchimp reported the email as invalid. Email status changed.", date( 'Y-m-d H:i' ) );
				break;

			case self::MC_PROFILE_UPDATE:
				// get contact from mailchimp (so we have the interessts (groups) in a usable format)
				// update email1, subscriptions
				$mailchimpClient = new MailChimpClient( $this->config->getMailchimpCredentials()['apikey'], $this->config->getMailchimpListId() );
				$mcData          = $mailchimpClient->getSubscriber( $mcData['data']['email'] );
				$crmData         = $mapper->mailchimpToCrm( $mcData );
				break;

			case self::MC_EMAIL_UPDATE:
				// update email1
				$crmData = $this->updateEmail( $mcData );
				break;

			default:
				// log: this type is not supported
				Log::notice( sprintf(
					"%s was called with an undefined webhook event: %s",
					__METHOD__,
					$mcData['type']
				) );
		}

		$this->crmClient->put( '/member/' . $crmData['id'], $crmData );

		Log::debug( sprintf(
			"Sync successful (record id: %d)",
			$recordId
		) );
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

	/**
	 * Return $crmData with all subscriptions disabled
	 *
	 * @param array $crmData
	 *
	 * @return array in crmData format
	 *
	 * @throws \App\Exceptions\ConfigException
	 * @throws \App\Exceptions\ParseCrmDataException
	 * @throws \App\Exceptions\ParseMailchimpDataException
	 */
	private function unsubscribeAll( array $crmData ): array {
		$mapper = new Mapper( $this->config->getFieldMaps() );
		$mcData = $mapper->crmToMailchimp( $crmData );

		foreach ( $mcData[ FieldMapGroup::MAILCHIMP_PARENT_KEY ] as $key => $value ) {
			$mcData[ FieldMapGroup::MAILCHIMP_PARENT_KEY ][ $key ] = false;
		}

		return $mapper->mailchimpToCrm( $mcData );
	}

	/**
	 * Update the email field in $crmData according to the email in $mcData
	 *
	 * @param array $mcData
	 *
	 * @return array in crmData format with email only
	 *
	 * @throws \App\Exceptions\ConfigException
	 * @throws \App\Exceptions\ParseMailchimpDataException
	 */
	private function updateEmail( array $mcData ): array {
		foreach ( $this->config->getFieldMaps() as $map ) {
			if ( $map->isEmail() ) {
				$map->addMailchimpData( $mcData );

				return $map->getCrmDataArray();
			}
		}

		throw new ConfigException( 'No field of type "email"' );
	}
}