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

class Host extends Model
{
    protected $table = "host";


    public static function saveHost($data)
    {
        DB::table('host')->insert($data);

    }

    public static function updateHost($id,$data)
    {
        DB::table('host')->where('id',$id)->update($data);
        DB::update('update host set updatetime=current_timestamp() where id = ?', [$id]);
    }

    public static function findHostByPname($hostname,$uid)
    {
        $host = DB::table('host_user')
            ->leftjoin('host','host_user.hostid','=','host.id')
            ->where('host.host_name',$hostname)
            ->where('host_user.userid',$uid)->first();
        return $host;
    }

    public static function findByHostid($hostid)
    {
        return DB::table('host')->where('id',$hostid)->first();
    }



}