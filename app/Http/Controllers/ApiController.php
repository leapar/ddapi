<?php

namespace App\Http\Controllers;

use App\Dashboard;
use App\Metric;
use App\MyClass\MyApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Response;
use DB;
use Mockery\Exception;


class ApiController  extends Controller
{
    public function metricsJson(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        if(!$uid) return;

        $host_tags = MyApi::getCustomTagsByHost($uid);

        try{
            $res = MyApi::getMetricJson($uid);
            $result = [];
            if(!empty($res)){
                $result = MyApi::lookupRes($res,$host_tags);
            }
            $code = 0;
            $message = 'success';
        }catch(Exception $e){
            $result = [];
            $code = 500;
            $message = 'fail';
            Log::info('error == ' . $e->getMessage());
        }
        $ret = new \stdClass();
        $ret->code = $code;
        $ret->message = $message;
        $ret->result = $result;

        return response()->json($ret);
    }

    public function test(Request $request)
    {
        $uid = 1;
        if(!$uid) return;

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
        try{
            $res = MyApi::getMetricJson($uid);
            //var_dump($res);exit();
            $result = [];
            if(!empty($res)){
                $result = MyApi::lookupRes($res,$host_tags);
            }
            $code = 0;
            $message = 'success';
        }catch(Exception $e){
            $result = [];
            $code = 500;
            $message = 'fail';
            Log::info('error == ' . $e->getMessage());
        }
        $ret = new \stdClass();
        $ret->code = $code;
        $ret->message = $message;
        $ret->result = $result;
        return response()->json($ret);
    }

    public function tagJson(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $url = MyApi::TSDB_URL . '/api/search/uidmeta?query=custom.uid:'.$uid.'&limit=10000';
        $res = \GuzzleHttp\json_decode(MyApi::httpGet($url)); // 自定义tag

        $m_res = MyApi::getMetricJson($uid);
        //var_dump($res);exit();
        $m_result = [];
        if(!empty($m_res)){
            $m_result = MyApi::lookupRes($m_res);
        }
        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = 'success';
        $ret->result = [];
        foreach($res->results as $item){
            if($item->custom){
                foreach($item->custom as $key => $value){
                    if($key != 'uid'){
                        array_push($ret->result,$key.':'.$value);
                    }
                }
            }
        }
        foreach($m_result as $item){
            $ret->result = array_merge($ret->result,$item->tags);
        }
        $ret->result = array_values(array_unique($ret->result));
        //echo $res;
        return response()->json($ret);
    }

    public function normalModeList(Request $request)
    {
        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = 'success';
        $ret->result = [];

        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        if(!$uid){
            $ret->message = '请求出错，请重试';
            return response()->json($ret);
        };

        $metrics = [];
        $metric_hosts = DB::table('metric_host')
            ->leftJoin('host_user', 'metric_host.hostid', '=', 'host_user.hostid')
            ->where('host_user.userid',$uid)
            ->select('metric_host.check_run','metric_host.service_checks')->get();
        foreach($metric_hosts as $metric_host){
            $check_run  = \GuzzleHttp\json_decode($metric_host->check_run);
            $service_check = \GuzzleHttp\json_decode($metric_host->service_checks);
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
        return response()->json($ret);
    }

    public function showJson(Request $request,$dasbid)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $ret = Dashboard::findByid($dasbid,$uid);
        return response()->json($ret);
    }

    public function dashboardsJson(Request $request)
    {

        $ret = new \stdClass();
        $ret->code = 0;
        $ret->result = [];

        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        if(!$uid){
            $ret->message = '请求出错，请重试';
            return response()->json($ret);
        };

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

        return response()->json($ret);
    }

    public function chartsJson($dasbid)
    {
        $charts = DB::table('charts')->where('dashboard_id',$dasbid)->get();

        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = "success";
        foreach($charts as $chart){
            $chart->meta = json_decode($chart->meta,true);
            //$chart->meta = $chart->meta;
            $chart->metrics = json_decode($chart->metrics,true);
            //$chart->metrics = $chart->metrics;
        }
        $ret->result = $charts;
        if(empty($ret->result)){
            Log::info('charts_result ===== ');
        }

        return response()->json($ret);
    }

    public function addJson(Request $request,$dasid)
    {
        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = "success";
        $ret->result = false;

        if(!$request->has('chart')) return response()->json($ret);

        $param = $order = \GuzzleHttp\json_decode($request->chart);
        $data = [
            'name' => $param->name,
            'dashboard_id' => $dasid,
            'type' => $param->type,
            'meta' => json_encode($param->meta),
            'metrics' => json_encode($param->metrics),
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s')
        ];
        $res = DB::table('charts')->insert($data);
        $ret->result = $res;

        return response()->json($ret);
    }

