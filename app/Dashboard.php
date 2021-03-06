<?php
/**
 * Created by PhpStorm.
 * User: admin
 * Date: 2016/11/7
 * Time: 18:03
 */

namespace App;

use App\MyClass\MyRedisCache;
use Illuminate\Database\Eloquent\Model;
use DB;
use Log;

class Dashboard extends Model
{
    protected $table = "dashboard";

    public static function findBySlug($slug,$uid,$type)
    {
        $res = DB::table('dashboard')->where('type','system')->where('slug',$slug)->first();
        if($res){
            $dasbid = $res->id;
            $ret = Dashboard::findByid($dasbid,$uid);
        }else{
            $ret = MyRedisCache::getMetricByService($slug,$uid,$type);
        }
        return $ret;
    }

    public static function findBySystem($slug)
    {
        $res = DB::table('dashboard')->where('type','show-system')->where('slug',$slug)->first();

        $ret = new \stdClass();
        $ret->message = 'success';
        $ret->code = 0;

        if(empty($res)){
            $ret->message = '未定义仪表盘';
            $ret->result = [];
            return $ret;
        }

        $res->owner = ['id'=>null,'email' => 'test@apmsys.com','name' => '路人甲'];
        $res->order = !$res->order ? "[]" : $res->order;
        $res->is_installed = $res->is_installed ? true:false;
        $res->is_favorite = $res->is_favorite ? true:false;
        $res->update_time = strtotime($res->update_time) * 1000;
        $res->create_time = strtotime($res->create_time) * 1000;
        $ret->result = $res;

        return $ret;
    }

    public static function findByid($id,$uid)
    {
        $res = DB::table('dashboard')->where('id',$id)->first();
        $ret = new \stdClass();
        $ret->code = 0;
        if(empty($res)){
            $ret->message = 'fail';
            $ret->result = [];
            return $ret;
        }
        if($res->type == 'user' && $uid != $res->user_id){
            $ret->code = 403;
            $ret->message = 'fail';
            $ret->result = [];
            return $ret;
        }
        if($res->type == 'user'){
            $res->owner = DB::table('user')->where('id',$res->user_id)->select('id','email','nickname as name')->first();
        }else if($res->type == 'system'){
            $res->owner = ['id'=>null,'email' => 'test@apmsys.com','name' => '路人甲'];
        }
        $res->order = !$res->order ? "[]" : $res->order;
        $ret->message = 'success';
        $res->is_installed = $res->is_installed ? true:false;
        $res->is_favorite = $res->is_favorite ? true:false;
        $res->update_time = strtotime($res->update_time) * 1000;
        $res->create_time = strtotime($res->create_time) * 1000;
        $ret->result = $res;

        return $ret;
    }


}