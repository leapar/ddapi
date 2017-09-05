<?php
/**
 * Created by PhpStorm.
 * User: cheng_f
 * Date: 2017/2/21
 * Time: 10:56
 */

namespace App\MyClass;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Log;
use DB;

class Vcenter
{
    const DATACENTER = 'Datacenter';
    const FOLDER = 'Folder';
    const CLUSTER = 'ClusterComputeResource';
    const HOST = 'HostSystem';
    const VM = 'VirtualMachine';

    const RESOURCEPOOL = 'ResourcePool';// (和HOST一样 属于 cluster)
    const DATASTORE = 'Datastore';// (和vm一样，属于host 或者 folder)
    const NETWORK = 'Network';// (和vm一样，属于host 或者 folder)

    const VIR_DOMAIN_NOSTATE = 0;     /* no state */
    const VIR_DOMAIN_RUNNING = 1;     /* the domain is running */
    const VIR_DOMAIN_BLOCKED = 2;     /* the domain is blocked on resource */
    const VIR_DOMAIN_PAUSED  = 3;     /* the domain is paused by user */
    const VIR_DOMAIN_SHUTDOWN= 4;     /* the domain is being shut down */
    const VIR_DOMAIN_SHUTOFF = 5;     /* the domain is shut off */
    const VIR_DOMAIN_CRASHED = 6;     /* the domain is crashed */
    const VIR_DOMAIN_PMSUSPENDED = 7; /* the domain is suspended by guest power management */

    public static function VDB()
    {
        return app('db')->connection("mysql_vcenter");
    }

