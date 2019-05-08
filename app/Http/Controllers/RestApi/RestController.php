<?php

namespace App\Http\Controllers\RestApi;

use App\MailchimpEndpoint;
use App\Synchronizer\MailchimpToCrmSynchronizer;
use Illuminate\Http\Request;

class RestController {
	public function handlePost( Request $request, string $secret ) {
		/** @var MailchimpEndpoint|null $endpoint */
		$endpoint = MailchimpEndpoint::where( 'secret', $secret )->first();

		if ( ! $endpoint ) {
			abort( 401, 'Invalid secret.' );
		}

		$sync = new MailchimpToCrmSynchronizer( $endpoint->config );
		$sync->syncSingle( $request->post() );
	}

	public function handleGet( string $secret ) {
		/** @var MailchimpEndpoint|null $endpoint */
		$endpoint = MailchimpEndpoint::where( 'secret', $secret )->first();

		if ( ! $endpoint ) {
			abort( 401, 'Invalid secret.' );
		}
	}
}
