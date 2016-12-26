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

    public static function setUserCache()
    {
        $users = DB::table('user')->select('id')->get();
        foreach($users as $user){
            //$ret = new \stdClass();
            $uid = 'user_host_tag_metric_metric_node_'.$user->id;
            //$ret->{$uid} = new \stdClass();
            $userhosts = HostUser::findUserHostByUID($user->id);
            foreach($userhosts as $userhost){
                $ret = new \stdClass();
                $hostid = $userhost->hostid;
                //$ret->{$uid}->{$hostid} = new \stdClass();
                $host = Host::findByHostid($userhost->hostid);
                //$ret->{$uid}->{$hostid}->host = $host;
                $ret->host = $host;
                $tags = Tag::findTagHostByHostid($userhost->hostid);
                //$ret->{$uid}->{$hostid}->tag = $tags;
                $ret->tag = $tags;
                $metrics = MetricModel::findMetricHostByHostid($userhost->hostid);
                foreach($metrics as $metric){
                    $metricnodes = MetricModel::findMetricNodeByHostid($userhost->hostid,$metric->metricid);
                    $metric->metric_node = $metricnodes;
                }
                //$ret->{$uid}->{$hostid}->metric = $metrics;
                $ret->metric = $metrics;
                /*$metric_nodes = Metric::findMetricNodeByHostid($userhost->hostid);
                $ret->{$uid}->{$hostid}->metric_node = $metric_nodes;*/
                $value = \GuzzleHttp\json_encode($ret);
                Redis::command('HSET',[$uid,$hostid,$value]);
            }
            //MyRedisCache::setRedisCache($uid,$ret);
        }
        //MyRedisCache::setRedisCache('userdata',$ret);
    }

    public static function setNodeHostCache()
    {
        $users = DB::table('user')->select('id')->get();
        foreach($users as $user){
            //$ret = new \stdClass();
            $uid = 'user_node_metric_node_'.$user->id;
            //$ret->{$uid} = new \stdClass();
            $userhosts = HostUser::findUserHostByUID($user->id);
            $data = [];
            foreach($userhosts as $userhost){
                $node_hosts = MetricModel::findNodeHostByHostid($userhost->hostid);
                foreach($node_hosts as $metric_node){
                    //$metricid = $metric_node->metricid;
                    $name = $metric_node->metric_name;
                    if(!isset($data[$name])) $data[$name] = [];
                    if(!in_array($userhost->hostid,$data[$name]))
                        array_push($data[$name],$userhost->hostid);
                    $value = \GuzzleHttp\json_encode($data[$name]);
                    Redis::command('HSET',[$uid,$name,$value]);
                }
            }
            //$ret->{$uid} = $data;
            //MyRedisCache::setRedisCache($uid,$ret);

        }


    }


}