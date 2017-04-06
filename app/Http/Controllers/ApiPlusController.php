<?php

namespace App\Http\Controllers;

use App\Dashboard;
use App\Metric;
use App\MyClass\MyApi;
use App\MyClass\MyRedisCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Response;
use DB;
use Mockery\Exception;


class ApiPlusController  extends Controller
{
    public function metricsJson(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        if(!$uid) return;
        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = "success";
        $ret->result = [];

        try{
            $my_redis = new MyRedisCache();
            $ret->result = $my_redis->metricCache($uid);
        }catch(Exception $e){
            $result = [];
            $ret->code = 500;
            $ret->message = 'fail';
            Log::info('error == ' . $e->getMessage());
        }

        return response()->json($ret);
    }

    public function tagJson(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 19;
        if(!$uid) return;
        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = "success";
        $ret->result = [];
        try{
            $my_redis = new MyRedisCache();
            $ret->result = $my_redis->tagsCache($uid);
        }catch(Exception $e){
            $ret->code = 500;
            $ret->message = 'fail';
            Log::info('error == ' . $e->getMessage());
        }

        return response()->json($ret);
    }
}

