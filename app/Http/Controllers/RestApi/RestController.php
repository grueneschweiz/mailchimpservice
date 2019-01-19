<?php

namespace App\Http\Controllers\RestApi;
use App\Http\Controllers\RestApi\MailChimpWrapper as MailChimpWrapper;

class RestController
{

  public function getLists() {
    $MailChimpClient = MailChimpClient::getClient();

    $result = $MailChimpClient->getLists();

    return json_encode($result);
  }

}
