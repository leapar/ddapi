<?php

namespace App\Http\Controllers;

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

    public function device(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            return $this->returnJson(404,'未知用户');
        }
        Log::info("device_uid = " . $uid);
        $data = json_decode($request->getContent());
        if(empty($data)){
            return $this->returnJson(404,'未能获取参数');
        }
        $host = $data->sysName;
        $ip = $data->hostname;
        $device_id= $data->device_id;
        $ptype= $data->os;
        $hostid = md5(md5($uid).md5($host));

        $res = DB::table('host')->where('id',$hostid)->update(['host_name' => $host,'ip' => $ip,'device_id'=>$device_id,'ptype'=>$ptype]);
        if(!$res){
            DB::table('host')->insert(['id'=>$hostid,'host_name' => $host,'ip' => $ip,'device_id'=>$device_id,'ptype'=>$ptype,'userid' => $uid]);
            DB::table('host_user')->insert(['userid'=>$uid,'hostid'=>$hostid]);
        }
        return $this->returnJson(200,'success');
    }

    public function deviceos(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            return $this->returnJson(404,'未知用户');
        }
        Log::info("deviceos_uid = " . $uid);
        if(!$request->has('device_id')){
            return $this->returnJson(404,'未知device');
        }
        $data = json_decode($request->getContent());
        if(empty($data)){
            return $this->returnJson(404,'未能获取参数');
        }
        $device_id= $request->device_id;
        $logo= $data->logo;
        $ptype= $data->os;
        $res = $res = DB::table('host')->where('userid',$uid)->where('device_id',$device_id)->update(['ptype'=>$ptype,'logo'=>$logo]);
        return $this->returnJson(200,'success');
    }

    public function ports(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            return $this->returnJson(404,'未知用户');
        }
        Log::info("ports_uid = " . $uid);
        if(!$request->has('device_id')){
            return $this->returnJson(404,'未知device');
        }
        $device_id = $request->device_id;
        $data = json_decode($request->getContent());
        if(empty($data)){
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
                    'ifPhysAddress' => $item->ifPhysAddress
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
        Log::info("portsStack_uid = " . $uid);
        if(!$request->has('device_id')){
            return $this->returnJson(404,'未知device');
        }
        $device_id = $request->device_id;
        $data = json_decode($request->getContent());
        if(empty($data)){
            return $this->returnJson(404,'未能获取参数');
        }
        try{
            DB::table('ports_stack')->where('userid',$uid)->where('device_id',$device_id)->delete();
            foreach($data as $item){
                $save_data = [
                    'userid' => $uid,
                    'device_id' => $device_id,
                    'port_id_high' => $item->port_id_high,
                    'port_id_low' => $item->port_id_low,
                    'ifStackStatus' => $item->ifStackStatus,
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
        $data = json_decode($request->getContent());
        if(empty($data)){
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
                    'context_name' => $item->context_name
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
        Log::info("ipv4Address_uid = " . $uid);
        if(!$request->has('device_id')){
            return $this->returnJson(404,'未知device');
        }
        $device_id = $request->device_id;
        $data = json_decode($request->getContent());
        if(empty($data)){
            return $this->returnJson(404,'未能获取参数');
        }
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
                    'context_name' => $item->context_name
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
        Log::info("ipv6NetWorks_uid = " . $uid);
        if(!$request->has('device_id')){
            return $this->returnJson(404,'未知device');
        }
        $device_id = $request->device_id;
        $data = json_decode($request->getContent());
        if(empty($data)){
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
                    'context_name' => $item->context_name
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
        Log::info("ipv6Address_uid = " . $uid);
        if(!$request->has('device_id')){
            return $this->returnJson(404,'未知device');
        }
        $device_id = $request->device_id;
        $data = json_decode($request->getContent());
        if(empty($data)){
            return $this->returnJson(404,'未能获取参数');
        }
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
                    'context_name' => $item->context_name
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
        Log::info("ipv4Mac_uid = " . $uid);
        if(!$request->has('device_id')){
            return $this->returnJson(404,'未知device');
        }
        $device_id = $request->device_id;
        $data = json_decode($request->getContent());
        if(empty($data)){
            return $this->returnJson(404,'未能获取参数');
        }
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
                    'context_name' => $item->context_name
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
}
