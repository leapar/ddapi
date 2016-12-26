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
$app->get('/info', 'MetricController@info');


$app->post('/datadog', 'MetricController@datadog');
$app->post('/intake', 'MetricController@intake');
$app->post('/infrastructure/metrics', 'MetricController@intake');
$app->post('/intake/metadata', 'MetricController@metadata');
$app->post('/intake/metrics', 'MetricController@metrics');
$app->post('/api/v1/series', 'MetricController@series');
$app->post('/api/v1/check_run', 'MetricController@check_run');
$app->post('/status', 'MetricController@status');

$app->get('get_user_data','RedisController@user');
$app->get('get_node_host','RedisController@nodeHost');
