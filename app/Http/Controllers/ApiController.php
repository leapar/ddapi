<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Response;


class ApiController  extends Controller
{
    private $tsdb_url = "http://172.29.231.70:4242";

    private function statusMessage($code)
    {
        switch($code){
            case '200': return 'success'; break;
            default:return "未知错误";
        }
    }

    private function respFormat($res)
    {
        $ret = new \stdClass();
        $ret->code = $res->getStatusCode();
        $ret->message = $this->statusMessage($res->getStatusCode());
        $ret->result = \GuzzleHttp\json_decode($res->getBody());

        return $ret;
    }

    public function metricsJson()
    {
        $url = $this->tsdb_url . "/suggest?type=tagk";
        $client = new \GuzzleHttp\Client();
        $res = $client->request('get',$url);

        $ret = $this->respFormat($res);
        return response()->json($ret);
    }

    public function showJson()
    {

    }

    public function dashboardsJson()
    {

    }

    public function chartsJson()
    {

    }

    public function tagsJson()
    {

    }

    public function queryJson()
    {

    }

    public function addJson()
    {

    }

    public function updateJson()
    {

    }


}

