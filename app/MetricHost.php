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
use Log;
use Cache;
use App\MyClass\MyRedisCache;
use Illuminate\Support\Facades\Redis;

class MetricHost extends Model
{
    protected $table = "metric_host";
    public $timestamps = false;

    public static function saveMetricHost($data)
    {
        DB::table('metric_host')->insert($data);
    }

    public static function findByHostid($hostid)
    {
        return DB::table('metric_host')->where('hostid',$hostid)->first();
    }

    public static function saveMetricHostJob($hostid,$service_checks,$check_run)
    {
        $data = [];
        !is_null($service_checks) ? $data['service_checks'] = json_encode($service_checks) : '';
        !is_null($check_run) ? $data['check_run'] = json_encode($check_run) : '';

        $res = MetricHost::findByHostid($hostid);
        if($res){
            DB::table('metric_host')->where('hostid',$hostid)->update($data);
        }else{
            $data['hostid'] = $hostid;
            DB::table('metric_host')->insert($data);
        }

        //$sql = "replace into metric_host(hostid,service_checks,check_run) values (?,?,?)";

        //DB::insert($sql,[$hostid,$service_checks,$check_run]);
    }

    public static function updateMetricHost($data,$id)
    {
        DB::table('metric_host')->where('id',$id)->update($data);
    }

    /**
     * 检查 metric_host 是否存在
     * @param $hostid
     * @param $metricid
     * @return bool
     */
    public static function findByMetricidHostId($hostid,$metricid)
    {
        $node_host = MyRedisCache::getRedisCache("metric_host_cache");
        if(empty($node_host)){
            MetricHost::updateMetricHostCache();
            $node_host = MyRedisCache::getRedisCache("metric_host_cache");
        }
        foreach($node_host as $item){
            if($item->hostid == $hostid && $item->metricid == $metricid){
                return $item;
            }
        }
        return false;
    }

    public static function updateMetricHostCache()
    {
        $res = DB::table('metric_host')->select('hostid','metricid')->get();
        MyRedisCache::setRedisCache("metric_host_cache",$res);
    }

}