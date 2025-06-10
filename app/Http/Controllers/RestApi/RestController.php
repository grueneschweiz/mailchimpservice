<?php

namespace App\Http\Controllers\RestApi;

use App\MailchimpEndpoint;
use App\Synchronizer\MailchimpToCrmSynchronizer;
use App\Synchronizer\WebsiteToMailchimpSynchronizer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\MailchimpClientException;
use App\Exceptions\EmailComplianceException;
use App\Exceptions\InvalidEmailException;
use App\Exceptions\MemberDeleteException;
use App\Exceptions\ConfigException;
use App\Exceptions\ParseCrmDataException;

class RestController
{
    public function handlePost(Request $request, string $secret)
    {
        /** @var MailchimpEndpoint|null $endpoint */
        $endpoint = MailchimpEndpoint::where('secret', $secret)->first();
        
        if (!$endpoint) {
            abort(401, 'Invalid secret.');
        }
        
        $sync = new MailchimpToCrmSynchronizer($endpoint->config);
        $sync->syncSingle($request->post());
    }
    
    /**
     * To add a webhook in Mailchimp, Mailchimp must be able to make a successful GET request to the given address.
     *
     * @param string $secret
     */
    public function handleGet(string $secret)
    {
        /** @var MailchimpEndpoint|null $endpoint */
        $endpoint = MailchimpEndpoint::where('secret', $secret)->first();
        
        if (!$endpoint) {
            abort(401, 'Invalid secret.');
        }
    }

    /**
     * Add a new contact to Mailchimp using CRM data
     *
     * @param Request $request
     * @param string $secret
     */
    public function addContact(Request $request, string $secret)
    {
        /** @var MailchimpEndpoint|null $endpoint */
        $endpoint = MailchimpEndpoint::where('secret', $secret)->first();

        if (!$endpoint) {
            abort(401, 'Invalid secret.');
        }

        $sync = new WebsiteToMailchimpSynchronizer($endpoint->config);
        $sync->syncSingle($request->post());
    }
}
