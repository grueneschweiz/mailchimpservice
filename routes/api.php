<?php

use Illuminate\Http\Request;
use App\Http\Controllers\RestApi\RestController as RestController;

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

Route::get('lists', function (Request $request) {
    $controller = new RestController();
    return $controller->getLists();
});
