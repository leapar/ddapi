<?php
/**
 * Created by PhpStorm.
 * User: admin
 * Date: 2016/11/7
 * Time: 18:03
 */

namespace App;

use Illuminate\Database\Eloquent\Model;
use DB;
use Cache;
use Illuminate\Support\Facades\Redis;
use Log;
use App\MyClass\MyRedisCache;
use App\Host;
use Mockery\CountValidator\Exception;

class Metric extends Model
{
    protected $table = "metric";

    /**
     * 检查metric 是否存在，如果存在返回 array(id)
     * @param $integration
     * @return array|bool
     */
    public static function findByIntegration($integration)
    {
        //return DB::table('metric')->where('integration',$integration)->first();
        $metric = MyRedisCache::getRedisCache('metric_cache');
        if(empty($metric)){
            Metric::updateMetricCache();
            $metric = MyRedisCache::getRedisCache('metric_cache');
        }
        foreach($metric as $item){
            if($item->integration == $integration){
                return $item;
            }
        }

        return false;
    }

    /**
     * 查看metric_node 是否存在 如果存在返回 array(id)
     * @param $metric_name
     * @return array|bool
     */
    public static function findMetricNodeByMetric($metric_name)
    {
        //return DB::table('metric_node')->where('metric_name',$metric_name)->first();
        $metric_node = MyRedisCache::getRedisCache("metric_node_cache");
        if(empty($metric_node)){
            Metric::updateMetricNodeCache();
            $metric_node = MyRedisCache::getRedisCache("metric_node_cache");
        }
        foreach($metric_node as $item){
            if($item->metric_name == $metric_name){
                return $item;
            }
        }
        /*if(in_array($metric_name,$metric_node)){
            return array_keys($metric_node, $metric_name);
        }*/
        return false;
    }

    /**
     * 检查 node_host 是否存在
     * @param $nodeid
     * @param $hostid
     * @return bool
     */
    public static function findNodeHost($nodeid,$hostid)
    {
        $node_host = MyRedisCache::getRedisCache("node_host_cache");
        if(empty($node_host)){
            Metric::updateNodeHostCache();
            $node_host = MyRedisCache::getRedisCache("node_host_cache");
        }
        foreach($node_host as $item){
            if($item->hostid == $hostid && $item->nodeid == $nodeid){
                return $item;
            }
        }
        return false;
        //return DB::table('node_host')->where('hostid',$hostid)->where('nodeid',$nodeid)->first();
    }

    /**
     * 保存metric
     * @param $data
     */
    public static function saveMetric($data)
    {
        DB::table('metric')->insert($data);
        //Metric::updateMetricCache();
    }

    /**
     * 保存metric_node
     * @param $data
     */
    public static function saveMetricNode($data)
    {
        DB::table('metric_node')->insert($data);
        //Metric::updateMetricNodeCache();
    }

    /**
     * 更新 metric 缓存数据
     */
    public static function updateMetricCache()
    {
        $res = DB::table('metric')->select('integration', 'id')->get();
        MyRedisCache::setRedisCache("metric_cache",$res);
    }

    /**
     * 更新 metric_node 缓存数据
     */
    public static function updateMetricNodeCache()
    {
        $res = DB::table('metric_node')->select('integrationid','metric_name','id')->get();
        MyRedisCache::setRedisCache("metric_node_cache",$res);
    }

    /**
     * 更新 node_host 缓存数据
     */
    public static function updateNodeHostCache()
    {
        $res = DB::table('node_host')->select('nodeid','hostid')->get();
        MyRedisCache::setRedisCache("node_host_cache",$res);
    }

    /**
     * 保存缓存
     * @param $key
     * @param $value
     */
    public static function saveCache($key,$value)
    {
        Cache::set($key, $value);
    }