    public static function saveVcenter($dataCenter,$uid,$vid,$arrPost,$metric)
    {
        $color = [
            'gray' => 0,
            'green' => 1,
            'yellow' => 2,
            'red' => 3,
        ];
        foreach($dataCenter as $center){
            $dc_name = $center->name;
            if(empty($dc_name)) continue;
            $did = md5(md5($uid).md5($dc_name));
            $param = ['id' => $did,'vid' => $vid,'name' => $dc_name];
            Vcenter::dbSave($did,"datacenters",$param);

            foreach($center->datastoreSummarys as $store){
                $store_name = $store->name;
                $store_val = $store->datastore->val;
                $store_id = md5(md5($uid).md5($store_name));
                $store_param = [
                    'id' => $store_id,
                    'did' => $did,
                    'name' => $store_name,
                    'value' => $store_val
                ];
                Vcenter::dbSave($store_id,"datastores",$store_param);
            }

            foreach($center->clusters as $cluster){
                $c_name = $cluster->name;
                if(empty($c_name)) continue;
                $cid = md5(md5($uid).md5($c_name));
                $param =['id' => $cid,'did' => $did,'name' => $c_name];
                Vcenter::dbSave($cid,"clusters",$param);

                foreach($cluster->hosts as $host){

                    $agent_check = $host->agent_Check;
                    $agent_check->tags->uid = $uid;
                    $agent_check->tags->host = $agent_check->tags->HostSystem;
                    unset($agent_check->tags->HostSystem);
                    array_push($arrPost,$agent_check);

                    if(count($arrPost) > 30) {
                        $metric->post2tsdb($arrPost);
                        $arrPost = array();
                    }

                    $hostSummary = $host->hostSummary;
                    $host_name = $hostSummary->config->name;
                    if(empty($host_name)) continue;
                    $type = $hostSummary->host->type;
                    $hid = md5(md5($uid).md5($host_name));

                    $param =[
                        'id' => $hid,
                        'cid' => $cid,
                        'name' => $host_name,
                        'vm_num' => count($host->vms),
                        'hardware_model' => $hostSummary->hardware->model,
                        'hardware_vendor' => $hostSummary->hardware->vendor,
                        'product_name' => $hostSummary->config->product->name,
                        'product_version' => $hostSummary->config->product->version,
                        'overallStatus' => isset($color[$hostSummary->overallStatus]) ? $color[$hostSummary->overallStatus] : 0,
                    ];
                    $uuid = $hostSummary->hardware->uuid;
                    Vcenter::dbSave($hid,"hosts",$param);
                    Vcenter::recevieDataPutRedis($host_name,$uid,$type,$uuid);
                    Vcenter::saveToApmsys($host_name,$uid,$type,2,$uuid);

                    foreach($host->datastoresSummarys as $store){
                        $store_name = $store->name;
                        $store_id = md5(md5($uid).md5($store_name));
                        $store_param = [
                            'hostid' => $hid,
                            'storeid' => $store_id,
                        ];
                        Vcenter::dbSaveStore("host_stores",$store_param);
                    }


                    foreach($host->vms as $vm){

                        $agent_check = $vm->agent_Check;
                        $agent_check->tags->uid = $uid;
                        $agent_check->tags->host = $agent_check->tags->VirtualMachine;
                        unset($agent_check->tags->VirtualMachine);
                        array_push($arrPost,$agent_check);

                        if(count($arrPost) > 30) {
                            $metric->post2tsdb($arrPost);
                            $arrPost = array();
                        }

                        $vm_name = $vm->vmSummary->config->name;
                        if(empty($vm_name)) continue;
                        $vmid = md5(md5($uid).md5($vm_name));
                        $vmtype = $vm->vmSummary->vm->type;

                        $vm_param =[
                            'id' => $vmid,
                            'hid' => $hid,
                            'name' => $vm_name,
                            'power_state' => $vm->vmSummary->runtime->powerState,
                            'suspended' => $vm->vmSummary->runtime->suspendInterval,

                            'toolsInstalled' => $vm->vmSummary->runtime->toolsInstallerMounted ? 1 : 0,
                            'sysName' => $vm->vmSummary->config->guestFullName,
                            'cpuUsage' => $vm->vmSummary->quickStats->overallCpuUsage,
                            'memUsage' => $vm->vmSummary->quickStats->hostMemoryUsage + $vm->vmSummary->quickStats->guestMemoryUsage,
                            'diskUsage' => $vm->vmSummary->storage->committed,
                            'overallStatus' => isset($color[$vm->vmSummary->overallStatus]) ? $color[$vm->vmSummary->overallStatus] : 0,

                        ];
                        $uuid = $vm->vmSummary->config->uuid;

                        if(stripos($vm_param['sysName'], 'Red Hat') !== false){
                            $logo = 'redhat.svg';
                        }else if(stripos($vm_param['sysName'], 'CentOS') !== false){
                            $logo = 'centos.svg';
                        }else if(stripos($vm_param['sysName'], 'Windows') !== false){
                            $logo = 'windows.svg';
                        }else if(stripos($vm_param['sysName'], 'Linux') !== false){
                            $logo = 'linux.svg';
                        }else{
                            $logo = 'generic.svg';
                        }

                        Vcenter::dbSave($vmid,"virtual_machines",$vm_param);
                        Vcenter::recevieDataPutRedis($vm_name,$uid,$vmtype,$uuid);
                        Vcenter::saveToApmsys($vm_name,$uid,$vmtype,2,$uuid,$logo);

                        $datastore = $vm->datastoreSummarys;
                        foreach($datastore as $store){
                            $store_name = $store->name;
                            $store_id = md5(md5($uid).md5($store_name));
                            $store_param = [
                                'vmid' => $vmid,
                                'storeid' => $store_id,
                            ];
                            Vcenter::dbSaveStore("vm_stores",$store_param);
                        }
                    }
                }
            }

            return $arrPost;
        }
    }

    public static function dbSave($id,$table,$param)
    {
        if(empty($id)) return;
        $res = Vcenter::VDB()->table($table)->where('id',$id)->first();
        if(empty($res)){
            Vcenter::VDB()->table($table)->insert($param);
        }else{
            Vcenter::VDB()->table($table)->where('id',$id)->update($param);
        }
    }

    public static function dbSaveStore($table,$param)
    {
        if(empty($param)) return;
        $db = Vcenter::VDB()->table($table);
        foreach($param as $key => $val){
            $db = $db->where($key,$val);
        }
        $db->delete();

        Vcenter::VDB()->table($table)->insert($param);
    }

    public static function dbUpdate($id,$table,$param)
    {
        Vcenter::VDB()->table($table)->where('id',$id)->update($param);
    }

