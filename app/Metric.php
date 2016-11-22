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
use Log;
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
        $metric = Cache::get('metric_cache');
        if(empty($metric)){
            Metric::updateMetricCache();
            $metric = Cache::get('metric_cache');
        }
        if(in_array($integration,$metric)) {
            return array_keys($metric, $integration);
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
        $metric_node = Cache::get("metric_node_cache");
        if(empty($tag_host)){
            Metric::updateMetricNodeCache();
            $metric_node = Cache::get("metric_node_cache");
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

    public static function findNodeHost($nodeid,$hostid)
    {
        return DB::table('node_host')->where('hostid',$hostid)->where('nodeid',$nodeid)->first();
    }

    /**
     * 保存metric
     * @param $data
     */
    public static function saveMetric($data)
    {
        DB::table('metric')->insert($data);
        Metric::updateMetricCache();
    }

    /**
     * 保存metric_node
     * @param $data
     */
    public static function saveMetricNode($data)
    {
        DB::table('metric_node')->insert($data);
        Metric::updateMetricNodeCache();
    }

    /**
     * 更新 metric 缓存数据
     */
    public static function updateMetricCache()
    {
        $res = DB::table('metric')->pluck('integration', 'id');
        Metric::saveCache("metric_cache",$res->toArray());
    }

    /**
     * 更新 metric_node 缓存数据
     */
    public static function updateMetricNodeCache()
    {
        $res = DB::table('metric_node')->select('integrationid','metric_name','id')->get();
        Metric::saveCache("metric_node_cache",$res);
    }

    /**
     * 保存缓存
     * @param $key
     * @param $value
     */
    public static function saveCache($key,$value)
    {
        Cache::forever($key, $value);
    }

    public static function saveMetricHostNode($sub)
    {
        try{
            DB::beginTransaction();

            $metricname = $sub->metric;
            $hostname = $sub->tags->host;
            $host = Host::findHostByPname($hostname);

            if(!$host) return;

            //1,metric_node 是否存在
            $res= Metric::findMetricNodeByMetric($metricname);
            if($res){
                //1.1存在
                $nodeid = $res->id;
                $metricid = $res->integrationid;
            }else{
                //1.2 不存在
                $temp = explode(".",$metricname);
                $integration = $temp[0];

                //1.2.1保存 metric
                $res = Metric::findByIntegration($integration);
                if(!$res){
                    $metricid = md5(uniqid());
                    Metric::saveMetric(['id' => $metricid,'integration' => $integration]);
                }else{
                    $metricid = $res['0'];
                }

                //1.2.2保存 metric_node
                $nodeid = md5(uniqid());
                $data = [
                    "id" => $nodeid,
                    "integrationid" => $metricid,
                    "subname" => isset($temp[1]) ? $temp[1] : "",
                    "metric_name" => $metricname
                ];
                Metric::saveMetricNode($data);

                Metric::updateMetricCache();
                Metric::updateMetricNodeCache();
            }
            //2,保存 node_host
            $res = Metric::findNodeHost($nodeid,$host->id);
            if(!$res){
                $nodehostid = md5(uniqid());
                DB::table('node_host')->insert(['id'=>$nodehostid,'nodeid'=>$nodeid,'hostid'=>$host->id]);
            }

            //3,保存 metric_host
            $data = [
                'metricid' => $metricid,
                'hostid' => $host->id,
                'status' => 0
            ];
            MetricHost::saveMetricHost($data);

            DB::commit();
        }catch(Exception $e){
            DB::rollBack();
        }

    }
}