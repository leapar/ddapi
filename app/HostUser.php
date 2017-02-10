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

class HostUser extends Model
{
    protected $table = "host_user";

    public static function findByHostid($hostid,$userid)
    {
        $host = DB::table('host_user')->where('userid',$userid)->where('hostid', $hostid)->first();
        return $host;
    }

    public static function findUserHostByUID($userid)
    {
        return DB::table('host_user')->where('userid',$userid)->select('hostid')->get();
    }
}