    public static function saveToApmsys($hostname,$uid,$ptype,$type_flag,$uuid=null,$logo=null)
    {
        if(empty($hostname)) return;
        $hostid = md5(md5($uid).md5($hostname));
        DB::table('host')->where('id',$hostid)->delete();
        DB::table('host_user')->where('userid',$uid)->where('hostid',$hostid)->delete();
        $data = [
            'id' => $hostid,
            'ptype' => $ptype,
            'host_name' => $hostname,
            'uuid' => $uuid,
            'userid' => $uid,
            'type_flag' => $type_flag,
            'createtime' => date('Y-m-d H:i:s')
        ];
        if(!is_null($logo)) $data['logo'] = $logo;
        DB::table('host')->insert($data);
        DB::table('host_user')->insert(['userid'=>$uid,'hostid'=>$hostid]);
    }

    public static function recevieDataPutRedis($host,$uid,$ptype=null,$uuid=null,$cpuIdle=null,$diskutilization=null,$disk_total=null,$iowait=null,$load15=null)
    {
        $expire = 3*24*3600;
        $hostid = md5(md5($uid).md5($host));
        list($t1, $t2) = explode(' ', microtime());
        $msec = (float)sprintf('%.0f', (floatval($t1) + floatval($t2)) * 1000);
        $hsname = "HOST_DATA_".$uid."_".$host;

        $redis_data = json_decode(Redis::command('GET', [$hsname]),true);
        $cpu_r= isset($redis_data['cpu'])?$redis_data['cpu']:null;
        $diskutilization_r= isset($redis_data['diskutilization'])?$redis_data['diskutilization']:null;
        $disksize_r= isset($redis_data['disksize'])?$redis_data['disksize']:null;
        $iowait_r= isset($redis_data['iowait'])?$redis_data['iowait']:null;
        $load15_r= isset($redis_data['load15'])?$redis_data['load15']:null;

        $redis_data = [
            "hostId" => $hostid,
            "hostName" => $host,
            "updatetime" => $msec,
            "colletcionstamp" => time(),
            "ptype" => $ptype,
            "uuid" => $uuid,

            "cpu" => !is_null($cpuIdle) ?  $cpuIdle : $cpu_r,
            "diskutilization" => !is_null($diskutilization) ?  $diskutilization : $diskutilization_r,
            "disksize" => !is_null($disk_total) ?  $disk_total : $disksize_r,
            "iowait" => !is_null($iowait) ?  $iowait : $iowait_r,
            "load15" => !is_null($load15) ?  $load15 : $load15_r,
        ];

        $redis_data['typeFlag'] = ($ptype == 'kvm') ? 3: 2;
        $redis_data['deviceType'] = null;

        //Redis::command('HSET',[$hsname,$hostid,json_encode($redis_data)]);
        Redis::command('SET',[$hsname,json_encode($redis_data)]);
        Redis::command('EXPIRE',[$hsname,$expire]);
    }

