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

    /**
     * 保存 tag tag_host
     * @param $sub
     */
    public static function saveTag($sub)
    {
        try{
            $hostname = $sub->tags->host;
            $uid = $sub->tags->uid;

            $host_tags = 'host-tags';
            $type = isset($sub->$host_tags) && $sub->$host_tags == 'host-tags' ? 1 : 0;
            if($type == 1){
                $tags = (array)$sub->tags->$host_tags;
            }else{
                $tags = (array)$sub->tags;
            }
            //Log::info("tag_type ===>".$type);
            foreach($tags as $key => $value){
                DB::beginTransaction();

                //2,tag 是否存在
                $res = Tag::findByKeyValueHostid($key,$value);
                if($res){
                    //1,1存在
                    $tagid = $res->id;
                }else{
                    //1,2不存在 添加tag
                    $tagid = md5(uniqid().rand(1111,9999));
                    DB::insert('insert into tag (`id`,`key`,`value`,`type`,`createtime`) values (?,?,?,?,current_timestamp())',[$tagid,$key,$value,$type]);
                    Tag::updateTagCache();
                }

                //3,保存tag_host
                $host = Host::findHostByPname($hostname,$uid);
                if($host){
                    $res2 = Tag::findTagHostByHostidTagid($host->id,$tagid);
                    if(!$res2){
                        $taghostid = md5(uniqid().rand(1111,9999));
                        $is_tsdb_tag = 0;
                        $stdtags = array('host','uid','device','instance');
                        if(in_array($key,$stdtags)){
                            $is_tsdb_tag = 1;
                        }
                        DB::insert('insert into tag_host (`id`,`hostid`,`tagid`,`is_tsdb_tag`,`createtime`,`updatetime`) values (?,?,?,?,sysdate(),sysdate())',[$taghostid,$host->id,$tagid,$is_tsdb_tag]);
                        Tag::updateTagHostCache();
                    }
                }

                DB::commit();
            }
        }catch(Exception $e){
            DB::rollBack();
        }
    }

    public static function saveTagV1($sub){
        try{
            $hostname = $sub->tags->host;
            $uid = $sub->tags->uid;
            $hostid = md5($uid.$hostname);

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

                $res = Tag::findById($tagid);
                if(!$res){
                    DB::insert('insert into tag (`id`,`key`,`value`,`type`,`createtime`) values (?,?,?,?,current_timestamp())',[$tagid,$key,$value,$type]);

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

    /**
     * 检查tag是否存在，存在则返回 tagid
     * @param $key
     * @param $val
     * @return bool
     */
    public static function findByKeyValueHostid($key,$val)
    {
        //return DB::table('tag')->where('key',$key)->where('value',$val)->first();
        $tags = MyRedisCache::getRedisCache("tag_cache");
        if(empty($tags)){
            Tag::updateTagCache();
            $tags = MyRedisCache::getRedisCache("tag_cache");
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
        $res = DB::table('tag')->select("id",'key','value')->get();
        MyRedisCache::setRedisCache("tag_cache",$res);
    }

    public static function findTagHostByHostid($hostid)
    {
        return DB::table('tag_host')
            ->leftJoin('tag','tag_host.tagid','=','tag.id')
            ->where('tag_host.hostid',$hostid)
            ->select('tag.id as tagid','tag.key','tag.value','tag_host.is_tsdb_tag')
            ->get();
    }
}