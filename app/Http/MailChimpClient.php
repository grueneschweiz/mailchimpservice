<?php

namespace App\Http;

use App\Exceptions\InvalidEmailException;
use DrewM\MailChimp\MailChimp;

class MailChimpClient {
	private const MC_GET_LIMIT = 1000;

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
		if ( null !== $this->subscribers ) {
			return $this->subscribers;
		}

		$offset = 0;

		while ( true ) {
			$get = $this->client->get( "lists/{$this->listId}/members?count=" . self::MC_GET_LIMIT . "&offset=$offset", [], 30 );

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

		if ( ! $this->subscribers ) {
			$this->subscribers = [];
		}

		return $this->subscribers;
	}

	/**
	 * Upsert subscriber
	 *
	 * @param array $mcData
	 * @param string $email provide email to update subscribers email address
	 *
	 * @return array|false
	 * @throws \Exception
	 * @throws \InvalidArgumentException
	 * @throws InvalidEmailException
	 */
	public function putSubscriber( array $mcData, string $email = null ) {
		if ( empty( $mcData['email_address'] ) ) {
			throw new \InvalidArgumentException( 'Missing email_address.' );
		}

		if ( ! isset( $mcData['status'] ) && ! isset( $mcData['status_if_new'] ) ) {
			$mcData['status_if_new'] = 'subscribed';
		}

		if ( ! $email ) {
			$email = $mcData['email_address'];
		}

		$id = self::calculateSubscriberId( $email );

		$put = $this->client->put( "lists/{$this->listId}/members/$id", $mcData );

		if ( ! $put ) {
			throw new \Exception( "Put request to Mailchimp failed: {$this->client->getLastError()}" );
		}

		if ( isset( $put['status'] ) && is_numeric( $put['status'] ) && $put['status'] !== 200 ) {
			if ( isset( $put['detail'] ) && strpos( $put['detail'], 'please enter a real email address' ) ) {
				throw new InvalidEmailException( $put['status'] );
			}

			throw new \Exception( "Put request against Mailchimp failed (status code: {$put['status']}): {$put['detail']}" );
		}

		// this is needed for updates
		$this->updateSubscribersTags( $id, $mcData['tags'] );

		return $put;
	}

	/**
	 * Update tags of subscriber
	 *
	 * This must be done extra, because mailchimp doesn't allow to change the tags on subscriber update
	 *
	 * @param string $id
	 * @param array $new the tags the subscriber should have
	 *
	 * @throws \Exception
	 */
	private function updateSubscribersTags( string $id, array $new ) {
		$get = $this->getSubscriberTags( $id );

		$current = array_column( $get['tags'], 'name' );

		$update = [];
		foreach ( $current as $currentTag ) {
			if ( ! in_array( $currentTag, $new ) ) {
				$update[] = (object) [ 'name' => $currentTag, 'status' => 'inactive' ];
			}
		}

		foreach ( $new as $newTag ) {
			if ( ! in_array( $newTag, $current ) ) {
				$update[] = (object) [ 'name' => $newTag, 'status' => 'active' ];
			}
		}

		if ( empty( $update ) ) {
			return;
		}

		$this->postSubscriberTags( $id, [ 'tags' => $update ] );
	}

	/**
	 * Get subscriber tags
	 *
	 * @param string $id
	 *
	 * @return array|false
	 * @throws \Exception
	 */
	private function getSubscriberTags( string $id ) {
		$get = $this->client->get( "lists/{$this->listId}/members/$id/tags" );

		if ( ! $get ) {
			throw new \Exception( "Get tags request against Mailchimp failed: {$this->client->getLastError()}" );
		}

		if ( isset( $get['status'] ) && is_numeric( $get['status'] ) && $get['status'] !== 200 ) {
			throw new \Exception( "Get tags request against Mailchimp failed (status code: {$get['status']}): {$get['detail']}" );
		}

		return $get;
	}

	/**
	 * Update subscriber tags
	 *
	 * @param string $id
	 * @param array $tags the tags that should be activated and the ones that should be deactivated
	 *
	 * @return array|false
	 * @throws \Exception
	 * @see https://developer.mailchimp.com/documentation/mailchimp/reference/lists/members/tags/#%20
	 *
	 */
	private function postSubscriberTags( string $id, array $tags ) {
		$post = $this->client->post( "lists/{$this->listId}/members/$id/tags", $tags );

		if ( ! $post ) {
			throw new \Exception( "Post tags request to Mailchimp failed: {$this->client->getLastError()}" );
		}

		if ( isset( $post['status'] ) && is_numeric( $post['status'] ) && $post['status'] !== 200 ) {
			throw new \Exception( "Post tags request against Mailchimp failed (status code: {$post['status']}): {$post['detail']}" );
		}

		return $post;
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

		if ( isset( $delete['status'] ) && is_numeric( $delete['status'] ) && $delete['status'] !== 204 ) {
			throw new \Exception( "Put request against Mailchimp failed (status code: {$delete['status']}): {$delete['detail']}" );
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
