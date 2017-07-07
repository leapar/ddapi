<?php

/**
 * Created by PhpStorm.
 * User: cfeng
 * Date: 2017/6/23
 * Time: 14:47
 */

namespace App\Jobs;


use Mockery\CountValidator\Exception;
use App\MyClass\Metric;
use DB;
use Log;

class VcenterV1 extends Job
{
    //use InteractsWithQueue, SerializesModels;

    private $data;
    private $uid;
    private $host;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($data,$host,$uid)
    {
        $this->data = $data;
        $this->uid = $uid;
        $this->host = $host;
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
        try{

            $arrPost = array();
            $metric = new Metric($this->data,$this->host,$this->uid);
            $arrPost = $metric->vcenterMetric($arrPost);

            //return response()->json($arrPost);
            if(count($arrPost) > 0) {
                $metric->post2tsdb($arrPost);
                $arrPost = array();
            }

        }catch(Exception $e){
            $this->delete();
            Log::info($e->getMessage());
        }

        return;

    }


}