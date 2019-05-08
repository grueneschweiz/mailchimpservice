<?php


namespace App\Console\Commands;


use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SyncTest extends TestCase {
	public function testSyncAll() {
		Artisan::call( 'sync:all', [
			'direction' => 'toMailchimp',
			'config'    => 'gruenetest.yml',
			//'verbosity' => '-v',
		] );
	}
}