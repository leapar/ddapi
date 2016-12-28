<?php

/**
 * Created by PhpStorm.
 * User: admin
 * Date: 2016/11/7
 * Time: 17:17
 */

namespace App\Jobs;

use App\Host;
use App\HostUser;
use App\Metric;
use App\MetricHost;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Mockery\CountValidator\Exception;
use DB;
use Log;

class CheckJob extends Job
{
    //use InteractsWithQueue, SerializesModels;


    private $service_checks;
    private $hostname;
    private $uid;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($service_checks,$hostname,$uid)
    {
        $this->service_checks = $service_checks;
        $this->hostname = $hostname;
        $this->uid = $uid;
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
            //Log::info("checkjob_start === " . time());

            $host = Host::findHostByPname($this->hostname,$this->uid);

            if(!$host) return;
            //Log::info("service_checks === " . json_encode($this->service_checks));
            //3 保存metric_host service_check
            foreach($this->service_checks as $service_check){

                $data = [];
                $check = $service_check->check;
                $tmps = explode(".",$check);
                if(count($tmps) == 3 && $tmps[2] == "check_status"){
                    if(isset($check->tags)){
                        $tags = explode(":",$check->tags[0]);
                        $integration = $tags[1];
                    }
                }
                if(count($tmps) == 2){
                    $integration = $tmps[0];
                }

                if(!isset($integration)) continue;

                $res = Metric::findByIntegration($integration);
                if($res){
                    $metricid = $res->id;
                    DB::table('metric_host')->where('metricid',$metricid)->where('hostid',$host->id)
                            ->update(['status'=>$service_check->status]);
                }

            }
            //Log::info("checkjob_end === " . time());

        }catch(Exception $e){
            Log::error($e->getMessage());
        }

        return;

    }


}