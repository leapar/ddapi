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

/*$app->options('htapmsys.com:800',function(){
    echo '123';
});*/

$app->get('/', function () use ($app) {
    return $app->version();
});

$app->get('/info', function () use ($app) {
    echo phpinfo();
});


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


$app->get('test','ApiController@test');

$app->group(['prefix' => 'p1','namespace'=>'App\Http\Controllers'], function () use ($app) {
    $app->get('metric_types/normal_mode_list','ApiController@normalModeList');
    $app->get('metric_types','ApiController@metricTypes');
    $app->post('metric_types/update','ApiController@metricTypesUpdate');
    $app->get('dashboards.json','ApiController@dashboardsJson');
    $app->get('dashboards/{dasbid}/show.json','ApiController@showJson');
    $app->get('dashboards/{dasbid}/charts.json','ApiController@chartsJson');
    $app->post('dashboards/{dasbid}/charts/add.json','ApiController@addJson');
    $app->post('dashboards/{dasbid}/charts/{chartid}/update.json','ApiController@updateChart');
    $app->post('dashboards/{dasbid}/charts/{chartid}/delete.json','ApiController@deleteChart');
    $app->post('dashboards/{dasbid}/update.json','ApiController@updateDasb');
    $app->post('dashboards/{dasbid}/clone.json','ApiController@cloneDasb');
    $app->post('dashboards/{dasbid}/delete.json','ApiController@deleteDasb');

    $app->post('dashboards/addMore.json','ApiController@addMore');
    $app->post('dashboards/{dasbid}/charts/batchAdd.json','ApiController@batchAdd');
    $app->post('metric_templates/add.json','ApiController@templateAdd');
    $app->post('metric_templates/update.json','ApiController@templateUpdate');
    $app->post('metric_templates/{id}/delete.json','ApiController@templateDel');
    $app->get('metric_templates/list.json','ApiController@templateList');
});

$app->group(['prefix' => 'p1','namespace'=>'App\Http\Controllers'], function () use ($app) {
    $app->get('metrics.json', 'ApiPlusController@metricsJson');
    $app->get('tags.json', 'ApiPlusController@tagJson');
});

$app->group(['prefix' => 'p0','namespace'=>'App\Http\Controllers'], function () use ($app) {
    $app->get('metrics.json','ApiController@metricsJson');
    $app->get('tags.json','ApiController@tagJson');
});