    public static function vcentertop($uid,$request)
    {
        $cache_key = 'vcenter_top_data'.$uid;
        if(Cache::has($cache_key)) {
            $ret = Cache::get($cache_key);
        }else{
            $ret = new \stdClass();
            $hosts = Vcenter::VDB()->table('hosts')
                ->leftJoin('clusters','hosts.cid','=','clusters.id')
                ->leftJoin('datacenters','clusters.did','=','datacenters.id')
                ->leftJoin('vcenters','datacenters.vid','=','vcenters.id')
                ->where('vcenters.uid',$uid)->where('vcenters.id',$request->vcid)
                ->select('hosts.id as hid','hosts.name as h_name','hosts.vm_num','hosts.hardware_model',
                    'hosts.hardware_vendor', 'hosts.product_name','hosts.product_version','hosts.overallStatus'
                )->get();
            $hids = [];
            $vmids = [];
            //$ret->nodes = [];
            //$ret->edges = [];
            $ret->host_vm = new \stdClass();
            $ret->host_vm->nodes = [];
            $ret->host_vm->edges = [];
            $ret->host_store = new \stdClass();
            $ret->host_store->nodes = [];
            $ret->host_store->edges = [];
            $ret->vm_store = new \stdClass();
            $ret->vm_store->nodes = [];
            $ret->vm_store->edges = [];

            $host_nodeids = [];
            $vm_nodeids = [];
            $storages_nodeids = [];
            $host_nodes = [];
            $vm_nodes = [];
            $store_nodes = [];

            $hostvm = [];
            $hoststore_s = [];
            $hoststore_h = [];
            $vmstore_v = [];
            $vmstore_s = [];
            $id=0;
            foreach($hosts as $host){
                $id++;
                $arr_model = explode(':',$host->hardware_model);
                $title = new \stdClass();
                $key = $arr_model[0];
                $value = isset($arr_model[1]) ? $arr_model[1] : '';
                $title->$key = $value;
                $title->virtual_machines = $host->vm_num;
                $title->hardware_model = $host->hardware_model;
                $title->hardware_vendor = $host->hardware_vendor;
                $title->product_name = $host->product_name;
                $title->product_version = $host->product_version;

                $stemp = new \stdClass();
                $stemp->id = $id;
                $stemp->hostName = $host->h_name;
                $stemp->info = $title;
                $stemp->group = $id;
                $stemp->status = $host->overallStatus;
                $stemp->type = 'host';
                //array_push($ret->nodes,$stemp);
                array_push($hids,$host->hid);
                $host_nodeids[$host->hid] = $id;
                $host_nodes[$host->hid] = $stemp;
            }
            $vms = Vcenter::VDB()->table('virtual_machines')
                ->whereIn('hid',$hids)->get();
            foreach ($vms as $vm){
                $id++;
                $title = new \stdClass();
                $title->sysName = $vm->sysName;
                $title->toolsInstalled = $vm->toolsInstalled == 0 ? '未安装' : '已安装';
                $title->cpuUsage = $vm->cpuUsage . 'MHz';
                $title->memUsage = $vm->memUsage . 'MB';
                $title->diskUsage = $vm->diskUsage / (1024 * 1024 * 1024) . 'GB';

                $host_nodeid = $host_nodeids[$vm->hid];

                $stemp = new \stdClass();
                $stemp->id = $id;
                $stemp->hostName = $vm->name;
                $stemp->info = $title;
                $stemp->group = $host_nodeid;
                $stemp->status = $vm->overallStatus;
                $stemp->type = 'vm';
                //array_push($ret->nodes,$stemp);
                $vm_nodes[$vm->id] = $stemp;

                $stemp2 = new \stdClass();
                $stemp2->from = $host_nodeid;
                $stemp2->to = $id;
                //$stemp2->type = 'host_vm';
                //array_push($ret->edges,$stemp2);
                array_push($vmids,$vm->id);
                $vm_nodeids[$vm->id] = $id;

                $host_node = $host_nodes[$vm->hid];
                if(empty($hostvm[$vm->hid])){
                    array_push($ret->host_vm->nodes,$host_node);
                    $hostvm[$vm->hid] = $vm->hid;
                }
                array_push($ret->host_vm->nodes,$stemp);
                array_push($ret->host_vm->edges,$stemp2);
            }
            $storages = Vcenter::VDB()->table('datastores')
                ->leftJoin('datacenters','datastores.did','=','datacenters.id')
                ->leftJoin('vcenters','datacenters.vid','=','vcenters.id')
                ->where('vcenters.uid',$uid)->where('vcenters.id',$request->vcid)
                ->select('datastores.*')->get();
            foreach ($storages as $storage){
                $id++;
                $stemp = new \stdClass();
                $stemp->id = $id;
                $stemp->hostName = $storage->name;
                $stemp->type = 'store';
                //$stemp->info = $title;
                //array_push($ret->nodes,$stemp);
                $storages_nodeids[$storage->id] = $id;
                $store_nodes[$storage->id] = $stemp;
            }

            $hsdatas = Vcenter::VDB()->table('host_stores')
                ->leftJoin('datastores','host_stores.storeid','=','datastores.id')
                ->whereIn('host_stores.hostid',$hids)->select('host_stores.storeid','host_stores.hostid')
                ->get();
            foreach ($hsdatas as $hsdata){
                $hid = $hsdata->hostid;
                $storeid = $hsdata->storeid;
                $host_nodeid = $host_nodeids[$hid];
                $store_nodeid = $storages_nodeids[$storeid];
                $stemp2 = new \stdClass();
                $stemp2->from = $host_nodeid;
                $stemp2->to = $store_nodeid;
                //$stemp2->type = 'host_store';
                //array_push($ret->edges,$stemp2);

                if(empty($hoststore_h[$hid])){
                    $host_node = $host_nodes[$hid];
                    array_push($ret->host_store->nodes,$host_node);
                    $hoststore_h[$hid] = $hid;
                }
                if(empty($hoststore_s[$storeid])){
                    $store_node = $store_nodes[$storeid];
                    array_push($ret->host_store->nodes,$store_node);
                    $hoststore_s[$storeid] = $storeid;
                }

                array_push($ret->host_store->edges,$stemp2);
            }

            $vmstores = Vcenter::VDB()->table('vm_stores')
                ->leftJoin('datastores','vm_stores.storeid','=','datastores.id')
                ->whereIn('vm_stores.vmid',$vmids)->select('vm_stores.storeid','vm_stores.vmid')
                ->get();
            foreach ($vmstores as $vmstore){
                $storeid = $vmstore->storeid;
                $vm_nodeid = $vm_nodeids[$vmstore->vmid];
                $store_nodeid = $storages_nodeids[$storeid];
                $stemp2 = new \stdClass();
                $stemp2->from = $vm_nodeid;
                $stemp2->to = $store_nodeid;
                //$stemp2->type = 'vm_store';
                //array_push($ret->edges,$stemp2);

                if(empty($vmstore_v[$vmstore->vmid])){
                    $vm_node = $vm_nodes[$vmstore->vmid];
                    array_push($ret->vm_store->nodes,$vm_node);
                    $vmstore_v[$vmstore->vmid] = $vmstore->vmid;
                }
                if(empty($vmstore_s[$storeid])){
                    $store_node = $store_nodes[$storeid];
                    array_push($ret->vm_store->nodes,$store_node);
                    $vmstore_s[$storeid] = $storeid;
                }
                array_push($ret->vm_store->edges,$stemp2);
            }

            Cache::put($cache_key,$ret,30);
        }


        return $ret;
    }

