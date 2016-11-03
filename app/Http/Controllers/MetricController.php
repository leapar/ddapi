<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Log;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use App\Metric;

class MetricController extends Controller
{
    public function datadog(Request $request) {
        Log::info(json_encode($request));
    }

    public function intake(Request $request) {
        $data = file_get_contents('php://input');
        $data = zlib_decode ($data);
        $metrics_in = \GuzzleHttp\json_decode($data);

        $host = $metrics_in->internalHostname;
        $metrics = $metrics_in->metrics;

        //Log::info("header===".$request->header('X-Consumer-Custom-ID'));
        //Log::info("header===".$request->header('X-Consumer-Username'));
        //$custom_id = $request->header('X-Consumer-Custom-ID');
        $custom_id = "asdfa8a09s7fasf70a9sd7gva09ds7fas09df8"; //test

        Log::info("intake===".$data);
        exit();

        $my_metric = new Metric($metrics_in,$host,$custom_id);

        $arrPost = array();
        $arrPost = $my_metric->getTagsByOS($arrPost);

        if(!empty($metrics)) {
            foreach($metrics as $metric) {

                $sub = $my_metric->getTags($metric);

                array_push($arrPost ,$sub);

                $arrPost = $my_metric->checkarrPost($arrPost);
            }
        }

        if(count($arrPost) > 0) {
            $my_metric->post2tsdb($arrPost);
            $arrPost = array();
        }

        //2、mysql 要存储主机跟metric关系表  host表 service_checks


    }

    public function series(Request $request) {
        //	var_dump($request);
        //	echo "ddd";
        //    Log::info("series===".json_encode($request->all()));
    }
    public function metadata(Request $request) {
        //    Log::info("metadata===".json_encode($request->all()));
    }
    public function metrics(Request $request) {
        //   Log::info("metrics===".json_encode($request->all()));
    }
    public function check_run(Request $request) {
        //   Log::info("check_run===".json_encode($request->all()));
    }
    public function status(Request $request) {
        //   Log::info("status===".json_encode($request->all()));
    }
    public function intake2(Request $request) {
        //  die("intake2");
        //   Log::info("intake2===".json_encode($request->all()));
    }

    public function info()
    {
        phpinfo();
    }
}
