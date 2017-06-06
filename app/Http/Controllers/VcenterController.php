<?php

namespace App\Http\Controllers;

use App\MyClass\Metric;
use App\MyClass\Vcenter;
use Illuminate\Http\Request;
use DB;
use Log;
use Mockery\Exception;

class VcenterController extends Controller
{
    private function returnJson($code,$message,$result=[])
    {
        $ret = new \stdClass();
        $ret->code = $code;
        $ret->message = $message;
        $ret->result = $result;
        return response()->json($ret);
    }

    private function getInput($request)
    {
        $data = file_get_contents('php://input');
        if($request->header('Content-Encoding') == "deflate" || $request->header('Content-Encoding') == "gzip"){
            $data = zlib_decode ($data);
        }
        return json_decode($data);
    }

    public function finder(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        if(!$uid){
            Log::info("vcenter_finder = 未知用户");
            return $this->returnJson(404,'未知用户');
        }

        if(!$request->has('host')){
            Log::info("vcenter_finder = 未能获取Host" . $uid);
            return $this->returnJson(404,'未能获取Host');
        }
        $host = $request->host;
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info("vcenter_finder = 未能获取参数 host=" . $host);
            return $this->returnJson(404,'未能获取参数');
        }

        //物理主机 vcenter
        $vid = md5(md5($uid).md5($host));
        $res = Vcenter::VDB()->table('vcenters')->where('id',$vid)->first();
        if(!$res){
            Vcenter::VDB()->table('vcenters')->insert(['id' => $vid,'uid' => $uid,'host' => $host]);
        }

        foreach($data as $item){
            Vcenter::switchByType($item,$uid,$vid);
        }
    }

    public function metrics(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        if(!$uid){
            Log::info("vcenter_metrics = 未知用户");
            return $this->returnJson(404,'未知用户');
        }

        if(!$request->has('host')){
            Log::info("vcenter_metrics = 未能获取Host" . $uid);
            return $this->returnJson(404,'未能获取Host');
        }
        $host = $request->host;
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info("vcenter_metrics = 未能获取参数" . $host);
            return $this->returnJson(404,'未能获取参数');
        }

        $arrPost = array();
        $metric = new Metric($data,$host,$uid);
        $arrPost = $metric->vcenterMetric($arrPost);

        //return response()->json($arrPost);
        if(count($arrPost) > 0) {
            $metric->post2tsdb($arrPost);
            $arrPost = array();
        }
    }
}