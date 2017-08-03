<?php

namespace App\Http\Controllers;

use App\Dashboard;
use App\Metric;
use App\MyClass\MyApi;
use App\MyClass\MyRedisCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Response;
use DB;
use Log;
use Mockery\Exception;


class ApiController  extends Controller
{
    public function metricsJson(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $res_u = MyApi::checkUidError($uid);
        if($res_u->code != 0) return response()->json($res_u);

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
    }

    public function tagJson(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $res_u = MyApi::checkUidError($uid);
        if($res_u->code != 0) return response()->json($res_u);

        $url = config('myconfig.tsdb_search_url') . '/api/search/uidmeta?query=custom.uid:'.$uid.'&limit=10000';
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
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $res_u = MyApi::checkUidError($uid);
        if($res_u->code != 0) return response()->json($res_u);

        $ret = MyApi::normalModeList($uid);
        return response()->json($ret);
    }

    public function showJson(Request $request,$dasbid)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 20;
        $res_u = MyApi::checkUidError($uid);
        if($res_u->code != 0) return response()->json($res_u);

        if(!is_numeric($dasbid)){
            $slug = $dasbid;
            /*if($slug == "system"){
                $ret = Dashboard::findBySystem($slug);//预埋的dashboard
            }else{*/
                $ret = Dashboard::findBySlug($slug,$uid,'show');
            //}
        }else{
            $ret = Dashboard::findByid($dasbid,$uid);
        }

        return response()->json($ret);
    }

    public function dashboardsJson(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $res_u = MyApi::checkUidError($uid);
        if($res_u->code != 0) return response()->json($res_u);
        $ret = MyApi::dashboardsJson($uid,$request);
        return response()->json($ret);
    }

    private function getChartsJson($uid,$dasbid)
    {
        $res = true;
        if(!is_numeric($dasbid)) {
            $slug = $dasbid;
            $res = DB::table('dashboard')->where('type','system')->where('slug', $slug)->first();
            if($res){
                $dasbid = $res->id;
            }
        }
        if(!$res){
            $ret = Dashboard::findBySlug($slug,$uid,'chart');
        }else{
            $charts = DB::table('charts')->where('dashboard_id',$dasbid)->get();
            $ret = new \stdClass();
            $ret->code = 0;
            $ret->message = "success";
            foreach($charts as $chart){
                $chart->meta = json_decode($chart->meta,true);
                $chart->metrics = json_decode($chart->metrics,true);
            }
            $ret->result = $charts;
        }

        return $ret;
    }

    public function chartsJson(Request $request,$dasbid)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 20;
        $res_u = MyApi::checkUidError($uid);
        if($res_u->code != 0) return response()->json($res_u);

        $ret = $this->getChartsJson($uid,$dasbid);
        return response()->json($ret);
    }

    //添加图表
    public function addJson(Request $request,$dasid)
    {
        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = "success";
        $ret->result = false;
        if(!$request->has('chart')) return response()->json($ret);
        $res = DB::table('dashboard')->where('id',$dasid)->first();
        if(empty($res)){
            $ret->message = "fail 未能获取仪表盘";
            return response()->json($ret);
        }
        $result = MyApi::addJson($res,$request,$dasid);
        $ret->result = $result;
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
        $param = \GuzzleHttp\json_decode($request->chart);
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
        $res_u = MyApi::checkUidError($uid);
        if($res_u->code != 0) return response()->json($res_u);

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
        $res_u = MyApi::checkUidError($uid);
        if($res_u->code != 0) return response()->json($res_u);
        $ret = new \stdClass();
        $ret->code = 0;
        $ret->result = [];
        if(!$request->has('dashboardName') || !$request->has('dashboardDesc')){
            $ret->message = '参数错误请重试';
            return response()->json($ret);
        }
        $res = DB::table('dashboard')->where('id',$dasid)->first();
        if(!$res){
            $ret->message = '未能获取仪表盘';
            return response()->json($ret);
        }
        $id = MyApi::cloneDasb($res,$request,$uid,$dasid);
        $ret = Dashboard::findByid($id,$uid);
        return response()->json($ret);
    }

    public function deleteDasb(Request $request,$dasid){

        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $res_u = MyApi::checkUidError($uid);
        if($res_u->code != 0) return response()->json($res_u);

        $ret = Dashboard::findByid($dasid,$uid);
        if(empty($ret->result)){
            $ret->message = '未能获取仪表盘';
            return response()->json($ret);
        }
        DB::table('dashboard')->where('id',$dasid)->delete();
        DB::table('charts')->where('id',$dasid)->delete();

        return response()->json($ret);

    }

    //浏览指标 新建仪表盘
    public function addMore(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $res_u = MyApi::checkUidError($uid);
        if($res_u->code != 0) return response()->json($res_u);

        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = 'success';
        $ret->result = [];
        $data = json_decode($request->getContent());
        if(empty($data)){
            $ret->message = "fail 未能获取参数";
            return response()->json($ret);
        }
        $id = MyApi::addMore($data,$uid);
        $ret = Dashboard::findByid($id,$uid);

        return response()->json($ret);
    }

    //浏览指标 已有仪表盘
    public function batchAdd(Request $request,$dasbid)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $res_u = MyApi::checkUidError($uid);
        if($res_u->code != 0) return response()->json($res_u);

        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = 'success';
        $ret->result = [];
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
        $orders = MyApi::batchAdd($res,$data,$dasbid);
        $ret = $this->getChartsJson($uid,$dasbid);
        return response()->json($ret);
    }

    //添加模板
    public function templateAdd(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $res_u = MyApi::checkUidError($uid);
        if($res_u->code != 0) return response()->json($res_u);

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

    //更新模板
    public function templateUpdate(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $res_u = MyApi::checkUidError($uid);
        if($res_u->code != 0) return response()->json($res_u);

        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = 'success';
        $ret->result = [];

        $data = json_decode($request->getContent());
        if(empty($data)){
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

    //删除模板
    public function templateDel(Request $request,$id)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $res_u = MyApi::checkUidError($uid);
        if($res_u->code != 0) return response()->json($res_u);

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
        $res_u = MyApi::checkUidError($uid);
        if($res_u->code != 0) return response()->json($res_u);

        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = 'success';
        $ret->result = [];

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

    public function metricTypes(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $res_u = MyApi::checkUidError($uid);
        if($res_u->code != 0) return response()->json($res_u);

        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = 'success';
        $ret->result = [];

        if(!$request->has('metric')){
            $ret->message = '参数错误';
            return response()->json($ret);
        }
        $ret->result = MyApi::getMetricTypes($uid,$request->metric);

        return response()->json($ret);
    }

    public function metricTypesUpdate(Request $request)
    {
        $uid = $request->header('X-Consumer-Custom-ID');
        //$uid = 1;
        $res_u = MyApi::checkUidError($uid);
        if($res_u->code != 0) return response()->json($res_u);

        $ret = new \stdClass();
        $ret->code = 0;
        $ret->message = 'success';
        $ret->result = [];

        $data = json_decode($request->getContent());
        $ret->result = MyApi::updateMetricTypes($uid,$data);

        return response()->json($ret);
    }


}

