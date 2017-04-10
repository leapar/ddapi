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
use DB;
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
        if(empty($metrics_in)) return '';
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

    public static function getMetricTypes($uid,$metric_name)
    {
        $first = DB::table('metric_types')->whereNotNull('userId')->where('userId',$uid)->where('metric_name', '=', $metric_name);
        $res = DB::table('metric_types')->whereNull('userId')->where('type',0)->where('metric_name', '=', $metric_name)
            ->unionAll($first)->orderBy('created_at','asc')->get();
        if(count($res) > 0){
            if(count($res) == 1){
                $item = $res[0];
                $item->created_at = strtotime($item->created_at) * 1000;
                $item->updated_at = strtotime($item->updated_at) * 1000;
                return $res[0];
            }
            if(count($res) == 2){
                foreach($res as $item){
                    if($item->type == 1){
                        $item->created_at = strtotime($item->created_at) * 1000;
                        $item->updated_at = strtotime($item->updated_at) * 1000;
                        return $item;
                        break;
                    }
                }
            }
        }else{
            //从ci 中获取
            $metric_types =  MyApi::getMetricTypesFromCi($metric_name);
            //save DB
            if($metric_types){
                MyApi::saveMetricTypes($metric_types);
            }else{
                return [];
            }
            return $metric_types;
        }
    }

    public static function saveMetricTypes($metric_types)
    {
        $data = [
            'integration' => $metric_types->integration,
            'metric_name' => $metric_types->metric_name,
            'description' => $metric_types->description,
            'metric_type' => $metric_types->metric_type,
            'metric_alias' => $metric_types->metric_alias,
            'per_unit' => $metric_types->per_unit,
            'plural_unit' => $metric_types->plural_unit,
            'type' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        $res = DB::table('metric_types')->where('metric_name',$metric_types->metric_name)->first();
        if(!$res){
            DB::table('metric_types')->insert($data);
        }
    }

    public static function updateMetricTypes($uid,$data)
    {
        if(!isset($data->metric_name) || empty($data->metric_name)) return [];
        $integration = explode('.',$data->metric_name);
        $up_data = [
            'integration' => $integration[0],
            'metric_name' => $data->metric_name,
            'description' => $data->description,
            'metric_type' => $data->metric_type,
            'per_unit' => $data->per_unit,
            'plural_unit' => $data->plural_unit,
            'type' => 1,
            'userId' => $uid,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $res = DB::table('metric_types')->where('userId',$uid)->where('metric_name',$data->metric_name)->update($up_data);
        if(!$res){
            $up_data['created_at'] = date('Y-m-d H:i:s');
            DB::table('metric_types')->insert($up_data);
        }
        return DB::table('metric_types')->where('userId',$uid)->where('metric_name',$data->metric_name)->first();

    }

    public static function getMetricTypesFromCi($metric)
    {
        ini_set("max_execution_time",1800);
        $post = array (
            'input' => '83250460@qq.com',
            'password' => '1234qwer',
            'rememberPassword' => true,
            'encode' => false,
            'labelKey' => 'ci',
        );
        //登录地址
        $url_login = "http://user.oneapm.com/pages/v2/login";
        //设置cookie保存路径
        $cookie = dirname(__FILE__) . '/cookie_ci.txt';
        //登录后要获取信息的地址
        $url_metric_type = "http://cloud.oneapm.com/v1/metric_types?metric="; //mesos.cluster.disk_percent
        //$metric = "mesos.cluster.disk_percent";
        //模拟登录
        MyApi::login($url_login, $cookie, $post);
        //获取登录页的信息
        $content = MyApi::get_metric_type($url_metric_type,$metric, $cookie);
        //删除cookie文件
        @unlink($cookie);
        $res = json_decode($content);
        if(isset($res->result)) return $res->result;
        return false;
    }

    //模拟登录
    public static function login($url, $cookie, $post) {
        $curl = curl_init();//初始化curl模块
        curl_setopt($curl, CURLOPT_URL, $url);//登录提交的地址
        curl_setopt($curl, CURLOPT_HEADER, 0);//是否显示头信息
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 0);//是否自动显示返回的信息
        curl_setopt($curl, CURLOPT_COOKIEJAR, $cookie); //设置Cookie信息保存在指定的文件中
        curl_setopt($curl, CURLOPT_POST, 1);//post方式提交
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));//要提交的信息
        curl_exec($curl);//执行cURL
        curl_close($curl);//关闭cURL资源，并且释放系统资源
    }

    //登录成功后获取数据
    public static function get_metric_type($url, $metric,$cookie) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url.$metric);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie); //读取cookie
        $rs = curl_exec($ch); //执行cURL抓取页面内容
        curl_close($ch);
        return $rs;
    }
}