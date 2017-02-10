<?php

namespace App\Http\Controllers;

use App\Jobs\CheckJob;
use App\Jobs\HostJob;
use App\Jobs\HostJobV1;
use App\Jobs\MetricJob;
use App\Jobs\MetricJobV1;
use App\Jobs\TagJob;
use App\Jobs\TagJobV1;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Log;
use DB;
use App\Tag;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use App\MyClass\Metric;
use App\MyClass\MyRedisCache;
use Mockery\CountValidator\Exception;

class MetricController extends Controller
{
    public function datadog(Request $request) {
        Log::info(json_encode($request));
    }

    public function intake(Request $request)
    {
        //exit();
        echo "success";
        try{
            //Log::info("intake_start === " .time());
            $data = file_get_contents('php://input');
            if($request->header('Content-Encoding') == "deflate" || $request->header('Content-Encoding') == "gzip"){
                $data = zlib_decode ($data);
                //Log::info("Content-Encoding===".$request->header('Content-Encoding'));
            }
            //$data = zlib_decode($data);
            $metrics_in = \GuzzleHttp\json_decode($data);

            $host = $metrics_in->internalHostname;
            $metrics = $metrics_in->metrics;

            Log::info("header===".$request->header('X-Consumer-Custom-ID'));
            //Log::info("header===".$request->header('X-Consumer-Username'));
            $uid = $request->header('X-Consumer-Custom-ID');
            //$uid = "1"; //test

            if(!$uid) return;

            $hostid = md5(md5($uid).md5($host));

            //("service_checks===".json_encode($metrics_in->service_checks));
            //exit();

            $my_metric = new Metric($metrics_in,$host,$uid);

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

            $my_metric->setHostTag();
            $tags = $my_metric->getTags();

            $res = $my_metric->checktime($hostid.'intake');
            if(!(isset($metrics_in->gohai) && !empty($metrics_in->gohai)) && !$res) return;
            
            $hostjobV1 = (new HostJobV1($metrics_in,$uid,$cpuIdle,$disk_total,$disk_used))->onQueue("hostV1");
            $this->dispatch($hostjobV1);

            $tagjobV1 = (new TagJobV1($tags))->onQueue("tagV1");
            $this->dispatch($tagjobV1);

            $metricjobV1 = (new MetricJobV1($hostid,$metrics_in->service_checks))->onQueue("metricV1");
            $this->dispatch($metricjobV1);

            /*//2、保存 mysql host表,host_user
            $hostjob = (new HostJob($metrics_in,$uid,$cpuIdle,$disk_total,$disk_used))->onQueue("host");
            $this->dispatch($hostjob);


            //4,保存tags
            $tagjob = (new TagJob($tags))->onQueue("tag");
            $this->dispatch($tagjob);


            //5,保存metric
            $metricjob = (new MetricJob($tags))->onQueue("metric");
            $this->dispatch($metricjob);

            //3，要存储主机跟metric关系表 service_checks
            $checkjob = (new CheckJob($metrics_in->service_checks,$host,$uid))->onQueue("metric");
            $this->dispatch($checkjob);*/

        }catch(Exception $e){
            Log::error($e->getMessage());
        }
    }

    public function series(Request $request)
    {
        //exit();
        try{
            $data = file_get_contents('php://input');
            if($request->header('Content-Encoding') == "deflate" || $request->header('Content-Encoding') == "gzip"){
                $data = zlib_decode ($data);
                //Log::info("Content-Encoding===".$request->header('Content-Encoding'));
            }
            $series_in = \GuzzleHttp\json_decode($data);
            //$series_in = $data;
            $uid = $request->header('X-Consumer-Custom-ID');
            //$uid = "1"; //test

            if(!$uid) return;

            //Log::info("series===".$data);
            //exit();

            $series = $series_in->series;
            if(!$series) return;

            //1,保存到opentsdb
            $arrPost = array();
            $tmps = $series[0];
            $host = $tmps->host;
            $my_metric = new Metric($series,$host,$uid);
            $arrPost = $my_metric->serise($arrPost);

            $tags = $my_metric->getTags();

            if(count($arrPost) > 0) {
                $my_metric->post2tsdb($arrPost);
                $arrPost = array();
            }

            $hostid = md5(md5($uid).md5($host));
            $res = $my_metric->checktime($hostid.'series');
            if(!$res) return;

            $tagjobV1 = (new TagJobV1($tags))->onQueue("tagV1");
            $this->dispatch($tagjobV1);

            //2,save tag
            //$tagjob = (new TagJob($tags))->onQueue("tag");
            //$this->dispatch($tagjob);

            //3,保存metric
            //$metricjob = (new MetricJob($tags))->onQueue("metric");
            //$this->dispatch($metricjob);

        }catch(Exception $e){
            Log::error($e->getMessage());
        }
    }
    public function check_run(Request $request)
    {
        //exit();
        try{
            $data = file_get_contents('php://input');
            //$data = zlib_decode ($data);
            if($request->header('Content-Encoding') == "deflate" || $request->header('Content-Encoding') == "gzip"){
                $data = zlib_decode ($data);
                //Log::info("Content-Encoding===".$request->header('Content-Encoding'));
            }
            $check_run = \GuzzleHttp\json_decode($data);
            //$check_run = $data;

            $uid = $request->header('X-Consumer-Custom-ID');
            //$uid = "1"; //test
            if(!$uid) return;

            //Log::info("check_run===".$data);
            //exit();

            if(!$check_run) return;

            //保存 mysql 保存metric_host todo
            $tmps = $check_run[0];
            $hostname = $tmps->host_name;
            $hostid = md5(md5($uid).md5($hostname));

            $my_metric = new Metric();
            $res = $my_metric->checktime($hostid.'check_run');
            if(!$res) return;

            $metricjobV1 = (new MetricJobV1($hostid,null,$check_run))->onQueue("metricV1");
            $this->dispatch($metricjobV1);

            //$checkjob = (new CheckJob($check_run,$hostname,$uid))->onQueue("metric");
            //$checkjob = new CheckJob($check_run,$hostname);
            //$this->dispatch($checkjob);
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
        $res = DB::table('tag')->lists('id');
        var_dump($res);
        //phpinfo();
        exit();
    }
}
