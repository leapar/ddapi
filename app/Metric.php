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
use Cache;
use Illuminate\Support\Facades\Redis;
use Log;
use App\MyClass\MyRedisCache;
use App\Host;
use Mockery\CountValidator\Exception;

class Metric extends Model
{
    protected $table = "metric";

}