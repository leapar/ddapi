<?php

namespace App\Http\Controllers;

use App\MyClass\Metric;
use App\MyClass\MyApi;
use Illuminate\Http\Request;
use DB;
use Log;
use Mockery\Exception;

class SnmpController extends Controller
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

    public function device(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            return $this->returnJson(404,'未知用户');
        }
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info("device_uid = 未能获取参数" . $uid);
            return $this->returnJson(404,'未能获取参数');
        }
        if(!isset($data->sysName) || !isset($data->hostname) || !isset($data->device_id) || !isset($data->os)){
            Log::info("device_uid = 参数错误" . $uid);
            return $this->returnJson(500,'参数错误');
        }
        Log::info("device_uid = " . $uid);
        $host = $data->sysName;
        $ip = $data->hostname;
        $device_id= $data->device_id;
        $ptype= $data->os;
        $hostid = md5(md5($uid).md5($host));
        $save_data = [
            'host_name' => $host,
            'ip' => $ip,
            'device_id'=>$device_id,
            'ptype'=>$ptype,
            'update_time'=>date('Y-m-d H:i:s')
        ];
        $res = DB::table('host')->where('id',$hostid)->where('userid',$uid)->first();
        if(empty($res)){
            $save_data['id'] = $hostid;
            $save_data['userid'] = $uid;
            $save_data['createtime'] = date('Y-m-d H:i:s');
            DB::table('host')->insert($save_data);
            DB::table('host_user')->insert(['userid'=>$uid,'hostid'=>$hostid]);
        }else{
            DB::table('host')->where('id',$hostid)->where('userid',$uid)->update($save_data);
        }

        MyApi::recevieDataPutRedis($host,$uid,$data);
        return $this->returnJson(200,'success');
    }

    public function deviceos(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            return $this->returnJson(404,'未知用户');
        }
        if(!$request->has('device_id')){
            return $this->returnJson(404,'未知device');
        }
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info("deviceos_uid = 未能获取参数" . $uid);
            return $this->returnJson(404,'未能获取参数');
        }
        Log::info("deviceos_uid = " . $uid);
        $device_id= $request->device_id;
        $logo= $data->logo;
        $ptype= $data->os;
        DB::table('host')->where('userid',$uid)->where('device_id',$device_id)->update(['ptype'=>$ptype,'logo'=>$logo,'update_time'=>date('Y-m-d H:i:s')]);
        return $this->returnJson(200,'success');
    }

    public function ports(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            return $this->returnJson(404,'未知用户');
        }
        if(!$request->has('device_id')){
            return $this->returnJson(404,'未知device');
        }
        $device_id = $request->device_id;
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info('ports_uid=未能获取参数'.$uid);
            return $this->returnJson(404,'未能获取参数');
        }
        Log::info('ports_uid='.$uid);
        try{
            DB::table('ports')->where('userid',$uid)->where('device_id',$device_id)->delete();
            foreach($data as $item){
                if(empty($item->port_id)) continue;
                $save_data = [
                    'userid' => $uid,
                    'port_id' => $item->port_id,
                    'device_id' => $device_id,
                    //'action' => isset($item->action)?$item->action:'',
                    'ifIndex' => $item->ifIndex,
                    'ifName' => $item->ifName,
                    'ifAlias' => $item->ifAlias,
                    'ifDescr' => $item->ifDescr,
                    'ifPhysAddress' => $item->ifPhysAddress,
                    'update_time'=>date('Y-m-d H:i:s')
                ];
                DB::table('ports')->insert($save_data);

            }
        }catch(Exception $e){
            return $this->returnJson(500,$e->getMessage());
        }
        return $this->returnJson(200,'success');
    }

    public function portsStack(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            return $this->returnJson(404,'未知用户');
        }
        if(!$request->has('device_id')){
            return $this->returnJson(404,'未知device');
        }
        $device_id = $request->device_id;
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info("portsStack_uid = 未能获取参数" . $uid);
            return $this->returnJson(404,'未能获取参数');
        }
        Log::info("portsStack_uid = " . $uid);
        try{
            DB::table('ports_stack')->where('userid',$uid)->where('device_id',$device_id)->delete();
            foreach($data as $item){
                $save_data = [
                    'userid' => $uid,
                    'device_id' => $device_id,
                    'port_id_high' => $item->port_id_high,
                    'port_id_low' => $item->port_id_low,
                    'ifStackStatus' => $item->ifStackStatus,
                    'update_time'=>date('Y-m-d H:i:s')
                ];
                DB::table('ports_stack')->insert($save_data);
            }
        }catch(Exception $e){
            return $this->returnJson(500,$e->getMessage());
        }
        return $this->returnJson(200,'success');
    }

    public function ipv4NetWorks(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            return $this->returnJson(404,'未知用户');
        }
        Log::info("ipv4NetWorks_uid = " . $uid);
        if(!$request->has('device_id')){
            return $this->returnJson(404,'未知device');
        }
        $device_id = $request->device_id;
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info('ipv4NetWorks_uid = 未能获取参数' . $uid);
            return $this->returnJson(404,'未能获取参数');
        }
        Log::info('ipv4NetWorks_uid = ' . $uid);
        try{
            DB::table('ipv4_networks')->where('userid',$uid)->where('device_id',$device_id)->delete();
            foreach($data as $item){
                $save_data = [
                    'userid' => $uid,
                    'device_id' => $device_id,
                    'ipv4_network' => $item->ipv4_network,
                    'context_name' => $item->context_name,
                    'update_time'=>date('Y-m-d H:i:s')
                ];
                DB::table('ipv4_networks')->insert($save_data);
            }
        }catch(Exception $e){
            return $this->returnJson(500,$e->getMessage());
        }
        return $this->returnJson(200,'success');
    }

    public function ipv4Address(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            return $this->returnJson(404,'未知用户');
        }
        if(!$request->has('device_id')){
            return $this->returnJson(404,'未知device');
        }
        $device_id = $request->device_id;
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info("ipv4Address_uid = 未能获取参数" . $uid);
            return $this->returnJson(404,'未能获取参数');
        }
        Log::info("ipv4Address_uid = " . $uid);
        try{
            DB::table('ipv4_addresses')->where('userid',$uid)->where('device_id',$device_id)->delete();
            foreach($data as $item){
                if(empty($item->port_id)) continue;
                $save_data = [
                    'userid' => $uid,
                    'device_id' => $device_id,
                    'ipv4_address' => $item->ipv4_address,
                    'ipv4_prefixlen' => $item->ipv4_prefixlen,
                    'ipv4_network_id' => $item->ipv4_network_id,
                    'port_id' => $item->port_id,
                    'context_name' => $item->context_name,
                    'update_time'=>date('Y-m-d H:i:s')
                ];
                DB::table('ipv4_addresses')->insert($save_data);
            }
        }catch(Exception $e){
            return $this->returnJson(500,$e->getMessage());
        }
        return $this->returnJson(200,'success');
    }

    public function ipv6NetWorks(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            return $this->returnJson(404,'未知用户');
        }
        if(!$request->has('device_id')){
            return $this->returnJson(404,'未知device');
        }
        $device_id = $request->device_id;
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info('ipv6NetWorks_uid = 未能获取参数' . $uid);
            return $this->returnJson(404,'未能获取参数');
        }
        Log::info('ipv6NetWorks_uid = ' . $uid);
        try{
            DB::table('ipv6_networks')->where('userid',$uid)->where('device_id',$device_id)->delete();
            foreach($data as $item){
                $save_data = [
                    'userid' => $uid,
                    'device_id' => $device_id,
                    'ipv6_network' => $item->ipv6_network,
                    'context_name' => $item->context_name,
                    'update_time'=>date('Y-m-d H:i:s')
                ];
                DB::table('ipv6_networks')->insert($save_data);
            }
        }catch(Exception $e){
            return $this->returnJson(500,$e->getMessage());
        }
        return $this->returnJson(200,'success');
    }

    public function ipv6Address(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            return $this->returnJson(404,'未知用户');
        }

        if(!$request->has('device_id')){
            return $this->returnJson(404,'未知device');
        }
        $device_id = $request->device_id;
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info("ipv6Address_uid = 未能获取参数" . $uid);
            return $this->returnJson(404,'未能获取参数');
        }
        Log::info("ipv6Address_uid = " . $uid);
        try{
            DB::table('ipv6_addresses')->where('userid',$uid)->where('device_id',$device_id)->delete();
            foreach($data as $item){
                if(empty($item->port_id)) continue;
                $save_data = [
                    'userid' => $uid,
                    'device_id' => $device_id,
                    'ipv6_address' => $item->ipv6_address,
                    'ipv6_compressed' => $item->ipv6_compressed,
                    'ipv6_prefixlen' => $item->ipv6_prefixlen,
                    'ipv6_origin' => $item->ipv6_origin,
                    'ipv6_network_id' => $item->ipv6_network_id,
                    'port_id' => $item->port_id,
                    'context_name' => $item->context_name,
                    'update_time'=>date('Y-m-d H:i:s')
                ];
                DB::table('ipv6_addresses')->insert($save_data);
            }
        }catch(Exception $e){
            return $this->returnJson(500,$e->getMessage());
        }
        return $this->returnJson(200,'success');
    }

    public function ipv4Mac(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            return $this->returnJson(404,'未知用户');
        }
        if(!$request->has('device_id')){
            return $this->returnJson(404,'未知device');
        }
        $device_id = $request->device_id;
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info("ipv4Mac_uid = 未能获取参数" . $uid);
            return $this->returnJson(404,'未能获取参数');
        }
        Log::info("ipv4Mac_uid = " . $uid);
        try{
            DB::table('ipv4_mac')->where('userid',$uid)->where('device_id',$device_id)->delete();
            foreach($data as $item){
                if(empty($item->port_id)) continue;
                $save_data = [
                    'userid' => $uid,
                    'device_id' => $device_id,
                    'mac_address' => $item->mac_address,
                    'port_id' => $item->port_id,
                    'ipv4_address' => $item->ipv4_address,
                    'context_name' => $item->context_name,
                    'update_time'=>date('Y-m-d H:i:s')
                ];
                DB::table('ipv4_mac')->insert($save_data);
            }
        }catch(Exception $e){
            return $this->returnJson(500,$e->getMessage());
        }
        return $this->returnJson(200,'success');
    }

    public function portTop(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            return $this->returnJson(404,'未知用户');
        }
        $lists = MyApi::portTopData($uid);
        return response()->json($lists);
    }

    public function metrics(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            return $this->returnJson(404,'未知用户');
        }
        if(!$request->has('device_id')){
            return $this->returnJson(404,'未知device');
        }

        $data = $this->getInput($request);
        if(empty($data)){
            Log::info("metrics_uid = 未能获取参数" . $uid . " | device_id = " . $request->device_id);
            return $this->returnJson(404,'未能获取参数');
        }
        $res = DB::table('host')->where('userid',$uid)->where('device_id',$request->device_id)->first();
        if(empty($res)){
            Log::info("metrics = 未找到主机 | UID = " . $uid . " | device_id = " . $request->device_id);
            return $this->returnJson(404,'未找到主机');
        }
        Log::info('snmp_metric = ' . json_encode($data));
        $host = $res->host_name;
        $arrPost = array();
        $metric = new Metric($data,$host,$uid);
        $arrPost = $metric->snmpMetric($arrPost);

        if(count($arrPost) > 0) {
            $metric->post2tsdb($arrPost);
            $arrPost = array();
        }
    }
}
