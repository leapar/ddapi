<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

phpinfo();die();

$app->get('/ddapi/', function () use ($app) {
    return $app->version();
});

$app->get('/ddapi', function () use ($app) {
    return $app->version();
});

$app->get('/ddapi/intake', 'HomeController@intake');

$app->group(['prefix' => 'ddapi'], function () use ($app) {
	
	$app->get('intake', 'HomeController@intake');
/*
	$app->any('/intake', 'HomeController@intake');
	$app->any('/intake/metadata', 'HomeController@metadata');
	$app->any('/intake/metrics', 'HomeController@metrics');
	$app->any('/api/v1/series', 'HomeController@series');
	$app->any('/api/v1/check_run', 'HomeController@check_run');
	$app->any('/status', 'HomeController@status');*/

});

/*
Route::any('/intake', 'HomeController@intake');
Route::any('/intake/metadata', 'HomeController@metadata');
Route::any('/intake/metrics', 'HomeController@metrics');
Route::any('/api/v1/series', 'HomeController@series');
Route::any('/api/v1/check_run', 'HomeController@check_run');
Route::any('/status', 'HomeController@status');*/