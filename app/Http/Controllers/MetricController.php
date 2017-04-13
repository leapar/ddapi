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
use App\MyClass\MyApi;
use App\MyClass\MyRedisCache;
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
            if($request->header('Content-Encoding') == "deflate" || $request->header('Content-Encoding') == "gzip"){
                $data = zlib_decode ($data);
            }
            $metrics_in = \GuzzleHttp\json_decode($data);
            $host = $metrics_in->internalHostname;
            $metrics = isset($metrics_in->metrics) ? $metrics_in->metrics : '';

            /*if($host == 'xu.long'){
                Log::info("check_rum ===> ".json_encode($data));
            }*/
            $uid = $request->header('X-Consumer-Custom-ID');
            if(!$uid){
                Log::info("intake_uid = " . $uid);
                return;
            }

            $hostid = md5(md5($uid).md5($host));
            $my_metric = new Metric($metrics_in,$host,$uid);

            //1，保存 opentsdb
            $arrPost = array();
            $arrPost = $my_metric->getMetricByOS($arrPost);

            $cpuIdle = isset($metrics_in->cpuIdle) ? $metrics_in->cpuIdle : 0;
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
            if(isset($metrics_in->gohai) && !empty($metrics_in->gohai)){
                MyApi::putHostTags($metrics_in,$host,$uid);

                $hostjobV1 = (new HostJobV1($metrics_in,$uid,$cpuIdle,$disk_total,$disk_used))->onQueue("hostV1");
                $this->dispatch($hostjobV1);
            }

            $res = Metric::checktime($hostid.'intake_redis',1);
            Log::info('intake_redis = '.$res);
            if($res){
                $this->hostRedis($metrics_in,$host,$uid,$cpuIdle,$disk_total,$disk_used);
            }
            $res = $my_metric->checktime($hostid.'intake');
            if(!$res) return;

            $metricjobV1 = (new MetricJobV1($hostid,$metrics_in->service_checks))->onQueue("metricV1");
            $this->dispatch($metricjobV1);
        }catch(Exception $e){
            Log::error($e->getMessage());
        }
    }

    public function series(Request $request)
    {
        try{
            $data = file_get_contents('php://input');
            if($request->header('Content-Encoding') == "deflate" || $request->header('Content-Encoding') == "gzip"){
                $data = zlib_decode ($data);
            }
            $series_in = \GuzzleHttp\json_decode($data);
            $uid = $request->header('X-Consumer-Custom-ID');
            if(!$uid){
                Log::info("series_uid = " . $uid);
                return;
            }
            $series = $series_in->series;
            if(!$series) return;

            //1,保存到opentsdb
            $arrPost = array();
            $tmps = $series[0];
            $host = $tmps->host;
            $my_metric = new Metric($series,$host,$uid);
            $arrPost = $my_metric->serise($arrPost);
            /*if($host == 'wan215'){
                Log::info("series_data ===> ".json_encode($data));
            }*/
            if(count($arrPost) > 0) {
                $my_metric->post2tsdb($arrPost);
                $arrPost = array();
            }

            $hostid = md5(md5($uid).md5($host));
            $res = Metric::checktime($hostid.'intake_redis',1);
            if($res){
                $cpuIdle = 0;
                $disk_total = 0;
                $disk_used = 0;
                $num = 0;
                foreach($series as $item) {
                    $metric = $item->metric;
                    $value = $item->points[0][1];
                    if($metric === "system.cpu.idle"){
                        $cpuIdle += $value;
                        $num++;
                    }
                    if($metric === "system.disk.total"){
                        $disk_total += $value;
                        $num++;
                    }
                    if($metric === "system.disk.used"){
                        $disk_used += $value;
                        $num++;
                    }
                }
                Log::info("num = " . $num);
                if($num >= 1){
                    $this->hostRedis($series,$host,$uid,$cpuIdle,$disk_total,$disk_used);
                }

            }

        }catch(Exception $e){
            Log::error($e->getMessage());
        }catch(\InvalidArgumentException $e){
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
            }
            $check_run = \GuzzleHttp\json_decode($data);
            $uid = $request->header('X-Consumer-Custom-ID');
            if(!$uid){
                Log::info("check_run = " . $uid);
                return;
            }
            if(!$check_run) return;

            //保存 mysql 保存metric_host todo
            $tmps = $check_run[0];
            $hostname = $tmps->host_name;
            $hostid = md5(md5($uid).md5($hostname));
            /*if($hostname == 'wan215'){
                Log::info("check_run ===> ".json_encode($data));
            }*/
            $my_metric = new Metric();
            $res = $my_metric->checktime($hostid.'check_run');
            if(!$res) return;

            $metricjobV1 = (new MetricJobV1($hostid,null,$check_run))->onQueue("metricV1");
            $this->dispatch($metricjobV1);

        }catch(Exception $e){
            Log::error($e->getMessage());
        }
    }

    private function hostRedis($metrics_in,$host,$uid,$cpuIdle,$disk_total,$disk_used)
    {
        $hostid = md5(md5($uid).md5($host));
        list($t1, $t2) = explode(' ', microtime());
        $msec = (float)sprintf('%.0f', (floatval($t1) + floatval($t2)) * 1000);
        $hsname = "HOST_DATA_".$uid;
        $load15 = "system.load.15";
        //保存数据到redis
        $data = json_decode(Redis::command("HGET",[$hsname,$hostid]),true);
        if(empty($data)){
            $redis_data = [
                "hostId" => $hostid,
                "cpu" => 100 - $cpuIdle,
                "diskutilization" => $disk_total == 0 ? 0 :$disk_used / $disk_total * 100,
                "disksize" => $disk_total,
                "hostName" => $host,
                "updatetime" => $msec,
                "iowait" =>  null,
                "load15" => null,
                "colletcionstamp" => time(),
                "ptype" => '',
                "uuid" => ''
            ];
        }else{
            $redis_data = [
                "hostId" => $hostid,
                "cpu" => 100 - $cpuIdle,
                "diskutilization" => $disk_total == 0 ? 0 :$disk_used / $disk_total * 100,
                "disksize" => $disk_total,
                "hostName" => $host,
                "updatetime" => $msec,
                "iowait" =>  $data['iowait'],
                "load15" => $data['load15'],
                "colletcionstamp" => time(),
                "ptype" => $data['ptype'],
                "uuid" => $data['uuid']
            ];
        }

        if(isset($metrics_in->cpuWait)) $redis_data['iowait'] = $metrics_in->cpuWait;
        if(isset($metrics_in->$load15)) $redis_data['load15'] = $metrics_in->$load15;
        if(isset($metrics_in->collection_timestamp)) $redis_data['colletcionstamp'] = $metrics_in->collection_timestamp;
        if(isset($metrics_in->os)) $redis_data['ptype'] = $metrics_in->os;
        if(isset($metrics_in->uuid)) $redis_data['uuid'] = $metrics_in->uuid;

        Redis::command('HSET',[$hsname,$hostid,json_encode($redis_data)]);

        if(isset($metrics_in->processes)){
            $host_process = "PROCESS_".$hostid;
            $process = json_encode($metrics_in->processes->processes);
            Redis::command('HSET',[$hsname,$host_process,$process]);
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
        $a = '-';
        $b = 2+3;
        echo $a+$b;
    }
}
