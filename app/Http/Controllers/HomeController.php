<?php

namespace App\Http\Controllers;

use App\Http\Requests;
use Illuminate\Http\Request;
use Log;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;


class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
     //   $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('home');
    }

    public function datadog(Request $request) {
        Log::info(json_encode($request));
    }

    public function post2tsdb($arrPost) {
        $headers = array('Content-Type: application/json','Content-Encoding: gzip',);
        $gziped_xml_content = gzencode(json_encode($arrPost));
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, 'http://172.29.225.222:4242/api/put?details');//'http://172.29.231.123:4242/api/put');
        curl_setopt($ch, CURLOPT_TIMEOUT,120);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $gziped_xml_content);
        $res = curl_exec($ch);
        curl_close($ch);
        if($res == NULL) {
        	Log::info("response ===opentsdb error");
        } else {
	        $res = json_decode($res);
	        
	        if($res->failed > 0) {
	        	Log::info("post ===".json_encode($arrPost));		
	        	Log::info("response ===".$res);
	        }
        }
    }
    
    public function intake(Request $request) {
        $data = file_get_contents('php://input');
        $data = zlib_decode ($data);
        $metrics_in = \GuzzleHttp\json_decode($data);

	 $host = $metrics_in->internalHostname;
        $metrics = $metrics_in->metrics;
        if(empty($metrics)) {
            return;
        }
        //Log::info("intake===".$data);
        $arrPost = array();
        
        
//	Log::info("header===".$request->header('X-Consumer-Custom-ID'));
//	Log::info("header===".$request->header('X-Consumer-Username'));
	$custom_id = $request->header('X-Consumer-Custom-ID');
	
        foreach($metrics as $metric) {
            $sub = new \stdClass();
            $sub->metric = $metric[0];
            $sub->timestamp = $metric[1];
            $sub->value = $metric[2];
            $tag = $metric[3];
            $sub->tags = new \stdClass();//$metric[3];
            $sub->tags->host = $host;
            $sub->tags->uid = $custom_id;//1;//$metrics_in->uuid;
            if(isset($tag->device_name)) {
            	$sub->tags->device = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\-\/]/u","",$tag->device_name);//str_replace(array("{",":","*","}"), "_", $tag->device_name);//$tag->device_name;
            }
            if(isset($tag->tags)) {
            	foreach($tag->tags as $value) {
            		$tmps = explode(":",$value);
            	
            		if(count($tmps) == 2) {
            	//			Log::info("value===".$tmps[1]);
            	//	Log::info("valuevalue===".str_replace(array("{","}"), "_", $tmps[1]));
            		
            			$sub->tags->$tmps[0] = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\-\/]/u","",$tmps[1]);//str_replace(array("{",":","*","}"), "_", $tmps[1]);
            		} else {
            			$sub->tags->$tmps[0] = "NULL";//$sub->tags->$tmps[0];
            		}
            	}
            }
            

            array_push( $arrPost ,$sub);
            if(count($arrPost) > 30) {
                /*$client = new Client();
                $response = $client->request('POST', 'http://172.29.225.109:4242/api/put', [
                    'json' => $arrPost
                ]);*/
                //Log::info("post===".json_encode($arrPost));
                $this->post2tsdb($arrPost);
                $arrPost = array();
            }
        }
        if(count($arrPost) > 0) {
            /*$client = new Client();
            $response = $client->request('POST', 'http://172.29.225.109:4242/api/put', [
                'json' => $arrPost,
                'decode_content' => 'gzip'
            ]);*/
            
            $this->post2tsdb($arrPost);
            $arrPost = array();
        }



   //     Log::info("response===".json_encode($response));
/*
        {
            "metric": "sys.cpu.nice",
            "timestamp": 1346846400,
            "value": 18,
            "tags": {
                    "host": "web01",
                    "dc": "lga"
            }
        }

        [
            {
                "metric": "sys.cpu.nice",
                "timestamp": 1346846400,
                "value": 18,
                "tags": {
                   "host": "web01",
                   "dc": "lga"
                }
            },
            {
                "metric": "sys.cpu.nice",
                "timestamp": 1346846400,
                "value": 9,
                "tags": {
                   "host": "web02",
                   "dc": "lga"
                }
            }
        ]
*/
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