    public function updateChart(Request $request,$chartid,$dasid)
    {
        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = "success";
        $ret->result = false;

        if(!$request->has('chart')){
            $ret->message = "参数错误";
            return response()->json($ret);
        }

        $param = $order = \GuzzleHttp\json_decode($request->chart);
        $data = [
            'name' => $param->name,
            'dashboard_id' => $dasid,
            'type' => $param->type,
            'meta' => json_encode($param->meta),
            'metrics' => json_encode($param->metrics),
            'update_time' => date('Y-m-d H:i:s')
        ];
        $res = DB::table('charts')->where('id',$chartid)->update($data);
        $ret->result = $res;
        return response()->json($ret);

    }

    public function deleteChart(Request $request,$chartid,$dasid)
    {
        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = "success";
        $ret->result = false;

        $res = DB::table('charts')->where('id',$chartid)->first();
        $res->meta = \GuzzleHttp\json_decode($res->meta);
        $res->metrics = \GuzzleHttp\json_decode($res->metrics);
        $ret->result = $res;
        DB::table('charts')->where('id',$chartid)->delete();
        return response()->json($ret);

    }

    public function updateDasb(Request $request,$dasid)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;

        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = "success";
        $ret->result = [];

        if(!$request->has('charts') && !$request->has('dashboardName')){
            $ret->message = '参数错误请重试';
            return response()->json($ret);
        }

        if($request->has('charts')){
            //charts:[["55",0,0,4,2],["56",8,0,4,2]]
            $param = $order = $request->charts;
            DB::table('dashboard')->where('id',$dasid)->update(['order'=>$param,'update_time' => date("Y-m-d H:i:s")]);

            $ret = Dashboard::findByid($dasid,$uid);
        }
        if($request->has('dashboardName')){
            $param = $order = $request->dashboardName;
            DB::table('dashboard')->where('id',$dasid)->update(['name'=>$param,'update_time' => date("Y-m-d H:i:s")]);

            $ret = Dashboard::findByid($dasid,$uid);
        }

