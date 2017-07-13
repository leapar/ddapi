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
use Illuminate\Support\Facades\Redis;

class MyApi
{
    public static function getMetricJson($uid)
    {
        $url = config('myconfig.tsdb_search_url') . '/api/search/lookup';
        $data = MyApi::lookupParam($uid);
        $res = MyApi::httpPost($url, $data, true);

        return $res;
    }

    public static function getTsuid($host)
    {
        $url = config('myconfig.tsdb_search_url') . '/api/uid/assign?tagv=' . $host;
        $res = MyApi::httpGet($url);
        $data = \GuzzleHttp\json_decode($res);
        if (isset($data->tagv_errors)) {
            $tagv_errors = $data->tagv_errors->$host;
            $arr = explode(':', $tagv_errors);
            $result = trim($arr[1]);
        } else {
            $result = isset($data->tagv) ? $data->tagv->$host : '';
        }

        return $result;
    }

    public static function getCustom($host)
    {
        $url = config('myconfig.tsdb_search_url') . '/api/search/uidmeta?query=name:' . $host;
        $res = MyApi::httpGet($url);
        $data = \GuzzleHttp\json_decode($res);
        $results = isset($data->results) ? $data->results : [];
        $custom = null;
        if (count($results) > 0) {
            $item = $results[0];
            $custom = $item->custom;
        }
        return $custom;
    }

    public static function putTags($tsuid, $agent, $custom, $uid, $host)
    {
        $param = MyApi::uidmetaParam($tsuid, $agent, $custom, $uid, $host);
        $url = config('myconfig.tsdb_search_url') . '/api/uid/uidmeta';
        $res = MyApi::httpPost($url, $param, true);
        return $res;
    }

    public static function lookupRes($res, $host_tags = null)
    {
        $data = \GuzzleHttp\json_decode($res);
        //return $data;
        $results = $data->results;
        $ret = [];
        foreach ($results as $result) {
            $arr = [];
            $metric = $result->metric;
            $tags = $result->tags;
            foreach ($tags as $key => $val) {
                if ($key == 'uid') continue;
                if ($key === 'host' && !is_null($host_tags) && !empty($host_tags->$val)) {
                    $arr = array_merge($arr, $host_tags->$val);
                }
                array_push($arr, $key . ":" . $val);
            }
            $ret[$metric] = isset($ret[$metric]) ? array_unique(array_merge($ret[$metric], $arr)) : $arr;
        }
        $result = [];
        foreach ($ret as $metric => $tags) {
            $ret = new \stdClass();
            $ret->metric = $metric;
            $ret->tags = array_values($tags);

            array_push($result, $ret);
        }

        return $result;
    }

