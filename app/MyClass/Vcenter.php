<?php
/**
 * Created by PhpStorm.
 * User: cheng_f
 * Date: 2017/2/21
 * Time: 10:56
 */

namespace App\MyClass;

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

    public static function VDB()
    {
        return app('db')->connection("mysql_vcenter");
    }

    public static function saveVcenter($dataCenter,$uid,$vid)
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
                    Vcenter::saveToApmsys($host_name,$uid,$type,$uuid);

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
                        Vcenter::saveToApmsys($vm_name,$uid,$vmtype,$uuid,$logo);

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

    public static function saveToApmsys($hostname,$uid,$ptype,$uuid,$logo=null)
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
            'type_flag' => 2,
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
        if(is_null($cpuIdle)){
            $redis_data = [
                "hostId" => $hostid,
                "hostName" => $host,
                "updatetime" => $msec,
                "colletcionstamp" => time(),
                "ptype" => $ptype,
                "uuid" => $uuid,

                "cpu" => null,
                "diskutilization" => null,
                "disksize" => null,
                "iowait" => null,
                "load15" => null
            ];
        }else if(!empty($redis_data)){
            !is_null($cpuIdle) ? $redis_data['cpu'] = $cpuIdle : null;
            !is_null($diskutilization) ? $redis_data['diskutilization'] = $diskutilization : null;
            !is_null($disk_total) ? $redis_data['disksize'] = $disk_total : null;
            !is_null($iowait) ? $redis_data['iowait'] = $iowait : null;
            !is_null($load15) ? $redis_data['load15'] = $load15 : null;
        }

        //Redis::command('HSET',[$hsname,$hostid,json_encode($redis_data)]);
        Redis::command('SET',[$hsname,json_encode($redis_data)]);
        Redis::command('EXPIRE',[$hsname,$expire]);
    }

    public static function getVctopV1($uid,$request)
    {
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
            $stemp->overallStatus = $host->overallStatus;
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
            $stemp->overallStatus = $vm->overallStatus;
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

        return $ret;
    }

    public static function getVctop($uid,$request)
    {
        $ret = new \stdClass();
        $hosts = Vcenter::VDB()->table('hosts')
            ->leftJoin('clusters','hosts.cid','=','clusters.id')
            ->leftJoin('datacenters','clusters.did','=','datacenters.id')
            ->leftJoin('vcenters','datacenters.vid','=','vcenters.id')
            ->where('vcenters.uid',$uid)->where('vcenters.id',$request->vcid)
            ->select('hosts.id as hid','hosts.name as h_name','hosts.vm_num','hosts.hardware_model',
                'hosts.hardware_vendor', 'hosts.product_name','hosts.product_version'
            )->get();
        $hids = [];
        $res = [];
        foreach($hosts as $host){
            $stmp = new \stdClass();
            $stmp->hid = $host->hid;
            $stmp->h_name = $host->h_name;
            $stmp->vm_num = $host->vm_num;
            $stmp->hardware_model = $host->hardware_model;
            $stmp->hardware_vendor = $host->hardware_vendor;
            $stmp->product_name = $host->product_name;
            $stmp->product_version = $host->product_version;
            $stmp->vm = [];
            $stmp->stores = [];
            if(!isset($res[$host->hid])){
                $res[$host->hid] = new \stdClass();
            }
            $res[$host->hid] = $stmp;
            array_push($hids,$host->hid);
        }

        $vmdatas = Vcenter::VDB()->table('virtual_machines')
            ->whereIn('hid',$hids)->get();
        foreach($vmdatas as $data){
            $vm = $res[$data->hid];
            if(!isset($vm->vm)) $vm->vm = [];
            $data->vmstore = Vcenter::VDB()->table('vm_stores')
                ->leftJoin('datastores','vm_stores.storeid','=','datastores.id')
                ->where('vm_stores.vmid',$data->id)->select('datastores.name','datastores.value')
                ->get();
            unset($data->id);unset($data->hid);unset($data->fid);unset($data->value);
            array_push($vm->vm,$data);
        }


        $hsdatas = Vcenter::VDB()->table('host_stores')
            ->leftJoin('datastores','host_stores.storeid','=','datastores.id')
            ->whereIn('host_stores.hostid',$hids)->select('host_stores.hostid','datastores.name','datastores.value')
            ->get();
        foreach($hsdatas as $data){
            $vm = $res[$data->hostid];
            if(!isset($vm->stores)) $vm->stores = [];
            unset($data->hostid);
            array_push($vm->stores,$data);
        }

        $ret->vm_host = new \stdClass();
        $ret->vm_host->nodes = [];
        $ret->vm_host->edges = [];
        $ret->vm_store = new \stdClass();
        $ret->vm_store->nodes = [];
        $ret->vm_store->edges = [];
        $ret->host_store = new \stdClass();
        $ret->host_store->nodes = [];
        $ret->host_store->edges = [];
        $id = 0;
        foreach ($res as $host){
            $id++;
            $arr_model = explode(':',$host->hardware_model);
            $title = new \stdClass();
            $key = $arr_model[0];
            $title->$key= isset($arr_model[1]) ? $arr_model[1] : '';
            $title->virtual_machines = $host->vm_num;
            $title->hardware_model = $host->hardware_model;
            $title->hardware_vendor = $host->hardware_vendor;
            $title->product_name = $host->product_name;
            $title->product_version = $host->product_version;

            $stemp = new \stdClass();
            $stemp->id = $id;
            $stemp->hostName = $host->h_name;
            $stemp->info = $title;
            array_push($ret->vm_host->nodes,$stemp);
            array_push($ret->host_store->nodes,$stemp);

            $vms = $host->vm;
            $hstores = $host->stores;
            $h_nodeid = $id;
            foreach ($hstores as $hstore){
                $id ++;
                $stemp = new \stdClass();
                $stemp->id = $id;
                $stemp->hostName = $hstore->name;
                array_push($ret->host_store->nodes,$stemp);

                $stemp2 = new \stdClass();
                $stemp2->from = $h_nodeid;
                $stemp2->to = $id;
                //$stemp2->arrows = 'to';
                array_push($ret->host_store->edges,$stemp2);
            }
            foreach ($vms as $vm){
                $id ++;
                $stemp = new \stdClass();
                $stemp->id = $id;
                $stemp->hostName = $vm->name;
                array_push($ret->vm_host->nodes,$stemp);
                array_push($ret->vm_store->nodes,$stemp);

                $stemp2 = new \stdClass();
                $stemp2->from = $h_nodeid;
                $stemp2->to = $id;
                //$stemp2->arrows = 'to';
                array_push($ret->vm_host->edges,$stemp2);

                $vmstores = $vm->vmstore;
                $vm_nodeid = $id;
                foreach ($vmstores as $vmstore){
                    $id ++;
                    $stemp = new \stdClass();
                    $stemp->id = $id;
                    $stemp->hostName = $vmstore->name;
                    array_push($ret->vm_store->nodes,$stemp);

                    $stemp2 = new \stdClass();
                    $stemp2->from = $vm_nodeid;
                    $stemp2->to = $id;
                    //$stemp2->arrows = 'to';
                    array_push($ret->vm_store->edges,$stemp2);
                }
            }
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
        return $data;
    }

}