    public static function kvmtop($uid,$request)
    {
        $cache_key = 'kvm_top_data'.$uid;
        if(Cache::has($cache_key)){
            $ret = Cache::get($cache_key);
        }else{
            $ret = new \stdClass();
            $ret->nodes = [];
            $ret->edges = [];
            $khost = Vcenter::VDB()->table('khosts')->where('userid',$uid)->where('id',$request->vcid)->first();
            if(!$khost) return $ret;
            $id = 1;
            $stemp = new \stdClass();
            $stemp->id = $id;
            $stemp->hostName = $khost->host;
            array_push($ret->nodes,$stemp);
            $local_id = $id;
            $kvms = Vcenter::VDB()->table('kvms')->where('khostid',$request->vcid)->get();
            foreach ($kvms as $kvm){
                $id++;
                $node = new \stdClass();
                $node->id = $id;
                $node->hostName = $kvm->name;
                $node->info = new \stdClass();
                //$node->info->state = $kvm->state == Vcenter::VIR_DOMAIN_RUNNING ? 'running' : 'stop';
                $node->info->MaxMen = ($kvm->MaxMem/1024) . 'MB';
                $node->info->MenUsed = ($kvm->Memory/1024) . 'MB';
                $node->info->CpuNum = $kvm->NrVirtCpu;
                $node->info->CpuTime = ($kvm->CpuTime/1000000000) . 'seconds';
                $node->status =  $kvm->state;

                $edge = new \stdClass();
                $edge->from = $local_id;
                $edge->to = $id;
                array_push($ret->nodes,$node);
                array_push($ret->edges,$edge);
            }
            Cache::put($cache_key,$ret,30);
        }

        return $ret;
    }

    public static function getVctopV1($uid,$request)
    {
        $ret = new \stdClass();
        if($request->type == 1){
            $ret = Vcenter::vcentertop($uid,$request);
        }else if($request->type == 2){
            $ret = Vcenter::kvmtop($uid,$request);
        }

        return $ret;
    }

