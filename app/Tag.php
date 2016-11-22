<?php
/**
 * Created by PhpStorm.
 * User: admin
 * Date: 2016/11/7
 * Time: 18:03
 */

namespace App;

use Illuminate\Database\Eloquent\Model;
use DB;
use Log;
use Cache;
use Mockery\CountValidator\Exception;

class Tag extends Model
{
    protected $table = "tag";

    /**
     * 保存 tag tag_host
     * @param $sub
     */
    public static function saveTag($sub)
    {
        try{
            DB::beginTransaction();

            $metricname = $sub->metric;
            $hostname = $sub->tags->host;

            $tags = (array)$sub->tags;
            foreach($tags as $key => $value){
                //2,tag 是否存在
                $res = Tag::findByKeyValueHostid($key,$value);
                if($res){
                    //1,1存在
                    $tagid = $res->id;
                }else{
                    //1,2不存在 添加tag
                    $tagid = md5(uniqid());
                    DB::insert('insert into tag (`id`,`key`,`value`,`createtime`) values (?,?,?,current_timestamp())',[$tagid,$key,$value]);
                    Tag::updateTagCache();
                }

                //3,保存tag_host
                $host = Host::findHostByPname($hostname);
                if($host){
                    $res2 = Tag::findTagHostByHostidTagid($host->id,$tagid);
                    if(!$res2){
                        $taghostid = md5(uniqid());
                        DB::insert('insert into tag_host (`id`,`hostid`,`tagid`,`createtime`) values (?,?,?,current_timestamp())',[$taghostid,$host->id,$tagid]);
                        Tag::updateTagHostCache();
                    }
                }
            }

            DB::commit();
        }catch(Exception $e){
            DB::rollBack();
        }
    }

    /**
     * 检查tag是否存在，存在则返回 tagid
     * @param $key
     * @param $val
     * @return bool
     */
    public static function findByKeyValueHostid($key,$val)
    {
        //return DB::table('tag')->where('key',$key)->where('value',$val)->first();
        $tags = Cache::get("tag_cache");
        if(empty($tags)){
            Tag::updateTagCache();
            $tags = Cache::get("tag_cache");
        }
        foreach($tags as $tag){
            if($tag->key == $key && $tag->value == $val){
                return $tag;
            }
        }
        return false;
    }

    //查看 tag_host是否存在
    public static function findTagHostByHostidTagid($hostid,$tagid)
    {
        //return DB::table('tag_host')->where('tagid',$tagid)->where('hostid',$hostid)->first();
        $tag_host = Cache::get("tag_host_cache");
        if(empty($tag_host)){
            Tag::updateTagHostCache();
            $tag_host = Cache::get("tag_host_cache");
        }
        foreach($tag_host as $tid => $hid){
            if($tagid == $tid && $hostid == $hid){
                return true;
            }
        }
        return false;
    }

    /**
     * 更新tag_host缓存
     */
    public static function updateTagHostCache()
    {
        $res = DB::table('tag_host')->pluck('hostid', 'tagid');
        Metric::saveCache("tag_host_cache",$res->toArray());
    }

    /**
     * 更新tag缓存
     */
    public static function updateTagCache()
    {
        $res = DB::table('tag')->select("id",'key','value')->get();
        Metric::saveCache("tag_cache",$res);
    }
}