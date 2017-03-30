<?php
/**
 * Created by PhpStorm.
 * User: cheng_f
 * Date: 2017/2/21
 * Time: 10:56
 */

namespace App\MyClass;

use Illuminate\Support\Facades\Cache;
use Log;
use Mockery\CountValidator\Exception;
use Symfony\Component\Debug\Exception\FatalErrorException;

class MyApi
{
    //const  TSDB_URL = "http://172.29.225.121:4242";
    const  TSDB_URL = "http://172.29.231.177:4242";

    public static function getMetricJson($uid)
    {
        $url = MyApi::TSDB_URL . '/api/search/lookup';
        $data = MyApi::lookupParam($uid);
        $res = MyApi::httpPost($url,$data,true);

        return $res;
    }

    public static function getTsuid($host)
    {
        $url = MyApi::TSDB_URL . '/api/uid/assign?tagv='.$host;
        $res = MyApi::httpGet($url);
        $data = \GuzzleHttp\json_decode($res);
        if(isset($data->tagv_errors)){
            $tagv_errors = $data->tagv_errors->$host;
            $arr = explode(':',$tagv_errors);
            $result = trim($arr[1]);
        }else{
            $result = isset($data->tagv) ? $data->tagv->$host : '';
        }

        return $result;
    }

    public static function getCustom($host)
    {
        $url = MyApi::TSDB_URL . '/api/search/uidmeta?query=name:'.$host;
        $res = MyApi::httpGet($url);
        $data = \GuzzleHttp\json_decode($res);
        $results = isset($data->results) ? $data->results : [];
        $custom = null;
        if(count($results) > 0){
            $custom = $results[0]->custom;
        }
        return $custom;
    }

    public static function putTags($tsuid,$agent,$custom,$uid,$host)
    {
        $param = MyApi::uidmetaParam($tsuid,$agent,$custom,$uid,$host);
        $url = MyApi::TSDB_URL . '/api/uid/uidmeta';
        $res = MyApi::httpPost($url,$param,true);
        return $res;
    }

    public static function lookupRes($res,$host_tags=null)
    {
        $data = \GuzzleHttp\json_decode($res);
        //return $data;
        $results = $data->results;
        $ret = [];
        foreach($results as $result){
            $arr = [];
            $metric = $result->metric;
            $tags = $result->tags;
            foreach($tags as $key => $val){
                if($key == 'uid') continue;
                if($key === 'host' && !is_null($host_tags) && !empty($host_tags->$val)){
                    $arr = array_merge($arr,$host_tags->$val);
                }
                array_push($arr,$key . ":" . $val);
            }
            $ret[$metric] = isset($ret[$metric]) ? array_unique(array_merge($ret[$metric],$arr)) : $arr;
        }
        $result = [];
        foreach($ret as $metric => $tags){
            $ret = new \stdClass();
            $ret->metric = $metric;
            $ret->tags = array_values($tags);

            array_push($result,$ret);
        }

        return $result;
    }

    public static function uidmetaParam($tsuid,$agent,$custom,$uid,$host)
    {
        $param = new \stdClass();
        $param->uid = $tsuid;
        $param->type = "tagv";
        $param->custom = empty($custom) ? new \stdClass(): $custom;
        if(!empty($agent)){
            $param->custom->agent = $agent;
        }
        $param->custom->uid = $uid;
        $param->custom->host = $host;
        $param = json_encode($param);
        return $param;
    }

    public static function lookupParam($uid)
    {
        $param = new \stdClass();
        $param->useMeta = false;
        $param->limit = 1000000;
        $param->tags = [];
        $sub = new \stdClass();
        $sub->key = 'uid';
        $sub->value = $uid;
        array_push($param->tags,$sub);
        $param = json_encode($param);

        return $param;
    }


    public static function httpPost($url,$data,$is_json=false)
    {
        $ch = curl_init();
        $result = '';
        try{
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS,$data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
            if($is_json){
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                        'Content-Type: application/json',
                        'Content-Length: ' . strlen($data))
                );
            }
            $result = curl_exec($ch);

        }catch(Exception $e){
            Log::info("http_post_error == " . $e->getMessage());
        }catch(FatalErrorException $e){
            Log::info("http_post_error opentsdb error == " . $e->getMessage());
        }
        curl_close($ch);
        return $result;
    }

    public static function httpGet($url)
    {
        $ch = curl_init();
        $result = '';
        try{
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            $result = curl_exec($ch);
        }catch(Exception $e){
            Log::info("http_get_error == " . $e->getMessage());
        }catch(FatalErrorException $e){
            Log::info("http_get_error opentsdb error == " . $e->getMessage());
        }

        curl_close($ch);
        return $result;
    }

    public static function getHostTagAgent($metrics_in)
    {
        $host_tags = 'host-tags';
        $host_tag = $metrics_in->$host_tags;
        if(!isset($host_tag->system)) return '';
        $agent = "";
        foreach($host_tag->system as $val) {
            $agent .= $val . ',';
        }

        return trim($agent,',');
    }

    public static function putHostTags($metrics_in,$host,$uid)
    {
        $hostid = md5(md5($uid).md5($host));

        $tsuid = MyApi::getTsuid($hostid);
        //return response()->json($tsuid);

        $custom = MyApi::getCustom($hostid);
        //return response()->json($custom);

        $agent = MyApi::getHostTagAgent($metrics_in);
        //$agent = 'host11,host13';
        $res = MyApi::putTags($tsuid,$agent,$custom,$uid,$host);

        Log::info('put-host-tag === ' . $res);
    }

    public static function getCustomTagsByHost($uid)
    {
        $url = MyApi::TSDB_URL . '/api/search/uidmeta?query=custom.uid:'.$uid.'&limit=10000';
        $res = \GuzzleHttp\json_decode(MyApi::httpGet($url)); // 自定义tag
        $host_tags = new \stdClass();
        foreach($res->results as $item){
            if($item->custom){
                $host = $item->custom->host;
                $host_tags->$host = [];
                foreach($item->custom as $key => $value){
                    if($key != 'uid' && $key != 'host' && $value){
                        array_push($host_tags->$host,$key.':'.$value);
                    }
                }
            }
        }

        return $host_tags;
    }
}