<?php

/**
 * Created by PhpStorm.
 * User: admin
 * Date: 2016/11/8
 * Time: 9:30
 */

namespace App\Jobs;

use App\Metric;
use App\MetricHost;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Mockery\CountValidator\Exception;
use App\Tag;
use DB;
use Log;

class MetricJobV1 extends Job
{
    //use InteractsWithQueue, SerializesModels;


    private $hostid;
    private $service_checks;
    private $check_run;
    private $agent_checks;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($hostid,$service_checks=null,$check_run=null,$agent_checks=null)
    {
        $this->hostid = $hostid;
        $this->check_run = $check_run;
        $this->service_checks = $service_checks;
        $this->agent_checks = $agent_checks;
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

        try{
            MetricHost::saveMetricHostJob($this->hostid,$this->service_checks,$this->check_run,$this->agent_checks);
        }catch(Exception $e){
            Log::info($e->getMessage());
        }

    }


}