    public static function getVclist($uid)
    {
        $hosts = Vcenter::VDB()->table('vcenters')
            ->select('vcenters.host','vcenters.id','vcenters.type',
                DB::raw('(select sum(hosts.vm_num) as vm_total from hosts 
                      left join clusters on hosts.cid = clusters.id 
                      left join datacenters on clusters.did = datacenters.id
                  where datacenters.vid = vcenters.id) as vm_total'),
                DB::raw('(select count(hosts.id) as host_total from hosts 
                      left join clusters on hosts.cid = clusters.id 
                      left join datacenters on clusters.did = datacenters.id
                  where datacenters.vid = vcenters.id) as host_total'),
                DB::raw('(select count(clusters.id) as cluster_total from clusters 
                      left join datacenters on clusters.did = datacenters.id
                  where datacenters.vid = vcenters.id) as cluster_total'),
                DB::raw('(select count(virtual_machines.id) as vm_poweredOff from virtual_machines 
                      left join hosts on virtual_machines.hid = hosts.id 
                      left join clusters on hosts.cid = clusters.id 
                      left join datacenters on clusters.did = datacenters.id
                  where datacenters.vid = vcenters.id and power_state = 1) as vm_poweredOff'),
                DB::raw('(select count(virtual_machines.id) as vm_suspended from virtual_machines 
                      left join hosts on virtual_machines.hid = hosts.id 
                      left join clusters on hosts.cid = clusters.id 
                      left join datacenters on clusters.did = datacenters.id
                  where datacenters.vid = vcenters.id and suspended = 1) as vm_suspended')
            )->where('vcenters.uid',$uid)->get();
        $kvms = Vcenter::VDB()->table('khosts')
            ->select('khosts.host','khosts.id','khosts.type',
                DB::raw('(select count(khostid) as total from kvms 
                      left join khosts on kvms.khostid = khosts.id 
                  where khosts.userid = '.$uid.') as total'),
                DB::raw('(select count(khostid) as running from kvms 
                      left join khosts on kvms.khostid = khosts.id 
                  where khosts.userid = '.$uid.' and state = '.Vcenter::VIR_DOMAIN_RUNNING.') as running'),
                DB::raw('(select count(khostid) as stop from kvms 
                      left join khosts on kvms.khostid = khosts.id 
                  where khosts.userid = '.$uid.' and state <> '.Vcenter::VIR_DOMAIN_RUNNING.') as stop')
                )->where('khosts.userid',$uid)->get();
        $data = [];
        foreach ($hosts as $host){
            $ret = new \stdClass();
            $ret->host = $host->host;
            $ret->id = $host->id;
            $ret->type = $host->type;
            $ret->info = new \stdClass();
            $ret->info->vm_total = (int)$host->vm_total;
            $ret->info->host_total = (int)$host->host_total;
            //$ret->info->cluster_total = (int)$host->cluster_total;
            $ret->info->vm_poweredOff = (int)$host->vm_poweredOff;
            $ret->info->vm_suspended = (int)$host->vm_suspended;

            array_push($data,$ret);
        }
        foreach ($kvms as $kvm){
            $ret = new \stdClass();
            $ret->host = $kvm->host;
            $ret->id = $kvm->id;
            $ret->type = $kvm->type;
            $ret->info = new \stdClass();
            $ret->info->vm_total = (int)$kvm->total;
            $ret->info->running = (int)$kvm->running;
            $ret->info->stop = (int)$kvm->stop;

            array_push($data,$ret);
        }
        return $data;
    }

    public static function kvm($host,$uid,$data)
    {
        $khostid = md5(md5($uid).md5($host));
        Vcenter::VDB()->table('kvms')->where('khostid',$khostid)->delete();
        foreach ($data as $item){
            $name = $item->Name;
            $vmid = md5(md5($uid).md5($name));

            $save_data = [
                'id' => $vmid,
                'khostid' => $khostid,
                'name' => $name,
                'state' => isset($item->Info->State) ? $item->Info->State : 0,
                'MaxMem' => isset($item->Info->MaxMem) ? $item->Info->MaxMem : null,
                'Memory' => isset($item->Info->Memory) ? $item->Info->Memory : null,
                'NrVirtCpu' => isset($item->Info->NrVirtCpu) ? $item->Info->NrVirtCpu : null,
                'CpuTime' => isset($item->Info->CpuTime) ? $item->Info->CpuTime : null,
            ];
            Vcenter::dbSave($vmid,'kvms',$save_data);
            Vcenter::saveToApmsys($name,$uid,'kvm',3);
            Vcenter::recevieDataPutRedis($name,$uid,'kvm');
        }
    }

}