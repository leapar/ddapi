<?php
/**
 * Created by PhpStorm.
 * User: admin
 * Date: 2016/11/28
 * Time: 14:30
 */
namespace App\MyClass;

use App\Host;
use App\HostUser;
use App\Metric as MetricModel;
use App\Tag;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use DB;
use Log;

class MyRedisCache
{
    public static function initRedis()
    {
        $redis = new \Redis();
        $redis->connect('172.29.231.177',6379);
        $redis->auth(123456);
        return $redis;
    }

    /**
     * 设置redis 缓存
     * @param $key
     * @param $value
     */
    public static function setRedisCache($key,$value)
    {
        $value = \GuzzleHttp\json_encode($value);
        //Redis::command('HSET', ['name', 5, 10]);
        Cache::put($key, $value,10);
    }

    /**
     * 获取 redis 缓存数据
     * @param $key
     * @return mixed
     */
    public static function getRedisCache($key)
    {
        $value = Cache::get($key);
        return \GuzzleHttp\json_decode($value);
    }

    /**
     * 获取metric 及 tags
     * @param $uid
     * @return array
     */
    public function metricCache($uid)
    {
        $redis = MyRedisCache::initRedis();
        $metric_key = 'search:metrics:'.$uid.':uid='.$uid;
        $metrics = $redis->hKeys($metric_key);
        if(empty($metrics)) return [];
        sort($metrics);
        $result = [];
        $pipe = $redis->multi(\Redis::PIPELINE);
        foreach($metrics as $key => $metric){
            $tag_key = "search:mts:".$uid.":".$metric;
            $pipe->hKeys($tag_key);
        }
        $replies = $pipe->exec();
        //$custom_tags = MyApi::getCustomTagsByHost($uid);
        $custom_tags = MyRedisCache::getCustomTags($uid);
        foreach($metrics as $key => $metric){
            $temp = new \stdClass();
            $temp->metric = $metric;
            $tags = $replies[$key];
            $tags_temp = [];
            foreach($tags as $val){
                $item = explode(",",$val);
                foreach($item as $value){
                    $arr = explode("=",$value);
                    if($arr[0] != "uid"){
                        array_push($tags_temp,$arr[0].':'.$arr[1]);
                    }
                   /* if($arr[0] === 'host' && !empty($arr[1])){
                        if(isset($custom_tags->$arr[1])) $tags_temp = array_merge($tags_temp,$custom_tags->$arr[1]);
                    }*/
                }
            }
            $tags_temp = array_merge($tags_temp,$custom_tags);
            $tags_temp = array_unique($tags_temp);
            sort($tags_temp);
            $temp->tags = $tags_temp;

            array_push($result,$temp);
        }

        return $result;
    }

    public function tagsCache($uid)
    {
        $redis = MyRedisCache::initRedis();
        $metric_key = 'search:metrics:'.$uid.':uid='.$uid;
        $metrics = $redis->hKeys($metric_key);
        if(empty($metrics)) return [];
        sort($metrics);
        $pipe = $redis->multi(\Redis::PIPELINE);
        foreach($metrics as $key => $metric){
            $tag_key = "search:mts:".$uid.":".$metric;
            $pipe->hKeys($tag_key);
        }
        $replies = $pipe->exec();
        //$custom_tags = MyApi::getCustomTagsByHost($uid);
        $custom_tags = MyRedisCache::getCustomTags($uid);
        $result = [];
        foreach($metrics as $key => $metric){
            $tags = $replies[$key];
            $tags_temp = [];
            foreach($tags as $val){
                $item = explode(",",$val);
                foreach($item as $value){
                    $arr = explode("=",$value);
                    if($arr[0] != "uid"){
                        array_push($tags_temp,$arr[0].':'.$arr[1]);
                    }
                    /*if($arr[0] === 'host' && !empty($arr[1])){
                        if(isset($custom_tags->$arr[1])) $tags_temp = array_merge($tags_temp,$custom_tags->$arr[1]);
                    }*/
                }
            }
            $result = array_merge($result,$tags_temp);
        }
        $result = array_merge($result,$custom_tags);
        $result = array_unique($result);
        sort($result);

        return $result;
    }

    public static function setCustomTags($metrics_in, $host, $uid)
    {
        $url = MyApi::TAG_PUT_URL . '/api/host/tag?uid='.$uid.'&host='.$host;

        $agent = MyApi::getHostTagAgent($metrics_in);
        //$agent = 'host-cf-1,host-cf-2';
        if(empty($agent)) return;
        $data = json_encode([['agent' => $agent]]);
        $res = MyApi::httpPost($url, $data, true);
        Log::info('put-host-tag === ' . $res);
    }

    public static function getCustomTags($uid)
    {
        $redis = MyRedisCache::initRedis();
        $key = 'search:hts:'.$uid.":*";
        $tags = $redis->keys($key);
        if(empty($tags)) return [];
        $pipe = $redis->multi(\Redis::PIPELINE);
        foreach($tags as $t_key){
            $pipe->hGetAll($t_key);
        }
        $replies = $pipe->exec();
        $res = [];
        foreach($replies as $item){
            foreach($item as $tagk => $tagv){
                array_push($res,$tagk.':'.$tagv);
            }
        }
        return $res;
    }

    public static function getMetricByService($slug,$uid,$type)
    {
        $metric_key = 'search:mts:'.$uid.':'.$slug.'.*';
        $metrics = Redis::keys($metric_key);
        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = 'success';
        $ret->result = [];

        if($type == 'show'){
            $res = new \stdClass();
            $x =0;$y=0;$w=3;$h=2;
            $data = [];
            foreach($metrics as $item){
               array_push($data,[null,$x,$y,$w,$h]);
                if($x >= 9){
                    $x = 0;
                    $y += $h;
                }else{
                    $x += $w;
                }
            }
            $res->order  = json_encode($data);
            $ret->result = $res;
        }
        if($type == 'chart'){
            foreach($metrics as $item){
                $res = new \stdClass();
                $res->metrics = [];
                $arr = explode(':',$item);
                $metric = $arr[3];
                $m = new \stdClass();
                $m->metric = $metric;
                array_push($res->metrics,$m);
                array_push($ret->result,$res);
            }
        }

        return $ret;
    }
}