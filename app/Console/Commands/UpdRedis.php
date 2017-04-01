<?php
/**
 * Created by PhpStorm.
 * User: admin
 * Date: 2016/12/26
 * Time: 17:30
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\MyClass\MyRedisCache;

class UpdRedis extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'updRedis';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'update redis';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        //MyRedisCache::setUserCache();
        //MyRedisCache::setNodeHostCache();
    }
}