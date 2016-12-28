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
        if ($this->attempts() >= 1) {
            $this->delete(); //队列任务执行超过两次就释放
        }

        set_time_limit(0);

        //Log::info("tagjob_start === " . time());
        if(empty($this->tags)) return;
        foreach($this->tags as $sub){
            //保存tag
            Tag::saveTag($sub);
        }
        //Log::info("tagjob_end === " . time());
        return;

    }


}