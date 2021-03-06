<?php

namespace App\Http\Controllers;

use App\MyClass\Metric;
use App\MyClass\MyApi;
use App\MyClass\Vcenter;
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

    public function devicePoller(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            Log::info("devicePoller = 未知用户");
            return $this->returnJson(404,'未知用户');
        }
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info("devicePoller = 未能获取参数" . $uid);
            return $this->returnJson(404,'未能获取参数');
        }
        if(!$request->has('device_id')){
            Log::info("devicePoller = 未知device" . $uid);
            return $this->returnJson(404,'未知device');
        }
        //Log::info("devicePoller_data = " . json_encode($data));
        $device_id = $request->device_id;
        $save_data = [];
        if(isset($data->version)) $save_data['version'] = $data->version;
        if(isset($data->features)) $save_data['features'] = $data->features;
        if(isset($data->hardware)) $save_data['hardware'] = $data->hardware;
        if(isset($data->serial)) $save_data['serial'] = $data->serial;
        if(isset($data->sysObjectID)) $save_data['sysObjectID'] = $data->sysObjectID;
        if(isset($data->deviceType)) $save_data['deviceType'] = $data->deviceType;
        if(isset($data->deviceStatus)) $save_data['deviceStatus'] = $data->deviceStatus;

        DB::table('host')->where('userid',$uid)->where('device_id',$device_id)->update($save_data);
        $res = DB::table('host')->where('device_id',$device_id)->where('userid',$uid)->first();
        if($res){
            unset($res->device_id);
            MyApi::recevieDataPutRedis($res->host_name,$uid,$res);
        }
        return $this->returnJson(200,'success');
    }

    public function device(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            Log::info("device_uid = 未知用户");
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

        $host = str_replace(" ","",$data->sysName);
        $ip = $data->hostname;
        $device_id= $data->device_id;
        $ptype= $data->os;
        $pollerName = $data->pollerName;
        $pollerIp = $request->getClientIp();
        $hostid = md5(md5($uid).md5($host));
        $save_data = [
            'host_name' => $host,
            'ip' => $ip,
            'device_id'=>$device_id,
            'ptype'=>$ptype,
            'type_flag' => 1,
            'update_time'=> date('Y-m-d H:i:s'),
            'pollerName' => $pollerName,
            'pollerIp' => $pollerIp
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
            Log::info("deviceos_uid = 未知用户");
            return $this->returnJson(404,'未知用户');
        }
        if(!$request->has('device_id')){
            Log::info("deviceos_uid = 未知device" . $uid);
            return $this->returnJson(404,'未知device');
        }
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info("deviceos_uid = 未能获取参数" . $uid);
            return $this->returnJson(404,'未能获取参数');
        }
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
            Log::info('ports_uid=未知用户');
            return $this->returnJson(404,'未知用户');
        }
        if(!$request->has('device_id')){
            Log::info('ports_uid=未知device'.$uid);
            return $this->returnJson(404,'未知device');
        }
        $device_id = $request->device_id;
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info('ports_uid=未能获取参数'.$uid);
            return $this->returnJson(404,'未能获取参数');
        }
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
            Log::info("portsStack_uid = 未知用户");
            return $this->returnJson(404,'未知用户');
        }
        if(!$request->has('device_id')){
            Log::info("portsStack_uid = 未知device" . $uid);
            return $this->returnJson(404,'未知device');
        }
        $device_id = $request->device_id;
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info("portsStack_uid = 未能获取参数" . $uid);
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
            Log::info('ipv4NetWorks_uid = 未知用户');
            return $this->returnJson(404,'未知用户');
        }
        if(!$request->has('device_id')){
            Log::info('ipv4NetWorks_uid = 未知device'.$uid);
            return $this->returnJson(404,'未知device');
        }
        $device_id = $request->device_id;
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info('ipv4NetWorks_uid = 未能获取参数' . $uid);
            return $this->returnJson(404,'未能获取参数');
        }
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
            Log::info("ipv4Address_uid = 未知用户");
            return $this->returnJson(404,'未知用户');
        }
        if(!$request->has('device_id')){
            Log::info("ipv4Address_uid = 未知device".$uid);
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
            Log::info("ipv6NetWorks_uid = 未知用户");
            return $this->returnJson(404,'未知用户');
        }
        if(!$request->has('device_id')){
            Log::info("ipv6NetWorks_uid = 未知device".$uid);
            return $this->returnJson(404,'未知device');
        }
        $device_id = $request->device_id;
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info('ipv6NetWorks_uid = 未能获取参数' . $uid);
            return $this->returnJson(404,'未能获取参数');
        }
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
            Log::info("ipv6Address_uid = 未知用户");
            return $this->returnJson(404,'未知用户');
        }

        if(!$request->has('device_id')){
            Log::info("ipv6Address_uid = 未知device".$uid);
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
            Log::info("ipv4Mac_uid = 未知用户");
            return $this->returnJson(404,'未知用户');
        }
        if(!$request->has('device_id')){
            Log::info("ipv4Mac_uid = 未知device" . $uid);
            return $this->returnJson(404,'未知device');
        }
        $device_id = $request->device_id;
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info("ipv4Mac_uid = 未能获取参数" . $uid);
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
            Log::info("porttop_uid = 未知用户");
            return $this->returnJson(404,'未知用户');
        }
        $lists = MyApi::portTopDataV2($uid);
        return $this->returnJson(200,'success',$lists);
        //return response()->json($lists);
    }

    public function metrics(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            Log::info("metrics_uid = 未知用户");
            return $this->returnJson(404,'未知用户');
        }
        if(!$request->has('device_id')){
            Log::info("metrics_uid = 未知device" . $uid);
            return $this->returnJson(404,'未知device');
        }
        $data = $this->getInput($request);
        if(empty($data)){
            Log::info("metrics_uid = 未能获取参数" . $uid . " | device_id = " . $request->device_id);
            return $this->returnJson(404,'未能获取参数');
        }

        if($request->has('poller_up') && $request->poller_up == 'poller_up'){
            $metric = new Metric();
            foreach ($data as &$option){
                $option->tags->uid = $uid;
            }
            $metric->post2tsdb($data);
        }else{
            $res = DB::table('host')->where('userid',$uid)->where('device_id',$request->device_id)->first();
            if(empty($res)){
                Log::info("metrics = 未找到主机 | UID = " . $uid . " | device_id = " . $request->device_id);
                return $this->returnJson(404,'未找到主机');
            }
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

    public function metricsCheck(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        if(!$uid){
            Log::info("metrics_uid = 未知用户");
            return $this->returnJson(404,'未知用户');
        }
        if(!$request->has('device_id')){
            Log::info("metrics_uid = 未知device" . $uid);
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
        $host = $res->host_name;
        $hostid = md5(md5($uid).md5($host));

        DB::table('metric_host')->where('hostid',$hostid)->delete();
        DB::table('metric_host')->insert(['hostid'=>$hostid,'service_checks'=>json_encode($data)]);
    }

    public function deviceInfo(Request $request)
    {
        $datas = $request->getContent();
        $datas = json_decode($datas);
        if(empty($datas)){
            return $this->returnJson(404,'缺少参数');
        }
        $data_arr = [];
        $ret = [];
        foreach ($datas as $data){
            if($data->type_flag == 0){
                /*$hostid = $data->hostid;
                $stmp = new \stdClass();
                $stmp->$hostid = new \stdClass();
                array_push($ret,$stmp);*/
            }else if($data->type_flag == 1){
                if(!isset($data_arr['snmp'])) $data_arr['snmp'] = [];
                array_push($data_arr['snmp'],$data->hostid);
            }else if($data->type_flag == 2){
                if($data->ptype == 'HostSystem'){
                    if(!isset($data_arr['HostSystem'])) $data_arr['HostSystem'] = [];
                    array_push($data_arr['HostSystem'],$data->hostid);
                }else if($data->ptype == 'VirtualMachine'){
                    if(!isset($data_arr['VirtualMachine'])) $data_arr['VirtualMachine'] = [];
                    array_push($data_arr['VirtualMachine'],$data->hostid);
                }
            }else if($data->type_flag == 3){
                if(!isset($data_arr['kvm'])) $data_arr['kvm'] = [];
                array_push($data_arr['kvm'],$data->hostid);
            }

        }
        if(isset($data_arr['snmp']) && !empty($data_arr['snmp'])){
            $results = DB::table('host')->whereIn('id',$data_arr['snmp'])
                ->select('id','ip','host_name','ptype','logo','version','features','hardware','serial','sysObjectID','pollerName','pollerIp')
                ->get();
            foreach ($results as $res){
                $hostid = $res->id;
                $stmp = new \stdClass();
                $logo_arr = explode(".",$res->logo);
                $features = $res->features ? '('.$res->features.')' : '';
                if(in_array($res->ptype,['windows','linux','mac'])){
                    $sys = $res->ptype;
                }else{
                    $sys = strtoupper($logo_arr[0]) . ' ' . $res->ptype;
                }
                $res->OperatingSystem =  $sys . ' ' . $res->version . $features;
                unset($res->logo);unset($res->ptype);unset($res->id);
                $stmp->$hostid = $res;
                array_push($ret,$stmp);
            }
        }
        if(isset($data_arr['HostSystem']) && !empty($data_arr['HostSystem'])){
            $ress = Vcenter::VDB()->table('hosts')
                ->leftJoin('clusters','hosts.cid','=','clusters.id')
                ->leftJoin('datacenters','clusters.did','=','datacenters.id')
                ->leftJoin('vcenters','datacenters.vid','=','vcenters.id')
                ->whereIn('hosts.id',$data_arr['HostSystem'])->select('hosts.*','vcenters.pollerName','vcenters.pollerIp')->get();
            foreach ($ress as $res){
                $hostid = $res->id;
                $stmp = new \stdClass();
                $res2 = new \stdClass();
                $res2->hostName = $res->name;
                $res2->virtualMachines = $res->vm_num . '台';
                $res2->hardwareModel = $res->hardware_model;
                $res2->hardwareVendor = $res->hardware_vendor;
                $res2->productName = $res->product_name;
                $res2->productVersion = $res->product_version;
                $res2->pollerName = $res->pollerName;
                $res2->pollerIp = $res->pollerIp;
                $stmp->$hostid = $res2;
                array_push($ret,$stmp);
            }
        }
        if(isset($data_arr['VirtualMachine']) && !empty($data_arr['VirtualMachine'])){
            $ress = Vcenter::VDB()->table('virtual_machines')
                ->leftJoin('hosts','virtual_machines.hid','=','hosts.id')
                ->leftJoin('clusters','hosts.cid','=','clusters.id')
                ->leftJoin('datacenters','clusters.did','=','datacenters.id')
                ->leftJoin('vcenters','datacenters.vid','=','vcenters.id')
                ->whereIn('virtual_machines.id',$data_arr['VirtualMachine'])
                ->select('virtual_machines.*','vcenters.pollerName','vcenters.pollerIp')->get();
            foreach ($ress as $res){
                $hostid = $res->id;
                $stmp = new \stdClass();
                $res2 = new \stdClass();
                $res2->hostName = $res->name;
                $res2->sysName = $res->sysName;
                $res2->toolsInstalled = $res->toolsInstalled == 0 ? '未安装' : '已安装';
                $res2->cpuUsage = $res->cpuUsage . 'MHz';
                $res2->memUsage = $res->memUsage . 'MB';
                $res2->diskUsage = number_format($res->diskUsage / (1024 * 1024 * 1024),2,".","") . 'GB';
                $res2->pollerName = $res->pollerName;
                $res2->pollerIp = $res->pollerIp;
                $stmp->$hostid = $res2;
                array_push($ret,$stmp);
            }
        }
        if(isset($data_arr['kvm']) && !empty($data_arr['kvm'])){
            $ress = Vcenter::VDB()->table('kvms')
                ->leftJoin('khosts','kvms.khostid','=','khosts.id')
                ->whereIn('kvms.id',$data_arr['kvm'])->select('kvms.*','khosts.pollerName','khosts.pollerIp')->get();
            foreach ($ress as $res){
                $hostid = $res->id;
                $stmp = new \stdClass();
                $res2 = new \stdClass();
                $res2->hostName = $res->name;
                $res2->state = ($res->state == Vcenter::VIR_DOMAIN_RUNNING) ? 'running' : 'stop';
                $res2->MaxMem = ($res->MaxMem/1024) . 'MB';
                $res2->MemUsed = ($res->Memory/1024) . 'MB';
                $res2->CpuNum = $res->NrVirtCpu;
                $res2->CpuTime = ($res->CpuTime/1000000000) . 'seconds';
                $res2->pollerIp = $res->pollerIp;
                $stmp->$hostid = $res2;
                array_push($ret,$stmp);
            }
        }

        if($ret){
            return $this->returnJson(200,'success',$ret);
        }else{
            return $this->returnJson(200,'未找到设备信息');
        }
    }

}
