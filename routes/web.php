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

$app->post('/datadog', 'MetricController@datadog');
$app->get('/info', 'MetricController@info');

$app->post('/intake', 'MetricController@intake');
$app->post('/infrastructure/metrics', 'MetricController@intake');

$app->post('/intake/metadata', 'MetricController@metadata');
$app->post('/intake/metrics', 'MetricController@metrics');
$app->post('/api/v1/series', 'MetricController@series');
$app->post('/api/v1/check_run', 'MetricController@check_run');
$app->post('/status', 'MetricController@status');

$app->get('/test',function(){

    $sub = new \stdClass();
    $sub->metric = "metric";
    $sub->timestamp = "timestamp";
    $sub->value = "value";
    $sub->tags = new \stdClass();//$metric[3];
    $sub->tags->host = "hostname";
    $sub->tags->uid = "uid";//1;//$metrics_in->uuid;

    $sub->tags->device = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\-\/]/u","","dis1");//str_replace(array("{",":","*","}"), "_", $tag->device_name);//$tag->device_name;

    $sub->tags->xxx="xxx";


    $tags = (array)$sub->tags;

    foreach($tags as $key => $tag){

        var_dump($key . "=>" .$tag);
        echo "<br>";
    }

});