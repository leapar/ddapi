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

class MetricHost extends Model
{
    protected $table = "metric_host";

    public static function saveMetricHost($data)
    {
        //Log::info("saveMetricHost======".json_encode($data));
        $res = MetricHost::findByMetricidHostId($data['hostid'],$data['metricid']);
        if($res){
            //MetricHost::updateMetricHost($data,$res->id);
        }else{
            $data["id"] = md5(uniqid());
            DB::table('metric_host')->insert($data);
        }
    }

    public static function updateMetricHost($data,$id)
    {
        DB::table('metric_host')->where('id',$id)->update($data);
    }

    public static function findByMetricidHostId($hostid,$metricid)
    {
        $res = DB::table('metric_host')
                ->where('hostid', $hostid)
                ->where('metricid',$metricid)
                ->first();
        return $res;
    }
}