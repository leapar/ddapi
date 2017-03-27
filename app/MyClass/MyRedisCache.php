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

class MyRedisCache
{
    private $redis_host = '127.0.0.1';
    private $redis_port = '6379';

    public function __construct($host,$port)
    {
        $this->redis_host = $host;
        $this->redis_port = $port;
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
     * 设置用户 host tag metric metric_node 缓存
     */
    public static function setUserCache()
    {
        $users = DB::table('user')->select('id')->get();
        foreach($users as $user){
            //MULTI  开启redis事务
            Redis::command('MULTI');

            $uid = 'user_host_tag_metric_metric_node_'.$user->id;
            Redis::command('DEL',[$uid]);

            $userhosts = HostUser::findUserHostByUID($user->id);
            foreach($userhosts as $userhost){
                $ret = new \stdClass();
                $hostid = $userhost->hostid;
                $host = Host::findByHostid($userhost->hostid);
                $ret->host = $host;
                $tags = Tag::findTagHostByHostid($userhost->hostid);
                $ret->tag = $tags;
                $metrics = MetricModel::findMetricHostByHostid($userhost->hostid);
                foreach($metrics as $metric){
                    $metricnodes = MetricModel::findMetricNodeByHostid($userhost->hostid,$metric->metricid);
                    $metric->metric_node = $metricnodes;
                }
                $ret->metric = $metrics;
                $value = \GuzzleHttp\json_encode($ret);
                Redis::command('HSET',[$uid,$hostid,$value]);
            }

            //EXEC 执行事务
            Redis::command('EXEC');
        }
    }

    /**
     * 设置 用户node 缓存
     */
    public static function setNodeHostCache()
    {
        $users = DB::table('user')->select('id')->get();
        foreach($users as $user){
            //MULTI  开启redis事务
            Redis::command('MULTI');

            $uid = 'user_node_metric_node_'.$user->id;
            Redis::command('DEL',[$uid]);

            $userhosts = HostUser::findUserHostByUID($user->id);
            $data = [];
            foreach($userhosts as $userhost){
                $node_hosts = MetricModel::findNodeHostByHostid($userhost->hostid);
                foreach($node_hosts as $metric_node){
                    $name = $metric_node->metric_name;
                    if(!isset($data[$name])) $data[$name] = [];
                    if(!in_array($userhost->hostid,$data[$name]))
                        array_push($data[$name],$userhost->hostid);
                    $value = \GuzzleHttp\json_encode($data[$name]);
                    Redis::command('HSET',[$uid,$name,$value]);
                }
            }

            //EXEC 执行事务
            Redis::command('EXEC');
        }
    }

    /**
     * 获取metric 及 tags
     * @param $uid
     * @return array
     */
    public function metricCache($uid)
    {
        $redis = new \Redis();
        $redis->connect($this->redis_host,$this->redis_port);
        $metric_key = 'search:metrics:uid='.$uid;
        $metrics = $redis->hKeys($metric_key);
        sort($metrics);
        $result = [];
        $pipe = $redis->multi(\Redis::PIPELINE);
        foreach($metrics as $key => $metric){
            $tag_key = "search:mts:".$metric;
            $pipe->hKeys($tag_key);
        }
        $replies = $pipe->exec();
        $custom_tags = MyApi::getCustomTagsByHost($uid);
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
                    if($arr[0] === 'host' && !empty($arr[1])){
                        $tags_temp = array_merge($tags_temp,$custom_tags->$arr[1]);
                    }
                }
            }
            $tags_temp = array_unique($tags_temp);
            sort($tags_temp);
            $temp->tags = $tags_temp;

            array_push($result,$temp);
        }

        return $result;
    }

    public function tagsCache($uid)
    {
        $redis = new \Redis();
        $redis->connect($this->redis_host,$this->redis_port);
        $metric_key = 'search:metrics:uid='.$uid;
        $metrics = $redis->hKeys($metric_key);
        sort($metrics);
        $pipe = $redis->multi(\Redis::PIPELINE);
        foreach($metrics as $key => $metric){
            $tag_key = "search:mts:".$metric;
            $pipe->hKeys($tag_key);
        }
        $replies = $pipe->exec();
        $custom_tags = MyApi::getCustomTagsByHost($uid);
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
                    if($arr[0] === 'host' && !empty($arr[1])){
                        $tags_temp = array_merge($tags_temp,$custom_tags->$arr[1]);
                    }
                }
            }
            $result = array_merge($result,$tags_temp);
        }
        $result = array_unique($result);
        sort($result);

        return $result;
    }
}