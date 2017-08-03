<?php

namespace App\Http\Controllers;

use App\Jobs\VcenterV1;
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
            $data = zlib_decode($data);
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
        if(empty($data) || !isset($data->list) || !isset($data->agent_Check)){
            Log::info("vcenter_finder = 未能获取参数 host=" . $host);
            return $this->returnJson(404,'未能获取参数');
        }
        //Log::info("#################### vcenter_finder ==".json_encode($data));
        $poller_data = $data->list;
        $pollerName = $poller_data->pollerName;
        $pollerIp = $poller_data->pollerIp;
        //物理主机 vcenter
        $vid = md5(md5($uid).md5($host));
        Vcenter::VDB()->table('vcenters')->where('id',$vid)->delete();
        Vcenter::VDB()->table('vcenters')->insert(['id' => $vid,'uid' => $uid,'host' => $host,'pollerName' => $pollerName,'pollerIp' => $pollerIp]);


        $arrPost = [];
        $metric = new Metric();

        $dataCenter = $poller_data->dataCenter;
        $arrPost = Vcenter::saveVcenter($dataCenter,$uid,$vid,$arrPost,$metric);

        $agent_check = $data->agent_Check;
        $agent_check->tags->uid = $uid;
        array_push($arrPost,$agent_check);

        $metric->post2tsdb($arrPost);
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

        $vcenterv1 = (new VcenterV1($data,$host,$uid))->onQueue("vcenterv1");
        $this->dispatch($vcenterv1);
        return;
        $arrPost = array();
        $metric = new Metric($data,$host,$uid);
        $arrPost = $metric->vcenterMetric($arrPost);

        //return response()->json($arrPost);
        if(count($arrPost) > 0) {
            $metric->post2tsdb($arrPost);
            $arrPost = array();
        }
    }

    public function vclist(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        if(!$uid){
            Log::info("vcenter_top = 未知用户");
            return $this->returnJson(404,'未知用户');
        }
        $ret = Vcenter::getVclist($uid);
        return $this->returnJson(200,'success',$ret);
    }

    public function vctop(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        if(!$uid){
            Log::info("vcenter_top = 未知用户");
            return $this->returnJson(404,'未知用户');
        }
        if(!$request->has('vcid') || !$request->has('type')){
            Log::info("vcenter_top = vcid" . $uid);
            return $this->returnJson(404,'参数错误');
        }
        $ret = Vcenter::getVctopV1($uid,$request);
        return $this->returnJson(200,'success',$ret);

    }
}