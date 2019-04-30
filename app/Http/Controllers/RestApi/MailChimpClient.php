<?php

namespace App\Http\Controllers\RestApi;

use \DrewM\MailChimp\MailChimp;

class MailChimpClient {

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

		return $get;
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

		$id = self::calculateSubscriberId( $mcData['email_address'] );

		$put = $this->client->put( "lists/{$this->listId}/members/$id", $mcData );

		if ( ! $put ) {
			throw new \Exception( "Put request to Mailchimp failed: {$this->client->getLastError()}" );
		}

		return $put;
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
