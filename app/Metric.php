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


    public static function findMetricTemplateByid($id,$uid)
    {
        $res = DB::table('metric_templates')->where('template_id',$id)->where('user_id',$uid)->first();
        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = 'success';
        if(empty($res)){
            Log::info('findMetricTemplateByid='.$id);
            $ret->message = 'fail 未能获取模板';
            $ret->result = [];
            return $ret;
        }
        $res->updated_at = strtotime($res->updated_at) * 1000;
        $res->created_at = strtotime($res->created_at) * 1000;
        $res->tag_key = json_decode($res->tag_key);
        $res->selected_tags = json_decode($res->selected_tags);
        $res->selected_metrics = json_decode($res->selected_metrics);
        $ret->result = $res;

        return $ret;
    }
}