    public static function saveMetricHostNode($sub)
    {
        try{
            log::info("metric_start ==> " . time());
            if(!isset($sub->metric)) return;
            $metricname = $sub->metric;
            $hostname = $sub->tags->host;
            $uid = $sub->tags->uid;
            $host = Host::findHostByPname($hostname,$uid);
            log::info("find_host ==> " . time());
            if(!$host) return;

            DB::beginTransaction();

            //1,metric_node 是否存在
            $res1= Metric::findMetricNodeByMetric($metricname);
            log::info("find_metric_node ==> " . time());
            if($res1){
                //1.1存在
                $nodeid = $res1->id;
                $metricid = $res1->integrationid;
            }else{
                //1.2 不存在
                $temp = explode(".",$metricname);
                $integration = $temp[0];

                //1.2.1保存 metric
                $res2 = Metric::findByIntegration($integration);
                log::info("find_metric ==> " . time());
                if(!$res2){
                    $metricid = md5(uniqid() . rand(1111,9999));
                    Metric::saveMetric(['id' => $metricid,'integration' => $integration]);
                    log::info("save_metric ==> " . time());
                }else{
                    $metricid = $res2->id;
                }

                //1.2.2保存 metric_node
                $nodeid = md5(uniqid() . rand(1111,9999));
                $data = [
                    "id" => $nodeid,
                    "integrationid" => $metricid,
                    "subname" => isset($temp[1]) ? $temp[1] : "",
                    "metric_name" => $metricname
                ];
                Metric::saveMetricNode($data);
                log::info("save_metric_node ==> " . time());
            }
            //2,保存 node_host
            $res3 = Metric::findNodeHost($nodeid,$host->id);
            log::info("find_node_host ==> " . time());
            if(!$res3){
                $nodehostid = md5(uniqid() . rand(1111,9999));
                DB::table('node_host')->insert(['id'=>$nodehostid,'nodeid'=>$nodeid,'hostid'=>$host->id]);
                log::info("save_node_host ==> " . time());
            }

            //3,保存 metric_host
            $res4 = MetricHost::findByMetricidHostId($host->id,$metricid);
            log::info("find_metric_host ==> " . time());
            if(!$res4){
                $data = [
                    'metricid' => $metricid,
                    'hostid' => $host->id,
                    'status' => 0,
                    'id' => md5(uniqid() . rand(1111,9999))
                ];
                MetricHost::saveMetricHost($data);
                log::info("save_metric_host ==> " . time());
            }
            DB::commit();

            if(!$res1){
                Metric::updateMetricNodeCache();
                if(!$res2){
                    Metric::updateMetricCache();
                }
            }
            if(!$res3){
                Metric::updateNodeHostCache();
            }
            if(!$res4){
                MetricHost::updateMetricHostCache();
            }
        }catch(Exception $e){
            Log::error($e->getMessage());
            DB::rollBack();
        }

    }

    public static function findMetricHostByHostid($hostid)
    {
        return DB::table('metric_host')
            ->leftJoin('metric','metric_host.metricid','=','metric.id')
            ->where('metric_host.hostid',$hostid)
            ->select('metric.integration','metric_host.metricid','metric_host.status')
            ->get();
    }

    public static function findMetricNodeByHostid($hostid,$metricid=null)
    {
        $metric_node = DB::table('node_host')
            ->leftJoin('metric_node','node_host.nodeid','=','metric_node.id');
        if(!is_null($metricid)){
            $metric_node->where('metric_node.integrationid',$metricid);
        }
        $metric_node->where('node_host.hostid',$hostid);
        return $metric_node->select('metric_node.metric_name','metric_node.plural_unit','metric_node.per_unit')->get();
    }

    public static function findNodeHostByHostid($hostid)
    {
        $metric_node = DB::table('metric_node')
            ->leftJoin('node_host','node_host.nodeid','=','metric_node.id')
            ->leftJoin('metric','metric_node.integrationid','=','metric.id')
            ->where('node_host.hostid',$hostid);
        return $metric_node->select('metric.id as metricid','metric_node.metric_name','metric_node.plural_unit','metric_node.per_unit')->get();
    }
}