<?php

namespace App\Http\Controllers;

use App\MyClass\MyRedisCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Response;


class RedisController extends Controller
{
    /**
     * 获取用户信息
     */
    public function user()
    {
        //MyRedisCache::setUserCache();
        return Redis::hgetall('user_host_tag_metric_metric_node_088DBF7B54EFBA3CA599B3543C73EA1C');
    }

    /**
     * 获取node_host
     */
    public function nodeHost()
    {
        //MyRedisCache::setNodeHostCache();
        return Redis::hgetall('user_node_metric_node_088DBF7B54EFBA3CA599B3543C73EA1C');
    }

}
