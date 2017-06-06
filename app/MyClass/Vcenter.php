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

    public static function switchByType($item,$uid,$vid)
    {
        if(empty($item->Name) || empty($item->Value)) return;
        $path = $item->Path;
        $p_arr = explode("/",trim($path,'/'));
        $count_p_arr = count($p_arr);
        $dc_name = $p_arr[0];
        $did = md5(md5($vid).md5($dc_name));
        $type = $item->Type;
        $name = str_replace(" ","-",$item->Name);
        switch($type){
            case Vcenter::DATACENTER:
                $param = ['id' => $did,'vid' => $vid,'name' => $name,'value' => $item->Value];
                Vcenter::dbSave($did,"datacenters",$param);
                break;
            case Vcenter::FOLDER:
                $fid = md5(md5($did).md5($name));
                $pid = null;
                if($count_p_arr >= 3){
                    $pf_name = $p_arr[$count_p_arr - 2];
                    $pid = md5(md5($did).md5($pf_name));
                }
                $param =['id' => $fid,'did' => $did,'name' => $name,'value' => $item->Value,'pid' => $pid];
                Vcenter::dbSave($fid,"folders",$param);
                break;
            case Vcenter::CLUSTER:
                $cid = md5(md5($did).md5($name));
                $param =['id' => $cid,'did' => $did,'name' => $name,'value' => $item->Value];
                Vcenter::dbSave($cid,"clusters",$param);
                break;
            case Vcenter::HOST:
                if(empty($p_arr[2])) continue;
                $c_name = $p_arr[2];
                $cid = md5(md5($did).md5($c_name));
                $hid = md5(md5($did).md5($name));
                $param =['id' => $hid,'cid' => $cid,'name' => $name,'value' => $item->Value];
                Vcenter::dbSave($hid,"hosts",$param);
                Vcenter::recevieDataPutRedis($name,$uid,$item);
                Vcenter::saveToApmsys($name,$uid,$item);
                break;
            case Vcenter::VM:
                $vid = md5(md5($did).md5($name));
                if($count_p_arr == 4){
                    if(empty($p_arr[2])) continue;
                    $f_name = $p_arr[2];
                    $fid = md5(md5($did).md5($f_name));
                    $param = ['fid' => $fid];
                    Vcenter::dbUpdate($vid,"virtual_machines",$param);
                }
                if($count_p_arr == 5){
                    if(empty($p_arr[3])) continue;
                    $h_name = $p_arr[3];
                    $hid = md5(md5($did).md5($h_name));

                    $param =['id' => $vid,'hid' => $hid,'name' => $name,'value' => $item->Value];
                    Vcenter::dbSave($vid,"virtual_machines",$param);
                    Vcenter::recevieDataPutRedis($name,$uid,$item);
                    Vcenter::saveToApmsys($name,$uid,$item);
                }

                break;

            case Vcenter::RESOURCEPOOL:
                if(empty($p_arr[2])) continue;
                $c_name = $p_arr[2];
                $cid = md5(md5($did).md5($c_name));
                $rid = md5(md5($did).md5($name));
                $param =['id' => $rid,'cid' => $cid,'name' => $name,'value' => $item->Value];
                Vcenter::dbSave($rid,"resource_pools",$param);
                break;
            case Vcenter::DATASTORE:
                $vid = md5(md5($did).md5($name));
                if($count_p_arr == 3){
                    if(empty($p_arr[1])) continue;
                    $f_name = $p_arr[1];
                    $fid = md5(md5($did).md5($f_name));
                    $param = ['fid' => $fid];
                    Vcenter::dbUpdate($vid,"datastores",$param);
                }
                if($count_p_arr == 5){
                    if(empty($p_arr[3])) continue;
                    $h_name = $p_arr[3];
                    $hid = md5(md5($did).md5($h_name));
                    $param =['id' => $vid,'hid' => $hid,'name' => $name,'value' => $item->Value];
                    Vcenter::dbSave($vid,"datastores",$param);
                }
                break;
            case Vcenter::NETWORK:
                $vid = md5(md5($did).md5($name));
                if($count_p_arr == 3){
                    if(empty($p_arr[1])) continue;
                    $f_name = $p_arr[1];
                    $fid = md5(md5($did).md5($f_name));
                    $param = ['fid' => $fid];
                    Vcenter::dbUpdate($vid,"networks",$param);
                }
                if($count_p_arr == 5){
                    if(empty($p_arr[3])) continue;
                    $h_name = $p_arr[3];
                    $hid = md5(md5($did).md5($h_name));
                    $param =['id' => $vid,'hid' => $hid,'name' => $name,'value' => $item->Value];
                    Vcenter::dbSave($vid,"networks",$param);
                }

                break;
        }
    }

    public static function dbSave($id,$table,$param)
    {
        $res = Vcenter::VDB()->table($table)->where('id',$id)->first();
        if(!$res){
            Vcenter::VDB()->table($table)->insert($param);
        }
    }

    public static function dbUpdate($id,$table,$param)
    {
        Vcenter::VDB()->table($table)->where('id',$id)->update($param);
    }

    public static function saveToApmsys($hostname,$uid,$item)
    {
        $hostid = md5(md5($uid).md5($hostname));
        $ptype = $item->Type;
        $res = DB::table('host')->where('id',$hostid)->first();
        if(!$res){
            DB::table('host')->insert(['id' => $hostid,'ptype' => $ptype,'host_name' => $hostname,'type_flag' => 2,'createtime' => date('Y-m-d H:i:s')]);
        }
    }

    public static function recevieDataPutRedis($host,$uid,$data)
    {
        $expire = 3*24*3600;
        $hostid = md5(md5($uid).md5($host));
        list($t1, $t2) = explode(' ', microtime());
        $msec = (float)sprintf('%.0f', (floatval($t1) + floatval($t2)) * 1000);
        $hsname = "HOST_DATA_".$uid."_".$host;

        $redis_data = [
            "hostId" => $hostid,
            "hostName" => $host,
            "updatetime" => $msec,
            "colletcionstamp" => time(),
            "ptype" => $data->Type,
        ];

        //Redis::command('HSET',[$hsname,$hostid,json_encode($redis_data)]);
        Redis::command('SET',[$hsname,json_encode($redis_data)]);
        Redis::command('EXPIRE',[$hsname,$expire]);
    }


}