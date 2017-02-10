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

    public static function findByHostid($hostid)
    {
        return DB::table('host')->where('id',$hostid)->first();
    }


}