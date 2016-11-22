<?php

namespace App\Http\Controllers;

use App\Jobs\CheckJob;
use App\Jobs\HostJob;
use App\Jobs\MetricJob;
use App\Jobs\TagJob;
use Illuminate\Http\Request;
use Log;
use DB;
use Cache;
use App\Tag;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use App\MyClass\Metric;
use Mockery\CountValidator\Exception;

class MetricController extends Controller
{
    public function datadog(Request $request) {
        Log::info(json_encode($request));
    }

    public function intake(Request $request)
    {
        try{
            $data = file_get_contents('php://input');
            $data = zlib_decode($data);
            $metrics_in = \GuzzleHttp\json_decode($data);

            $host = $metrics_in->internalHostname;
            $metrics = $metrics_in->metrics;

            //Log::info("header===".$request->header('X-Consumer-Custom-ID'));
            //Log::info("header===".$request->header('X-Consumer-Username'));
            //$custom_id = $request->header('X-Consumer-Custom-ID');
            $custom_id = "088DBF7B54EFBA3CA599B3543C73EA1C"; //test

            //Log::info("intake===".$data);
            //exit();

            $my_metric = new Metric($metrics_in,$host,$custom_id);

            //1，保存 opentsdb
            $arrPost = array();
            $arrPost = $my_metric->getMetricByOS($arrPost);

            $cpuIdle = isset($metrics_in->cpuIdle) ? $metrics_in->cpuIdle : "null";
            $disk_total = 0;
            $disk_used = 0;
            if(!empty($metrics)) {
                foreach($metrics as $metric) {
                    $sub = $my_metric->getMetric($metric);
                    if($metric[0] == "system.cpu.idle"){
                        $cpuIdle = $metric[2];
                    }
                    if($metric[0] == "system.disk.total"){
                        $disk_total += $metric[2];
                    }
                    if($metric[0] == "system.disk.used"){
                        $disk_used += $metric[2];
                    }
                    array_push($arrPost ,$sub);
                    $arrPost = $my_metric->checkarrPost($arrPost);
                }
            }

            if(count($arrPost) > 0) {
                $my_metric->post2tsdb($arrPost);
                $arrPost = array();
            }

            $tags = $my_metric->getTags();

            //2、保存 mysql host表,host_user
            $hostjob = (new HostJob($metrics_in,$custom_id,$cpuIdle,$disk_total,$disk_used))->onQueue("host");
            //$hostjob = new HostJob($metrics_in,$custom_id,$cpuIdle,$disk_total,$disk_used);
            $this->dispatch($hostjob);

            //3，要存储主机跟metric关系表 service_checks
            $checkjob = (new CheckJob($metrics_in->service_checks,$host))->onQueue("check");
            //$checkjob = new CheckJob($metrics_in->service_checks,$host);
            $this->dispatch($checkjob);

            //4,保存tags
            $tagjob = (new TagJob($tags))->onQueue("tag");
            //$tagjob = new TagJob($tags);
            $this->dispatch($tagjob);

            //5,保存metric
            $metricjob = (new MetricJob($tags))->onQueue("metric");
            //$metricjob = new MetricJob($tags);
            $this->dispatch($metricjob);
        }catch(Exception $e){
            Log::error($e->getMessage());
        }
    }

    public function series(Request $request)
    {
        try{
            $data = file_get_contents('php://input');
            $data = zlib_decode ($data);
            $series_in = \GuzzleHttp\json_decode($data);
            //$series_in = $data;
            //$custom_id = $request->header('X-Consumer-Custom-ID');
            $custom_id = "088DBF7B54EFBA3CA599B3543C73EA1C"; //test

            //Log::info("series===".$data);
            //exit();

            $series = $series_in->series;
            if(!$series) return;

            //1,保存到opentsdb
            $arrPost = array();
            $my_metric = new Metric($series,null,$custom_id);
            $arrPost = $my_metric->serise($arrPost);

            $tags = $my_metric->getTags();

            if(count($arrPost) > 0) {
                $my_metric = new Metric();
                $my_metric->post2tsdb($arrPost);
                $arrPost = array();
            }

            //2,save tag todo
            $tagjob = (new TagJob($tags))->onQueue("tag");
            //$tagjob = new TagJob($tags);
            $this->dispatch($tagjob);

            //3,保存metric
            $metricjob = (new MetricJob($tags))->onQueue("metric");
            //$metricjob = new MetricJob($tags);
            $this->dispatch($metricjob);

        }catch(Exception $e){
            Log::error($e->getMessage());
        }
    }
    public function check_run(Request $request)
    {
        try{
            $data = file_get_contents('php://input');
            //$data = zlib_decode ($data);
            $check_run = \GuzzleHttp\json_decode($data);
            //$check_run = $data;

            //$custom_id = $request->header('X-Consumer-Custom-ID');
            $custom_id = "088DBF7B54EFBA3CA599B3543C73EA1C"; //test

            //Log::info("check_run===".$data);
            //exit();

            if(!$check_run) return;

            //保存 mysql 保存metric_host todo
            $tmps = $check_run[0];
            $hostname = $tmps->host_name;
            //Log::info("check_run====host_name===".$hostname);
            $checkjob = (new CheckJob($check_run,$hostname))->onQueue("check");
            //$checkjob = new CheckJob($check_run,$hostname);
            $this->dispatch($checkjob);
        }catch(Exception $e){
            Log::error($e->getMessage());
        }
    }



    public function metadata(Request $request) {
        //    Log::info("metadata===".json_encode($request->all()));
    }
    public function metrics(Request $request) {
        //   Log::info("metrics===".json_encode($request->all()));
    }
    public function status(Request $request) {
        //   Log::info("status===".json_encode($request->all()));
    }

    public function info()
    {
        Cache::forget('metric_cache');
        Cache::forget('metric_node_cache');

        /*$res = DB::table('tag')->select("id",'key','value')->get();

        foreach($res as $tag){
            echo $tag->key . " => " . $tag->value . "; " . $tag->id . "<br>";
        }*/
        //phpinfo();

    }
}
