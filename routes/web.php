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

$app->get('/', function () use ($app) {
    return $app->version();
});

$app->get('/datadog', 'MetricController@datadog');
$app->get('/info', 'MetricController@info');

$app->get('/intake', 'MetricController@intake');
$app->get('/intake/metadata', 'MetricController@metadata');
$app->get('/intake/metrics', 'MetricController@metrics');
$app->get('/api/v1/series', 'MetricController@series');
$app->get('/api/v1/check_run', 'MetricController@check_run');
$app->get('/status', 'MetricController@status');