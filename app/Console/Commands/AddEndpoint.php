<?php

namespace App\Console\Commands;

use App\MailchimpEndpoint;

class AddEndpoint extends EndpointCommand {
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'endpoint:add 
                            {config : Filename of the config to use with this endpoint. The config must be in YAML format.}';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Add Mailchimp Endpoint';

	/**
	 * Create a new command instance.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle() {
		$config = $this->argument( 'config' );

		if ( $this->isValidConfig( $config ) ) {
			$this->addEndpoint( $config );

			return 0;
		}

		$this->printConfigErrors();

		return 1;
	}

	/**
	 * Add a mailchimp endpoint
	 *
	 * @param string $configFileName
	 */
	private function addEndpoint( string $configFileName ) {
		$endpoint         = new MailchimpEndpoint();
		$endpoint->config = $configFileName;
		$endpoint->secret = $this->getNewEndpointSecret();
		$endpoint->save();

		$this->info( 'New endpoint created successfully.' );
		$this->line( '<comment>ID:</comment> ' . $endpoint->id );
		$this->line( '<comment>Config file:</comment> ' . $endpoint->config );
		$this->line( '<comment>Endpoint secret:</comment> ' . $endpoint->secret );
		$this->line( '<comment>Endpoint url:</comment> ' . route( self::MC_ENDPOINT_ROUTE_NAME, [ 'secret' => $endpoint->secret ] ) );
	}
}
