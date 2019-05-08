<?php


namespace App\Synchronizer;


use App\Exceptions\ConfigException;
use App\Http\CrmClient;
use App\Http\MailChimpClient;
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
	 * @var string
	 */
	private $configName;

	/**
	 * @var CrmClient
	 */
	private $crmClient;

	/**
	 * @var MailChimpClient
	 */
	private $mcClient;

	/**
	 * Synchronizer constructor.
	 *
	 * @param Config $config
	 * @param int $userId
	 *
	 * @throws \App\Exceptions\ConfigException
	 * @throws \Exception
	 */
	public function __construct( string $configFileName ) {
		$this->config     = new Config( $configFileName );
		$this->configName = $configFileName;

		$crmCred = $this->config->getCrmCredentials();

		$this->crmClient = new CrmClient( $crmCred['clientId'], $crmCred['clientSecret'], $crmCred['url'] );
		$this->mcClient  = new MailChimpClient( $this->config->getMailchimpCredentials()['apikey'], $this->config->getMailchimpListId() );
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
		$mapper = new Mapper( $this->config->getFieldMaps() );

		$email = isset( $mcData['data']['new_email'] ) ? $mcData['data']['new_email'] : $mcData['data']['email'];

		$callType    = $mcData['type'];
		$mailchimpId = MailChimpClient::calculateSubscriberId( $email );

		Log::debug( sprintf(
			"Sync single record from Mailchimp to CRM\nRecord id: %s\nWebhook event: %s",
			$mailchimpId,
			$mcData['type']
		) );

		switch ( $callType ) {
			case self::MC_SUBSCRIBE:
				// send mail to dataOwner, that he should
				// add the subscriber to webling not mailchimp
				$mcData = $this->mcClient->getSubscriber( $email );
				$this->sendMailSubscribeOnlyInWebling( $this->config->getDataOwner(), $mcData );
				Log::debug( 'MC_SUBSCRIBE: Inform data owner.' );

				return;

			case self::MC_UNSUBSCRIBE:
				// get contact from crm
				// set all subscriptions, that are configured in this config to NO
				$crmId   = $mcData['data']['merges'][ $this->config->getMailchimpKeyOfCrmId() ];
				$get     = $this->crmClient->get( 'member/' . $crmId );
				$crmData = json_decode( (string) $get->getBody(), true );
				$crmData = $this->unsubscribeAll( $crmData );
				Log::debug( 'MC_UNSUBSCRIBE: Unsubscribe member in crm.' );
				break;

			case self::MC_CLEANED_EMAIL:
				// set email1 to invalid
				// add note 'email set to invalid because it bounced in mailchimp'
				$crmId                   = $mcData['data']['merges'][ $this->config->getMailchimpKeyOfCrmId() ];
				$get                     = $this->crmClient->get( 'member/' . $crmId );
				$crmData                 = json_decode( (string) $get->getBody(), true );
				$crmData['emailStatus']  = 'invalid';
				$crmData['notesCountry'] .= sprintf( "\n%s: Mailchimp reported the email as invalid. Email status changed.", date( 'Y-m-d H:i' ) );
				Log::debug( 'MC_CLEANED_EMAIL: Mark email invalid in crm.' );
				break;

			case self::MC_PROFILE_UPDATE:
				// get subscriber from mailchimp (so we have the interessts (groups) in a usable format)
				// update email1, subscriptions
				$mcData  = $this->mcClient->getSubscriber( $email );
				$crmId   = $mcData['merge_fields'][ $this->config->getMailchimpKeyOfCrmId() ];
				$crmData = $mapper->mailchimpToCrm( $mcData );
				Log::debug( 'MC_PROFILE_UPDATE: Update subscriptions in crm.' );
				break;

			case self::MC_EMAIL_UPDATE:
				// update email1
				$mcData  = $this->mcClient->getSubscriber( $email );
				$crmId   = $mcData['merge_fields'][ $this->config->getMailchimpKeyOfCrmId() ];
				$crmData = $this->updateEmail( $mcData );
				Log::debug( 'MC_EMAIL_UPDATE: Update email in crm.' );
				break;

			default:
				// log: this type is not supported
				Log::notice( sprintf(
					"%s was called with an undefined webhook event: %s",
					__METHOD__,
					$mcData['type']
				) );
		}

		$putData = [];
		foreach ( $crmData as $key => $value ) {
			$putData[ $key ] = [ 'value' => $value, 'mode' => 'replace' ];
		}

		$this->crmClient->put( 'member/' . $crmId, $putData );

		Log::debug( sprintf(
			"Sync successful (record id: %d)",
			$mailchimpId
		) );
	}

	/**
	 * Inform data owner that he should only add contact in the crm not in mailchimp
	 *
	 * @param array $dataOwner
	 * @param array $mcData
	 */
	private function sendMailSubscribeOnlyInWebling( array $dataOwner, array $mcData ) {
		$mailData                   = new \stdClass();
		$mailData->dataOwnerName    = $dataOwner['name'];
		$mailData->contactFirstName = $mcData['merge_fields']['FNAME']; // todo: check if we cant get the field keys dynamically
		$mailData->contactLastName  = $mcData['merge_fields']['LNAME']; // todo: dito
		$mailData->contactEmail     = $mcData['email_address'];
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