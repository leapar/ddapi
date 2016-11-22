<?php

/**
 * Created by PhpStorm.
 * User: admin
 * Date: 2016/11/8
 * Time: 9:30
 */

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Mockery\CountValidator\Exception;
use App\Tag;
use DB;
use Log;

class TagJob extends Job
{
    //use InteractsWithQueue, SerializesModels;


    private $tags;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($tags)
    {
        $this->tags = $tags;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->attempts() > 2) {
            $this->release(); //队列任务执行超过两次就释放
        }

        if(empty($this->tags)) return;
        foreach($this->tags as $sub){
            //保存tag
            Tag::saveTag($sub);
        }

        return;

    }


}