    public static function uidmetaParam($tsuid, $agent, $custom, $uid, $host)
    {
        $param = new \stdClass();
        $param->uid = $tsuid;
        $param->type = "tagv";
        $param->custom = empty($custom) ? new \stdClass() : $custom;
        if (!empty($agent)) {
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
        array_push($param->tags, $sub);
        $param = json_encode($param);

        return $param;
    }

    public static function httpPost($url, $data, $is_json = false)
    {
        $ch = curl_init();
        $result = '';
        try {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            if ($is_json) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                        'Content-Type: application/json',
                        'Content-Length: ' . strlen($data))
                );
            }
            $result = curl_exec($ch);

        } catch (Exception $e) {
            Log::info("http_post_error == " . $e->getMessage());
        } catch (FatalErrorException $e) {
            Log::info("http_post_error opentsdb error == " . $e->getMessage());
        }
        curl_close($ch);
        return $result;
    }

    public static function httpGet($url)
    {
        $ch = curl_init();
        $result = '';
        try {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            $result = curl_exec($ch);
        } catch (Exception $e) {
            Log::info("http_get_error == " . $e->getMessage());
        } catch (FatalErrorException $e) {
            Log::info("http_get_error opentsdb error == " . $e->getMessage());
        }

        curl_close($ch);
        return $result;
    }

    public static function getHostTagAgent($metrics_in)
    {
        if (empty($metrics_in)) return '';
        $host_tags = 'host-tags';
        $host_tag = $metrics_in->$host_tags;
        if (!isset($host_tag->system)) return '';
        $agent = "";
        foreach ($host_tag->system as $val) {
            $agent .= $val . ',';
        }

        return trim($agent, ',');
    }

    public static function putHostTags($metrics_in, $host, $uid)
    {
        $hostid = md5(md5($uid) . md5($host));

        $tsuid = MyApi::getTsuid($hostid);
        //return response()->json($tsuid);

        $custom = MyApi::getCustom($hostid);
        //return response()->json($custom);

        $agent = MyApi::getHostTagAgent($metrics_in);
        //$agent = 'host11,host13';
        $res = MyApi::putTags($tsuid, $agent, $custom, $uid, $host);

        Log::info('put-host-tag === ' . $res);
    }

    public static function getCustomTagsByHost($uid)
    {
        $url = config('myconfig.tsdb_search_url') . '/api/search/uidmeta?query=custom.uid:' . $uid . '&limit=10000';
        $res = \GuzzleHttp\json_decode(MyApi::httpGet($url)); // 自定义tag
        $host_tags = new \stdClass();
        foreach ($res->results as $item) {
            if ($item->custom) {
                $host = $item->custom->host;
                $host_tags->$host = [];
                foreach ($item->custom as $key => $value) {
                    if ($key != 'uid' && $key != 'host' && $value) {
                        array_push($host_tags->$host, $key . ':' . $value);
                    }
                }
            }
        }

        return $host_tags;
    }

    public static function getMetricTypes($uid, $metric_name)
    {
        //$first = DB::table('metric_types')->whereNotNull('userId')->where('userId', $uid)->where('metric_name', '=', $metric_name);
        /*$res = DB::table('metric_types')->whereNull('userId')->where('type', 0)->where('metric_name', '=', $metric_name)
            ->unionAll($first)->orderBy('type','desc')->orderBy('created_at', 'asc')->first();*/
        $plural_unit = null;
        $per_unit = null;

        $res = DB::table('metric_types')->where('metric_name', $metric_name)->where('type', 0)->first();
        if(!$res){
            return [];
        }
        $res->created_at = strtotime($res->created_at) * 1000;
        $res->updated_at = strtotime($res->updated_at) * 1000;
        return $res;

        /*if (count($res) > 0) {
            if (count($res) == 1) {
                $item = $res[0];
                $item->created_at = strtotime($item->created_at) * 1000;
                $item->updated_at = strtotime($item->updated_at) * 1000;
                $item->user_per_unit = $per_unit;
                $item->user_plural_unit = $plural_unit;
                return $item;
            }
            if (count($res) == 2) {
                $ret = new \stdClass();
                foreach ($res as $item) {
                    if($item->type == 0){
                        $plural_unit = $item->plural_unit;
                        $per_unit = $item->per_unit;
                    }
                    if ($item->type == 1) {
                        $ret = $item;
                        $ret->created_at = strtotime($item->created_at) * 1000;
                        $ret->updated_at = strtotime($item->updated_at) * 1000;
                        $ret->user_per_unit = $item->per_unit;
                        $ret->user_plural_unit = $item->plural_unit;
                        //return $item;
                        //break;
                    }
                }
                $ret->per_unit = $per_unit;
                $ret->plural_unit = $plural_unit;

                return $ret;
            }
        } else {
            //从ci 中获取
            $metric_types = MyApi::getMetricTypesFromCi($metric_name);
            //save DB
            if ($metric_types) {
                MyApi::saveMetricTypes($metric_types);
            } else {
                $integration = explode('.', $metric_name);
                $data = [
                    'integration' => $integration[0],
                    'metric_name' => $metric_name,
                    'description' => null,
                    'metric_type' => null,
                    'metric_alias' => null,
                    'per_unit' => null,
                    'plural_unit' => null,
                    'type' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                DB::table('metric_types')->insert($data);
                $item = DB::table('metric_types')->where('metric_name', $metric_name)->first();
                $item->created_at = strtotime($item->created_at) * 1000;
                $item->updated_at = strtotime($item->updated_at) * 1000;
                return $item;
            }
            return $metric_types;
        }*/
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
        $res = DB::table('metric_types')->where('metric_name', $metric_types->metric_name)->first();
        if (!$res) {
            DB::table('metric_types')->insert($data);
        }
    }

    public static function updateMetricTypes($uid, $data)
    {
        if (!isset($data->metric_name) || empty($data->metric_name)) return [];
        $integration = explode('.', $data->metric_name);
        $up_data = [
            'integration' => $integration[0],
            'metric_name' => $data->metric_name,
            'type' => 1,
            'userId' => $uid,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        if(isset($data->description)) $up_data['description'] = $data->description;
        if(isset($data->metric_type)) $up_data['metric_type'] = $data->metric_type;
        if(isset($data->user_plural_unit)) $up_data['plural_unit'] = $data->plural_unit;
        if(isset($data->uesr_per_unit)) $up_data['per_unit'] = $data->per_unit;
        
        $res = DB::table('metric_types')->where('userId', $uid)->where('metric_name', $data->metric_name)->update($up_data);
        if (!$res) {
            $up_data['created_at'] = date('Y-m-d H:i:s');
            DB::table('metric_types')->insert($up_data);
        }
        return DB::table('metric_types')->where('userId', $uid)->where('metric_name', $data->metric_name)->first();

    }

    public static function getMetricTypesFromCi($metric)
    {
        ini_set("max_execution_time", 1800);
        $post = array(
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
        $content = MyApi::get_metric_type($url_metric_type, $metric, $cookie);
        //删除cookie文件
        @unlink($cookie);
        $res = json_decode($content);
        if (isset($res->result)) return $res->result;
        return false;
    }

    //模拟登录
    public static function login($url, $cookie, $post)
    {
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
    public static function get_metric_type($url, $metric, $cookie)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . $metric);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie); //读取cookie
        $rs = curl_exec($ch); //执行cURL抓取页面内容
        curl_close($ch);
        return $rs;
    }

    /**
     * 获取最后一行，最后一行中的最后一个图标的x,y,w,h及最大的mh
     * @param $arr
     * @return array [x,y,w,h,mh]
     */
    public static function getMaxKeyInArray($arr)
    {
        if (empty($arr)) {
            return [0,0,3,2,0,3,2];
        }
        $res = [0,0];
        $res_key = 0;
        $res_key_h = 0;
        $y = 0;
        foreach ($arr as $key => $value) {
            $res[0] = $value[2];
            $res[1] = max($res[1],$value[2]);//最大y
        }
        $y = $res[1];//最后一行y
        $res_t = [0,0];
        foreach ($arr as $key => $value) {
            if($value[2] == $y){ //最后一行
                $res_t[0] = max($res_t[0],$value[1]);//最大的x
                $res_t[1] = max($res_t[1],$value[4]);//最大的h
                if($res_t[0] == $value[1]){
                    $res_key = $key;
                }
                if($res_t[1] == $value[4]){
                    $res_key_h = $key;
                }
            }
        }
        $result = $arr[$res_key];//（最后一个图表）
        $result_h  = $arr[$res_key_h];
        return [$result[1],$result[2],$result[3],$result[4],$result_h[1],$result_h[3],$result_h[4]];
    }

    public static function checkUidError($uid)
    {
        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = 'success';
        $ret->result = [];
        if(!$uid){
            $ret->code = 403;
            $ret->message = "fail 未知用户UID";
        }else{
            $ret->code = 0;
        }
        return $ret;
    }

    public static function normalModeList($uid)
    {
        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = 'success';
        $ret->result = [];

        $metrics = [];
        $metric_hosts = DB::table('metric_host')
            ->leftJoin('host_user', 'metric_host.hostid', '=', 'host_user.hostid')
            ->where('host_user.userid',$uid)
            ->select('metric_host.check_run','metric_host.service_checks')->get();
        foreach($metric_hosts as $metric_host){
            $check_run  = json_decode($metric_host->check_run);
            $service_check = json_decode($metric_host->service_checks);
            if(!empty($check_run)){
                foreach($check_run as $check){
                    $check_status = $check->check;
                    if($check->status == 0){
                        $tmps = explode(".",$check_status);
                        array_push($metrics,$tmps[0]);
                    }
                }
            }
            if(!empty($service_check)){
                foreach($service_check as $check){
                    $check_status = $check->check;
                    $tmps = explode(".",$check_status);
                    if(end($tmps) == 'check_status' && isset($check->tags) && $check->status == 0){
                        $tags = explode(":",$check->tags[0]);
                        array_push($metrics,$tags[1]);
                    }
                }
            }
        }
        $metrics = array_unique($metrics);
        if(!in_array('system',$metrics)){
            array_push($metrics,'system');
        }

        $node = DB::table('metric_dis')
            /*->leftJoin('metric_service', 'metric_dis.integrationid', '=', 'metric_service.id')*/
            ->whereIn('integrationid',$metrics)
            ->select('metric_dis.integrationid as integration','metric_dis.subname','metric_dis.metric_name','metric_dis.short_description','metric_dis.metric_type as type','metric_dis.description','metric_dis.per_unit')
            ->get();

        $integration_arr = [];
        foreach($node as $val){
            $subname = $val->subname;
            $integration = $val->integration;
            if(!isset($integration_arr[$integration][$subname])){
                $integration_arr[$integration][$subname] = [];
            }
            $arr = explode(".",$val->metric_name);
            $end = end($arr);
            $des = !empty($val->short_description) ? $val->short_description : $end;
            $tmps3 = new \stdClass();
            $tmps3->$des = new \stdClass();
            $tmps3->$des->description = $val->description;
            $tmps3->$des->metric_name = $val->metric_name;
            $tmps3->$des->type = $val->type;
            $tmps3->$des->unit = $val->per_unit;

            array_push($integration_arr[$integration][$subname],$tmps3);
        }
        foreach($integration_arr as $integration => $subname_arr){
            $tmps1 = new \stdClass();
            $tmps1->$integration = [];
            foreach($subname_arr as $subname => $tmps3){
                $tmps2 = new \stdClass();
                $tmps2->$subname = $tmps3;
                array_push($tmps1->$integration,$tmps2);
            }
            array_push($ret->result,$tmps1);
        }

        return $ret;
    }

    public static function dashboardsJson($uid,$request)
    {
        $ret = new \stdClass();
        $ret->code = 0;
        $ret->result = [];
        if($request->has('favorite') && $request->favorite == 'true'){
            $is_favorite = 1;
        }else{
            $is_favorite = 0;
        }
        $res = DB::table('dashboard')
            ->select('dashboard.*',DB::raw('UNIX_TIMESTAMP(update_time)*1000 as update_time'),DB::raw('UNIX_TIMESTAMP(create_time)*1000 as create_time'))
            ->where('user_id',$uid);

        if($request->has('favorite')){
            $res = $res->where('is_favorite',$is_favorite);
        }
        if($request->has('type')){
            $res = $res->where('type',$request->type);
        }
        $res = $res->get();
        foreach($res as $item){
            $item->is_favorite = $item->is_favorite ? true : false;
            $item->is_installed = $item->is_installed ? true : false;
            $item->update_time = strtotime($item->update_time) * 1000;
            $item->create_time = strtotime($item->create_time) * 1000;
        }
        $ret->message = 'success';
        $ret->result = $res;

        return $ret;
    }

    public static function addJson($res,$request,$dasid)
    {
        /*   update order  1 */
        $orders = json_decode($res->order);
        if(!$orders) $orders = [];
        $init_arr = MyApi::getMaxKeyInArray($orders);//最后一行 初始化位置
        $init_x = $init_arr[0];
        $init_y = $init_arr[1];
        $init_w = $init_arr[2];
        $init_h = $init_arr[3];
        $max_x = $init_arr[4];
        $max_w = $init_arr[5];
        $max_h = $init_arr[6];
        $x = $init_x + $init_w; $y = $init_y;$w = 3;$h = 2;
        /*     update order  1  */

        $param = \GuzzleHttp\json_decode($request->chart);
        $data = [
            'name' => $param->name,
            'dashboard_id' => $dasid,
            'type' => $param->type,
            'meta' => json_encode($param->meta),
            'metrics' => json_encode($param->metrics),
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s')
        ];

        /*   update order   */
        $chart_id = DB::table('charts')->insertGetId($data);
        if($x > 9){ //直接换行追加
            if($max_h - $init_h > 2){ //半行追加
                $y = $y + $init_h;
                $x = $max_x + $max_w;
            }else{ //另起行
                $x = 0;
                $y = $y + $max_h;
            }
        }
        array_push($orders,[$chart_id,$x,$y,$w,$h]);
        DB::table('dashboard')->where('id',$dasid)->update(['order' => json_encode($orders)]);
        /*   update order   */

        $result = DB::table('charts')->where('id',$chart_id)->first();

        return $result;
    }

    public static function cloneDasb($res,$request,$uid,$dasid)
    {
        unset($res->id);
        $res->name = $request->dashboardName;
        $res->desc = $request->dashboardDesc;
        $res->type = 'user';
        $res->slug = null;
        $res->user_id = $uid;
        $res->is_able = 1;
        $res->create_time = date("Y-m-d H:i:s");
        $res->update_time = date("Y-m-d H:i:s");
        $orders = json_decode($res->order);
        $res = json_encode($res);
        $res = json_decode($res,true);
        $id = DB::table('dashboard')->insertGetId($res);
        $charts = DB::table('charts')->where('dashboard_id',$dasid)->get();
        $arr = [];
        foreach($orders as $item){
            $arr[$item[0]] = $item;
        }
        $new_orders = [];
        foreach($charts as $chart){
            $order = $arr[$chart->id];
            unset($chart->id);
            $chart->create_time = date("Y-m-d H:i:s");
            $chart->update_time = date("Y-m-d H:i:s");
            $chart->dashboard_id = $id;
            $chart = json_encode($chart);
            $chart = json_decode($chart,true);
            //DB::table('charts')->insert($chart);
            $chart_id = DB::table('charts')->insertGetId($chart);
            $order[0] = $chart_id;
            array_push($new_orders,$order);
        }
        DB::table('dashboard')->where('id',$id)->update(['order' => json_encode($new_orders)]);
        return $id;
    }

    public static function addMore($data,$uid)
    {
        $charts = $data->charts;
        $name = $data->dashboard->dashboard_name;

        $data = [
            'name' => $name,
            'type' => 'user',
            'user_id' => $uid,
            'is_able' => 1,
            'create_time' => date("Y-m-d H:i:s"),
            'update_time' => date("Y-m-d H:i:s")
        ];
        $id = DB::table('dashboard')->insertGetId($data);

        $orders = [];
        $x=0;$y=0;$w=3;$h=2;
        foreach($charts as $chart){
            $data = [
                'name' => $chart->dashboard_chart_name,
                'create_time' => date("Y-m-d H:i:s"),
                'update_time' => date("Y-m-d H:i:s"),
                'dashboard_id' => $id,
                'type' => $chart->dashboard_chart_type,
                'metrics' => json_encode($chart->metrics)
            ];
            $chart_id = DB::table('charts')->insertGetId($data);

            array_push($orders,[$chart_id,$x,$y,$w,$h]);
            if($x >= 9){//换行
                $y += 2;
                $x = 0;
            }else{
                $x += 3;
            }
        }
        DB::table('dashboard')->where('id',$id)->update(['order' => json_encode($orders)]);

        return $id;
    }

    public static function batchAdd($res,$data,$dasbid)
    {
        /*   update order   */
        $orders = json_decode($res->order);
        if(!$orders) $orders = [];
        $init_arr = MyApi::getMaxKeyInArray($orders);//最后一行 初始化位置
        $init_x = $init_arr[0];
        $init_y = $init_arr[1];
        $init_w = $init_arr[2];
        $init_h = $init_arr[3];
        $max_x = $init_arr[4];
        $max_w = $init_arr[5];
        $max_h = $init_arr[6];
        $x = $init_x + $init_w; $y = $init_y;$w = 3;$h = 2;
        /*   update order   */
        $new_line_1 = 0;
        $new_line_2 = 0;
        foreach($data as $key => $chart){
            $data = [
                'name' => $chart->dashboard_chart_name,
                'create_time' => date("Y-m-d H:i:s"),
                'update_time' => date("Y-m-d H:i:s"),
                'dashboard_id' => $dasbid,
                'type' => $chart->dashboard_chart_type,
                'metrics' => json_encode($chart->metrics)
            ];
            $chart_id = DB::table('charts')->insertGetId($data);

            /*   update order   */
            if($x >= 9){ //直接换行追加
                if($max_h - $init_h >= 2){ //半行追加
                    $new_line_1 += 1;
                    if($new_line_1 >= 2){
                        $y = $y + $h;
                    }else{
                        $y = $y + $init_h;
                    }
                    $x = $max_x + $max_w;
                    $init_h += $h;
                }else{ //另起行
                    $x = 0;
                    $new_line_2 += 1;
                    if($new_line_2 >= 2){
                        $y = $y + $h;
                    }else{
                        $y = $y + $max_h;
                    }
                }
            }else{ //依次追加
                $x = $x + $key*$w;
            }
            array_push($orders,[$chart_id,$x,$y,$w,$h]);
            /*   update order   */

        }

        DB::table('dashboard')->where('id',$dasbid)->update(['order' => json_encode($orders)]);

        return $orders;
    }

    /** device 数据存redis
     * @param $host
     * @param $uid
     * @param $data
     */
    public static function recevieDataPutRedis($host,$uid,$data)
    {
        $expire = 3*24*3600;
        $hostid = md5(md5($uid).md5($host));
        list($t1, $t2) = explode(' ', microtime());
        $msec = (float)sprintf('%.0f', (floatval($t1) + floatval($t2)) * 1000);
        //$hsname = "HOST_DATA_".$uid;
        $hsname = "HOST_DATA_".$uid."_".$host;
        $redis_data = json_decode(Redis::command('GET', [$hsname]),true);
        if(isset($data->device_id)){
            $redis_data = [
                "hostId" => $hostid,
                "hostName" => $host,
                "updatetime" => $msec,
                "colletcionstamp" => time(),
                "ptype" => $data->os,
                "ip" => $data->hostname,
                "device_id" => $data->device_id,

                "cpu" => null,
                "diskutilization" => null,
                "disksize" => null,
                "iowait" => null,
                "load15" => null
            ];
        }

        if(!empty($redis_data)){
            isset($data->cpuIdle) ? $redis_data['cpu'] = $data->cpuIdle : 0;
            isset($data->diskutilization) ? $redis_data['diskutilization'] = $data->diskutilization : 0;
            isset($data->disk_total) ? $redis_data['disksize'] = $data->disk_total : 0;
            isset($data->iowait) ? $redis_data['iowait'] = $data->iowait : 0;
            isset($data->load15) ? $redis_data['load15'] = $data->load15 : 0;
        }

        //Redis::command('HSET',[$hsname,$hostid,json_encode($redis_data)]);
        Redis::command('SET',[$hsname,json_encode($redis_data)]);
        Redis::command('EXPIRE',[$hsname,$expire]);

    }
    /*
     * 获取port top数据
     */
    public static function portTopData($uid)
    {
        $lists1 = DB::table('ipv4_mac')
            ->leftJoin(DB::raw('ports'), function ($join) {
                $join->on('ipv4_mac.port_id','=','ports.port_id');
            })
            ->leftJoin(DB::raw('ports as remote_ports'), function ($join) {
                $join->on('ipv4_mac.mac_address','=','remote_ports.ifPhysAddress');
            })
            ->where('ipv4_mac.userid',$uid)
            ->whereNotNull('remote_ports.device_id')
            ->where('ports.device_id','<>','remote_ports.device_id')//排除自己连自己
            ->whereNotIn('ipv4_mac.mac_address',['000000000000','ffffffffffff'])
            ->orderBy('ipv4_mac.device_id','asc')
            ->groupBy('ports.device_id','remote_ports.device_id') //排除连个设备间多条链接
            ->select('ipv4_mac.*','ports.ifName as port_name','ports.ifDescr as port_name2','remote_ports.ifName as remote_port_name','remote_ports.ifDescr as remote_port_name2')
            ->get()->toArray();
        $lists2 = DB::table('ipv4_mac')
            ->leftJoin(DB::raw('ports'), function ($join) {
                $join->on('ipv4_mac.port_id','=','ports.port_id');
            })
            ->leftJoin(DB::raw('ports as remote_ports'), function ($join) {
                $join->on('ipv4_mac.mac_address','=','remote_ports.ifPhysAddress');
            })
            ->where('ipv4_mac.userid',$uid)
            ->whereNull('remote_ports.device_id')//非系统设备
            ->whereNotIn('ipv4_mac.mac_address',['000000000000','ffffffffffff'])
            ->orderBy('ipv4_mac.device_id','asc')
            //->groupBy('ports.device_id','remote_ports.device_id') //排除连个设备间多条链接
            ->select('ipv4_mac.*','ports.ifName as port_name','ports.ifDescr as port_name2','remote_ports.ifName as remote_port_name','remote_ports.ifDescr as remote_port_name2')
            ->get()->toArray();
        $lists = array_merge($lists1,$lists2);
        $devices = DB::table('host')
            ->where('userid',$uid)->whereNotNull('device_id')
            ->orderBy('device_id','asc')
            ->get();
        $device_ids = [];
        $device_ips = [];
        $device_host = [];
        $mac_ips = [];
        $mac_host = [];
        $os_arr = ['windows','linux'];
        $ret = new \stdClass();
        $ret->nodes = [];
        $ret->edges = [];
        $id = 0;
        foreach($devices as $item){
            if(in_array($item->ptype,$os_arr)) continue;
            $id ++;
            $stemp = new \stdClass();
            $stemp->id = $id;
            $stemp->hostName = $item->host_name;
            //$stemp->value = $item->device_id;
            $stemp->ip = $item->ip;

            $stemp->logo = $item->logo;
            $stemp->deviceType = $item->deviceType;
            $stemp_2 = new \stdClass();
            $stemp_2->sysObjectId = $item->sysObjectID;
            $stemp_2->hardware = $item->hardware;
            $logo_arr = explode(".",$item->logo);
            $features = $item->features ? '('.$item->features.')' : '';
            $stemp_2->OperatingSystem = strtoupper($logo_arr[0]) . ' ' . $item->ptype . ' ' . $item->version . $features;
            $stemp_2->serial = $item->serial;
            $stemp_2->version = $item->version;

            $stemp->info = $stemp_2;
            $stemp->shape = 'circle';
            $stemp->group = $id;
            array_push($ret->nodes,$stemp);

            $device_ids[$item->device_id] = $id;
            array_push($device_ips,$item->ip);
            $device_host[$item->ip] = $id;
        }
        foreach($lists as $item){
            if(!isset($device_ids[$item->device_id])) continue;
            $local_port_name = $item->port_name;
            if(empty($item->port_name)) $local_port_name = $item->port_name2;
            $remote_port_name = $item->remote_port_name;
            if(empty($item->remote_port_name)) $remote_port_name = $item->remote_port_name2;

            $edege_title = empty($item->remote_port_name2) ? $local_port_name : $local_port_name . ' > ' . $remote_port_name;

            $local_id = $device_ids[$item->device_id];
            if(in_array($item->ipv4_address,$device_ips)){
                $to = $device_host[$item->ipv4_address];
                if($local_id == $to) continue;
                $stemp2 = new \stdClass();
                $stemp2->from = $local_id;
                $stemp2->to = $to;
                //$stemp2->arrows = 'to';
                $stemp2->port = $item->port_id;
                //$stemp2->portName = empty($item->port_name)? '':$item->port_name;
                $stemp2->portName = $edege_title;
                array_push($ret->edges,$stemp2);
            }else{
                if(in_array($item->ipv4_address,$mac_ips)){
                    $to = $mac_host[$item->ipv4_address];
                    $stemp2 = new \stdClass();
                    $stemp2->from = $local_id;
                    $stemp2->to = $to;
                    //$stemp2->arrows = 'to';
                    $stemp2->port = $item->port_id;
                    //$stemp2->portName = empty($item->port_name)? '':$item->port_name;
                    $stemp2->portName = $edege_title;
                    array_push($ret->edges,$stemp2);
                }else{
                    $id++;
                    $stemp = new \stdClass();
                    $stemp->id = $id;
                    $stemp->ip = $item->ipv4_address;
                    $stemp->group = $local_id;
                    //$stemp->title = $item->ipv4_address;
                    array_push($ret->nodes,$stemp);

                    $stemp2 = new \stdClass();
                    $stemp2->from = $local_id;
                    $stemp2->to = $id;
                    //$stemp2->arrows = 'to';
                    $stemp2->port = $item->port_id;
                    //$stemp2->portName = empty($item->port_name)? '':$item->port_name;
                    $stemp2->portName = $edege_title;
                    array_push($ret->edges,$stemp2);

                    array_push($mac_ips,$item->ipv4_address);
                    $mac_host[$item->ipv4_address] = $id;
                }
            }
        }
        return $ret;
    }
}