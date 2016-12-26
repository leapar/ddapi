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
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Mockery\CountValidator\Exception;
use DB;
use Log;

class HostJob extends Job
{
    //use InteractsWithQueue, SerializesModels;


    private $metrics_in;
    private $uid;

    private $cpuIdle;
    private $disk_total;
    private $disk_used;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($metrics_in,$uid,$cpuIdle,$disk_total,$disk_used)
    {
        $this->metrics_in = $metrics_in;
        $this->uid = $uid;

        $this->cpuIdle = $cpuIdle;
        $this->disk_total = $disk_total;
        $this->disk_used = $disk_used;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->attempts() >= 1) {
            $this->release(); //队列任务执行超过两次就释放
        }
        try{
            //Log::info("hostjob_start === " . time());
            DB::beginTransaction();

            $hostname = $this->metrics_in->internalHostname;
            $host = Host::findHostByPname($hostname,$this->uid);

            //1 保存host
            $load15 = "system.load.15";
            $data = [
                "ptype" => $this->metrics_in->os,
                "status" => 1, //todo 主机状态 0,1
                "cpu" => 100 - $this->cpuIdle,
                "iowait" => isset($this->metrics_in->cpuWait) ? $this->metrics_in->cpuWait : null,
                //"gohai" => isset($this->metrics_in->gohai) ? json_encode($this->metrics_in->gohai) : null,
                "load15" => isset($this->metrics_in->$load15) ? $this->metrics_in->$load15 : null,
                //"updatetime" => date("Y-m-d H:i:s"),
                "colletcionstamp" => $this->metrics_in->collection_timestamp,
                "processmetrics" => isset($this->metrics_in->processes) ? json_encode($this->metrics_in->processes) : null,
                "diskutilization" => $this->disk_used / $this->disk_total * 100,
                "disksize" => $this->disk_total,
                "uuid" => $this->metrics_in->uuid
            ];
            if(isset($this->metrics_in->gohai) && !empty($this->metrics_in->gohai)){
                $data["gohai"] = $this->metrics_in->gohai;
            }
            if($host){
                $hostid = $host->id;
                Host::updateHost($hostid,$data);
            }else{
                $hostid= md5(uniqid() . rand(1111,9999));
                $data['id'] = $hostid;
                $data["host_name"] = $hostname;
                $data['createtime'] = date("Y-m-d H:i:s");
                Host::saveHost($data);
            }

            //2 host_user
            HostUser::saveHostUser($hostid,$this->uid);

            DB::commit();
            //Log::info("hostjob_end === " . time());
        }catch(Exception $e){
            DB::rollBack();
        }

        return;

    }


}