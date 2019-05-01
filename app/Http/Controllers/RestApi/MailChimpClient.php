<?php

namespace App\Http\Controllers\RestApi;

use \DrewM\MailChimp\MailChimp;

class MailChimpClient {
	private const MC_GET_LIMIT = 100;

	/**
	 * The Mailchimp client object
	 *
	 * @see https://github.com/drewm/mailchimp-api
	 *
	 * @var MailChimp
	 */
	private $client;

	/**
	 * The MailChimp api key
	 *
	 * @var string
	 */
	private $apiKey;

	/**
	 * The list we're working with
	 *
	 * @var string
	 */
	private $listId;

	/**
	 * In memory cache for all subscriber.
	 *
	 * Key: crmId, value: email
	 *
	 * @var array
	 */
	private $subscribers;

	/**
	 * @param string $api_key MailChimp api key
	 *
	 * @throws \Exception
	 */
	public function __construct( string $api_key, string $listId ) {
		$this->apiKey = $api_key;
		$this->listId = $listId;
		$this->client = new MailChimp( $api_key );
	}

	/**
	 * Get subscriber by email
	 *
	 * @param string $email
	 *
	 * @return array|false
	 * @throws \Exception
	 */
	public function getSubscriber( string $email ) {
		$id = self::calculateSubscriberId( $email );

		$get = $this->client->get( "lists/{$this->listId}/members/$id" );

		if ( ! $get ) {
			throw new \Exception( "Get request against Mailchimp failed: {$this->client->getLastError()}" );
		}

		if ( isset( $get['status'] ) && is_numeric( $get['status'] ) && $get['status'] !== 200 ) {
			throw new \Exception( "Get request against Mailchimp failed (status code: {$get['status']}): {$get['detail']}" );
		}

		return $get;
	}

	/**
	 * Return the email address of the first subscriber that has the given value in the given merge tag field.
	 *
	 * Note: This function is really costly, since mailchimp's api doesn't allow to search by merge tag by april 2019.
	 *
	 * @param string $crmId
	 * @param string $crmIdKey
	 *
	 * @return false|string email address on match else false
	 *
	 * @throws \Exception
	 */
	public function getSubscriberEmailByCrmId( string $crmId, string $crmIdKey ) {
		$subscribers = $this->getAllSubscribers( $crmIdKey );

		return array_search( $crmId, $subscribers );
	}

	/**
	 * Return cached array of all mailchimp entries with their email address as key and the crm id as value.
	 *
	 * Note: This function is really costly. We use it since mailchimp's api doesn't allow to search by merge tag by april 2019.
	 *
	 * @param string $crmIdKey
	 *
	 * @return array [email => crmId, ...]
	 *
	 * @throws \Exception
	 */
	private function getAllSubscribers( string $crmIdKey ): array {
		if ( $this->subscribers ) {
			return $this->subscribers;
		}

		$offset = 0;

		while ( true ) {
			$get = $this->client->get( "lists/{$this->listId}/members?count=" . self::MC_GET_LIMIT . "&offset=$offset" );

			if ( ! $get ) {
				throw new \Exception( "Get request against Mailchimp failed: {$this->client->getLastError()}" );
			}

			if ( isset( $get['status'] ) && is_numeric( $get['status'] ) && $get['status'] !== 200 ) {
				throw new \Exception( "Get request against Mailchimp failed (status code: {$get['status']}): {$get['detail']}" );
			}

			if ( 0 === count( $get['members'] ) ) {
				break;
			}

			foreach ( $get['members'] as $member ) {
				$this->subscribers[ $member['email_address'] ] = $member['merge_fields'][ $crmIdKey ];
			}

			$offset += self::MC_GET_LIMIT;
		}

		return $this->subscribers;
	}

	/**
	 * Upsert subscriber
	 *
	 * @param array $mcData
	 *
	 * @return array|false
	 * @throws \Exception
	 * @throws \InvalidArgumentException
	 */
	public function putSubscriber( array $mcData ) {
		if ( empty( $mcData['email_address'] ) ) {
			throw new \InvalidArgumentException( 'Missing email_address.' );
		}

		if ( ! isset( $mcData['status'] ) && ! isset( $mcData['status_if_new'] ) ) {
			$mcData['status_if_new'] = 'subscribed'; // todo: check if we should change it not only if new, if someone unsubscribes and then wished to resubscribe via webling
		}

		$id = self::calculateSubscriberId( $mcData['email_address'] );

		$put = $this->client->put( "lists/{$this->listId}/members/$id", $mcData );

		if ( ! $put ) {
			throw new \Exception( "Put request to Mailchimp failed: {$this->client->getLastError()}" );
		}

		return $put;
	}

	/**
	 * Delete subscriber
	 *
	 * @param string $email
	 *
	 * @throws \Exception
	 */
	public function deleteSubscriber( string $email ) {
		$id     = self::calculateSubscriberId( $email );
		$delete = $this->client->delete( "lists/{$this->listId}/members/$id" );

		if ( ! $delete ) {
			throw new \Exception( "Delete request to Mailchimp failed: {$this->client->getLastError()}" );
		}
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
	public static function calculateSubscriberId( string $email ) {
		$email = trim( $email );
		$email = strtolower( $email );

		return md5( $email );
	}
}
