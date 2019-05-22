<?php

namespace Tests\Feature\Http\Controllers\RestApi;


use App\Console\Commands\EndpointCommand;
use App\MailchimpEndpoint;

class EndpointHelper {
	private $endpoint;
	private $config;

	public function __construct( string $configFileName ) {
		$this->config = $configFileName;
	}

	public function get() {
		if ( ! $this->endpoint ) {
			$this->add();
		}

		return route( EndpointCommand::MC_ENDPOINT_ROUTE_NAME, [ 'secret' => $this->endpoint->secret ] );
	}

	private function add() {
		$endpoint         = new MailchimpEndpoint();
		$endpoint->config = $this->config;
		$endpoint->secret = 'asdfghjkl';
		$endpoint->save();

		$this->endpoint = $endpoint;
	}

	public function delete() {
		$this->endpoint->delete();
	}
}