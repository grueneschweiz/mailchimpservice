<?php

namespace App\Http\Controllers\RestApi;

use App\Http\Controllers\RestApi\MailChimpWrapper as MailChimpWrapper;

use Illuminate\Http\Request;
use App\Exceptions\IllegalArgumentException;

class RestController
{

  public function getLists() {
    $MailChimpClient = MailChimpClient::getClient();

    $result = $MailChimpClient->getLists();

    return json_encode($result);
  }

  public function getList($list_id) {
    $MailChimpClient = MailChimpClient::getClient();

    $result = $MailChimpClient->getList($list_id);

    return json_encode($result);
  }

  public function put(Request $request) {
    $mailchimp_api_key=$request->header($key = 'mailchimp_key');
    $MailChimpClient = MailChimpClient::getClient($mailchimp_api_key);

    $result = json_decode($request->getContent(), true);

    $email = $result['EMAIL'];
    if (!$email) {
      throw new IllegalArgumentException("Input \"EMAIL\" must be given in the JSON.");
    }
    $returnArray = [];
    $returnArray['EMAIL'] = $email;
    $returnArray['LNAME'] = $result['LNAME'];
    $returnArray['FNAME'] = $result['FNAME'];
    // $returnArray['GENDER'] = $result['GENDER'];
    // $returnArray['CANTON'] = $result['CANTON'];
    // $returnArray['MEMBER'] = $result['MEMBER'];
    // $returnArray['JOURNALIST'] = $result['JOURNALIST'];
    // $returnArray['NOTES'] = $result['NOTES'];

    // TODO create call to mailchimp based on the information in the request (in MailChimpClient)

    // TODO Define what should be the result

    // for the time being, just return the information in the request
    return json_encode($returnArray);
  }

//From specification
  // Mergetag	Required	Visible	Default	Options	Comment
  // EMAIL	1	1
  // FNAME	0	1			first name
  // LNAME	0	1			last name
  // GENDER	0	1		m, f, n, m+f	n=neutral; m+f is a legacy from webling
  // CANTON	0	0			Kürzel
  // -- WEBLINGID	0	0			Used for synchronization. If it has to be required is TBD
  // MEMBER	0	0		yes, no
  // JOURNALIST	0	0		yes, no
  // --DONOR	0	0		null, donor, sponsor, patron	May be its better to have just yes and no. TBD
  // -- ENGAGEMENTS	0	0			TBD if there should be a value mapping or if we use the values from webling. Feld: Anfragen für
  // --CONCERNS	0	0			TBD if there should be a value mapping or if we use the values from webling. Feld: Interessen
  // NOTES	0	0			The mapped field from webling may change according to the client (Notizen national, Notizen Kanton etc)

}
