<?php

use App\Console\Commands\EndpointCommand;
use App\Http\Controllers\RestApi\RestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
Route::group( [ 'prefix' => 'v1', 'middleware' => [ 'api' ] ], function () {
	/*
	|--------------------------------------------------------------------------
	| Member Resources
	|--------------------------------------------------------------------------
	*/
	Route::group( [ 'prefix' => 'mailchimp' ], function () {
		Route::post( 'webhook/{secret}', function ( Request $request, string $secret ) {
			$controller = new RestController();
			$controller->handlePost( $request, $secret );

			return response( '', 204 );
		} )->name( EndpointCommand::MC_ENDPOINT_ROUTE_NAME );

		Route::get( 'webhook/{secret}', function ( Request $request, string $secret ) {
			$controller = new RestController();
			$controller->handleGet( $secret );

			return response( '', 204 );
		} )->name( EndpointCommand::MC_ENDPOINT_ROUTE_NAME );
	} );
} );