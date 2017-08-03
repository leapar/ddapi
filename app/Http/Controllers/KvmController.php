<?php
/**
 * Created by PhpStorm.
 * User: cheng_f
 * Date: 2017/7/18
 * Time: 14:40
 */

namespace App\Http\Controllers;

use App\MyClass\Metric;
use App\MyClass\Vcenter;
use Illuminate\Http\Request;
use DB;
use Log;
use Mockery\Exception;

class KvmController extends Controller
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
            $data = zlib_decode($data);
        }
        return json_decode($data);
    }

    public function host(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        if(!$uid){
            Log::info("kvm = 未知用户");
            return $this->returnJson(404,'未知用户');
        }

        if(!$request->has('host') || !$request->has('ip')){
            Log::info("kvm_host = 未能获取Host" . $uid);
            return $this->returnJson(404,'未能获取Host');
        }
        $host = $request->host;
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info("kvm_host_data = 未能获取data ==== host=" . $host);
            return $this->returnJson(404,'未能获取data');
        }
        $khostid = md5(md5($uid).md5($host));
        Vcenter::VDB()->table('khosts')->where('id',$khostid)->delete();
        Vcenter::VDB()->table('khosts')->insert(['id' => $khostid,'userid' => $uid,'host' => $host,'pollerIp' => $request->ip]);

        Vcenter::kvm($host,$uid,$data);
    }

    public function metrics(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        if(!$uid){
            Log::info("kvm = 未知用户");
            return $this->returnJson(404,'未知用户');
        }

        if(!$request->has('host')){
            Log::info("kvm_host = 未能获取Host" . $uid);
            return $this->returnJson(404,'未能获取Host');
        }
        $host = $request->host;
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info("kvm_host_data = 未能获取data ==== host=" . $host);
            return $this->returnJson(404,'未能获取data');
        }

        $arrPost = array();
        $metric = new Metric($data,$host,$uid);
        $arrPost = $metric->kvmMetric($arrPost);

        //return response()->json($arrPost);
        if(count($arrPost) > 0) {
            $metric->post2tsdb($arrPost);
            $arrPost = array();
        }
    }

    public function poller(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            Log::info("kvm = 未知用户");
            return $this->returnJson(404,'未知用户');
        }
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info("kvm_poller_data = 未能获取data ====");
            return $this->returnJson(404,'未能获取data');
        }

        $metric = new Metric();
        foreach ($data as &$option){
            $option->tags->uid = $uid;
        }
        $metric->post2tsdb($data);
    }
}