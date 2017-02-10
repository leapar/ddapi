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
use Illuminate\Support\Facades\Redis;
use Log;
use Cache;
use Mockery\CountValidator\Exception;
use App\MyClass\MyRedisCache;

class Tag extends Model
{
    protected $table = "tag";

    protected $fillable = ['id'];

    public static function saveTagV1($sub){
        try{
            $hostname = $sub->tags->host;
            $uid = $sub->tags->uid;
            $hostid = md5(md5($uid).md5($hostname));

            $host_tags = 'host-tags';
            $type = isset($sub->$host_tags) && $sub->$host_tags == 'host-tags' ? 1 : 0;
            if($type == 1){
                $tags = (array)$sub->tags->$host_tags;
            }else{
                $tags = (array)$sub->tags;
            }
            //Log::info("tag_type ===>".$type);
            foreach($tags as $key => $value){

                $tagid = md5($key.$value);

                $res = Tag::findByTagid($tagid);
                if(!$res){
                    DB::insert('insert into tag (`id`,`key`,`value`,`type`,`createtime`) values (?,?,?,?,current_timestamp())',[$tagid,$key,$value,$type]);
                }
                //3,保存tag_host
                $res2 = Tag::findTagHostByHostidTagid($hostid,$tagid);
                if(!$res2){
                    $is_tsdb_tag = 0;
                    $stdtags = array('host','uid','device','instance');
                    if(in_array($key,$stdtags)){
                        $is_tsdb_tag = 1;
                    }
                    DB::insert('insert into tag_host (`hostid`,`tagid`,`is_tsdb_tag`,`createtime`,`updatetime`) values (?,?,?,sysdate(),sysdate())',[$hostid,$tagid,$is_tsdb_tag]);
                }

            }
        }catch(Exception $e){
            Log::info($e->getMessage());
        }
    }

    public static function findById($id)
    {
        return DB::table('tag')->where("id",$id)->first();
    }

    public static function findByHostidTagId($hostid,$tagid)
    {
        return DB::table('tag_host')->where('hostid', $hostid)->where('tagid',$tagid)->first();
    }

    /**
     * 检查tag是否存在，存在则返回 tagid
     * @param $key
     * @param $val
     * @return bool
     */
    public static function findByTagid($tagid)
    {
        //return DB::table('tag')->where('key',$key)->where('value',$val)->first();
        $tags = MyRedisCache::getRedisCache("tag_cache");
        if(empty($tags)){
            Tag::updateTagCache();
            $tags = MyRedisCache::getRedisCache("tag_cache");
        }
        foreach($tags as $tag){
            if($tag->id == $tagid){
                return true;
            }
        }
        return false;
    }

    //查看 tag_host是否存在
    public static function findTagHostByHostidTagid($hostid,$tagid)
    {
        //return DB::table('tag_host')->where('tagid',$tagid)->where('hostid',$hostid)->first();
        $tag_host = MyRedisCache::getRedisCache("tag_host_cache");
        if(empty($tag_host)){
            Tag::updateTagHostCache();
            $tag_host = MyRedisCache::getRedisCache("tag_host_cache");
        }
        foreach($tag_host as $item){
            if($item->tagid == $tagid && $item->hostid == $hostid){
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
        $res = DB::table('tag_host')->select('hostid', 'tagid')->get();
        MyRedisCache::setRedisCache("tag_host_cache",$res);
    }

    /**
     * 更新tag缓存
     */
    public static function updateTagCache()
    {
        $res = DB::table('tag')->select("id")->get();
        MyRedisCache::setRedisCache("tag_cache",$res);
    }
}