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
private $mailchimp_client;

/**
 * The MailChimp api key
 *
 * @var string
 */
private $api_key;

/**
 * @param string $api_key MailChimp api key
 */
private function __construct( string $api_key) {
  $this->api_key = $api_key;
  $this->mailchimp_client = new MailChimp($api_key);
}

  /**
  * Creates a MailChimpWrapper to deal with Member entities.
  *
  * @param string [optional] the mailchimp key
  * @return MailChimpClient
  */
  public static function getClient(String $api_key = null) {
    if (!$api_key) {
      $api_key = config('app.mailchimp_api_key');// default on server
    }
    return new MailChimpClient($api_key);
  }

  public function getLists() {
    return $this->mailchimp_client->get('lists');
  }

}
