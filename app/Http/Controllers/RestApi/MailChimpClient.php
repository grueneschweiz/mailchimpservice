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

		if ( isset( $get['status'] ) && is_numeric($get['status']) && $get['status'] !== 200 ) {
			throw new \Exception( "Get request against Mailchimp failed (status code: {$get['status']}): {$get['detail']}" );
		}

		return $get;
	}

	/**
	 * Return the email address of the first subscriber that has the given value in the given merge tag field.
	 *
	 * Note: This function is really costly, since mailchimp's api doesn't allow to search by merge tag by april 2019.
	 *
	 * @param string $value
	 * @param string $mergeFieldKey
	 *
	 * @return false|string email address on match else false
	 *
	 * @throws \Exception
	 */
	public function getSubscriberEmailByMergeField( string $value, string $mergeFieldKey ) {
		$offset = 0;

		// yes by april 2019, there is no better way to do that
		// because mailchimp doesn't let us search by merge tag
		while ( true ) {
			$get = $this->client->get( "lists/{$this->listId}/members?count=" . self::MC_GET_LIMIT . "&offset=$offset" );

			if ( ! $get ) {
				throw new \Exception( "Get request against Mailchimp failed: {$this->client->getLastError()}" );
			}

			if ( isset( $get['status'] ) ) {
				throw new \Exception( "Get request against Mailchimp failed (status code: {$get['status']}): {$get['detail']}" );
			}

			if ( 0 === count( $get['members'] ) ) {
				return false;
			}

			foreach ( $get['members'] as $member ) {
				if ( $value === $member['merge_fields'][ $mergeFieldKey ] ) {
					return $member['email_address'];
				}
			}

			$offset += self::MC_GET_LIMIT;
		}
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
