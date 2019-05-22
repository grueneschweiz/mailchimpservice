<?php

namespace App\Console\Commands;

use App\MailchimpEndpoint;
use Illuminate\Console\Command;

class ListEndpoint extends Command {
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'endpoint:list';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'List Mailchimp Endpoints';

	/**
	 * Create a new command instance.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 * Print a table with all mailchimp endpoints
	 *
	 * @return mixed
	 */
	public function handle() {
		$data = MailchimpEndpoint::all()->toArray();

		$headers = [ 'ID', 'Endpoint Secret', 'Config Name', 'Created', 'Updated' ];

		$this->table( $headers, $data );

		return 0;
	}
}