        return response()->json($ret);
    }

    public function cloneDasb(Request $request,$dasid)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;

        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = "success";
        $ret->result = [];

        //dashboardName:1111-clone
        //dashboardDesc:仪表盘描述
        if(!$request->has('dashboardName') || !$request->has('dashboardDesc')){
            $ret->message = '参数错误请重试';
            return response()->json($ret);
        }
        $res = DB::table('dashboard')->where('id',$dasid)->first();
        if(!$res){
            $ret->message = '未能获取仪表盘';
            return response()->json($ret);
        }
        unset($res->id);
        $res->name = $request->dashboardName;
        $res->desc = $request->dashboardDesc;
        $res->type = 'user';
        $res->user_id = $uid;
        $res->is_able = 1;
        $res->create_time = date("Y-m-d H:i:s");
        $res->update_time = date("Y-m-d H:i:s");
        $res = json_encode($res);
        $res = json_decode($res,true);
        $id = DB::table('dashboard')->insertGetId($res);
        $charts = DB::table('charts')->where('dashboard_id',$dasid)->get();
        foreach($charts as $chart){
            unset($chart->id);
            $chart->create_time = date("Y-m-d H:i:s");
            $chart->update_time = date("Y-m-d H:i:s");
            $chart->dashboard_id = $id;
            $chart = json_encode($chart);
            $chart = json_decode($chart,true);
            DB::table('charts')->insert($chart);
        }
        $ret = Dashboard::findByid($id,$uid);

        return response()->json($ret);
    }

    public function deleteDasb(Request $request,$dasid){

        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;

        $ret = Dashboard::findByid($dasid,$uid);
        if(empty($ret->result)){
            $ret->message = '未能获取仪表盘';
            return response()->json($ret);
        }
        DB::table('dashboard')->where('id',$dasid)->delete();
        DB::table('charts')->where('id',$dasid)->delete();

        return response()->json($ret);

    }

    public function addMore(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = 'success';
        $ret->result = [];

        if(!$uid){
            $ret->message = "fail 未知用户";
            return response()->json($ret);
        }

        $data = json_decode($request->getContent());
        //$data = json_decode($data);
        if(empty($data)){
            $ret->message = "fail 未能获取参数";
            return response()->json($ret);
        }
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

        foreach($charts as $chart){
            $data = [
                'name' => $chart->dashboard_chart_name,
                'create_time' => date("Y-m-d H:i:s"),
                'update_time' => date("Y-m-d H:i:s"),
                'dashboard_id' => $id,
                'type' => $chart->dashboard_chart_type,
                'metrics' => json_encode($chart->metrics)
            ];
            DB::table('charts')->insert($data);
        }

        $ret = Dashboard::findByid($id,$uid);

        return response()->json($ret);
    }

    public function batchAdd(Request $request,$dasbid)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = 'success';
        $ret->result = [];
        if(!$uid){
            $ret->message = "fail 未知用户";
            return response()->json($ret);
        }
        $res = DB::table('dashboard')->where('id',$dasbid)->first();
        if(empty($res)){
            $ret->message = "fail 未能获取仪表盘";
            return response()->json($ret);
        }
        $data = json_decode($request->getContent());
        if(empty($data)){
            $ret->message = "fail 未能获取参数";
            return response()->json($ret);
        }

        foreach($data as $chart){
            $data = [
                'name' => $chart->dashboard_chart_name,
                'create_time' => date("Y-m-d H:i:s"),
                'update_time' => date("Y-m-d H:i:s"),
                'dashboard_id' => $dasbid,
                'type' => $chart->dashboard_chart_type,
                'metrics' => json_encode($chart->metrics)
            ];
            DB::table('charts')->insert($data);
        }

        return $this->chartsJson($dasbid);
    }

    public function templateAdd(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = 'success';
        $ret->result = [];
        $user = DB::table('user')->where('id',$uid)->select('id','nickname','username')->first();
        if(!$uid || empty($user)){
            $ret->message = "fail 未知用户";
            return response()->json($ret);
        }

        $data = json_decode($request->getContent());
        if(empty($data)){
            $ret->message = "fail 未能获取参数";
            return response()->json($ret);
        }

        $sql_data = [
            "template_name" => $data->templateName,
            "tag_key" => json_encode($data->tagKey),
            "selected_tags" => json_encode($data->selectedTags),
            "selected_metrics" => json_encode($data->selectedMetrics),
            "aggregator" => $data->aggregator,
            "chart_name_prefix" => $data->chartNamePrefix,
            "col_display_option" => $data->colDisplayOption,
            //"group_id" => $data->group_id, //todo
            "match_y_axis" => $data->matchYAxis,
            "max_chart_num" => $data->maxChartNum,
            "owner_name" => $user->username,
            "user_id" => $uid,
            "created_at" => date("Y-m-d H:i:s"),
            "updated_at" => date("Y-m-d H:i:s")
        ];

        $id = DB::table('metric_templates')->insertGetId($sql_data);
        $ret = Metric::findMetricTemplateByid($id,$uid);

        return response()->json($ret);
    }

    public function templateUpdate(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;

        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = 'success';
        $ret->result = [];

        $data = json_decode($request->getContent());
        if(!$uid || empty($data)){
            $ret->message = "fail 未能获取参数";
            return response()->json($ret);
        }
        $id = $data->templateId;
        if(empty($id)){
            $ret->message = "templateId 错误";
            return response()->json($ret);
        }

        $sql_data = [
            "template_name" => $data->templateName,
            "tag_key" => json_encode($data->tagKey),
            "selected_tags" => json_encode($data->selectedTags),
            "selected_metrics" => json_encode($data->selectedMetrics),
            "aggregator" => $data->aggregator,
            "chart_name_prefix" => $data->chartNamePrefix,
            "col_display_option" => $data->colDisplayOption,
            //"group_id" => $data->group_id, //todo
            "user_id" => $uid,
            "match_y_axis" => $data->matchYAxis,
            "max_chart_num" => $data->maxChartNum,
            "updated_at" => date("Y-m-d H:i:s")
        ];

        DB::table('metric_templates')->where('template_id',$id)->update($sql_data);

        $ret = Metric::findMetricTemplateByid($id,$uid);

        return response()->json($ret);
    }

    public function templateDel(Request $request,$id)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $ret = Metric::findMetricTemplateByid($id,$uid);
        if(!empty($ret->result)){
            DB::table('metric_templates')->where('template_id',$id)->delete();
        }
        return response()->json($ret);
    }

    public function templateList(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = 'success';
        $ret->result = [];
        if(!$uid){
            $ret->message = "uid获取失败";
            return response()->json($ret);
        }

        $lists = DB::table('metric_templates')->where('user_id',$uid)->orderBy('updated_at','desc')->get();
        foreach($lists as $item){
            $item->updated_at = strtotime($item->updated_at) * 1000;
            $item->created_at = strtotime($item->created_at) * 1000;
            $item->tag_key = json_decode($item->tag_key);
            $item->selected_tags = json_decode($item->selected_tags);
            $item->selected_metrics = json_decode($item->selected_metrics);
        }
        $ret->result = $lists;

        return response()->json($ret);
    }

}

