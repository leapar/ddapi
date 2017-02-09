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
use Mockery\CountValidator\Exception;
use Illuminate\Support\Facades\Redis;
use DB;
use Log;

class HostJobV1 extends Job
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
            $this->delete(); //队列任务执行超过两次就释放
        }
        try{

            $hostname = $this->metrics_in->internalHostname;
            $hostid = md5($this->uid.$hostname);

            //1 保存host
            $load15 = "system.load.15";
            $redis_data = [
                "hostId" => $hostid,
                "cpu" => 100 - $this->cpuIdle,
                "iowait" => isset($this->metrics_in->cpuWait) ? $this->metrics_in->cpuWait : null,
                //"gohai" => isset($this->metrics_in->gohai) ? json_encode($this->metrics_in->gohai) : null,
                "load15" => isset($this->metrics_in->$load15) ? $this->metrics_in->$load15 : null,
                //"updatetime" => date("Y-m-d H:i:s"),
                "colletcionstamp" => $this->metrics_in->collection_timestamp,
                //"processmetrics" => isset($this->metrics_in->processes) ? json_encode($this->metrics_in->processes) : null,
                "diskutilization" => $this->disk_used / $this->disk_total * 100,
                "disksize" => $this->disk_total,
                "hostName" => $hostname,
                "ptype" => $this->metrics_in->os,
                "uuid" => $this->metrics_in->uuid,
                "updatetime" => date("Y-m-d H:i:s")
            ];

            //保存数据到redis
            $hsname = "HOST_DATA_".$this->uid;
            Redis::command('HSET',[$hsname,$hostid,json_encode($redis_data)]);
            $host_process = "PROCESS_".$hostid;
            $process = isset($this->metrics_in->processes) ? json_encode($this->metrics_in->processes->processes) : null;
            Redis::command('HSET',[$hsname,$host_process,$process]);

            $data = [
                "host_name" => $hostname,
                "ptype" => $this->metrics_in->os,
                "uuid" => $this->metrics_in->uuid,
                "lastStartTime" => date("Y-m-d H:i:s")
            ];

            if(isset($this->metrics_in->gohai) && !empty($this->metrics_in->gohai)){
                $data["gohai"] = $this->metrics_in->gohai;
                $host = Host::findByHostid($hostid);
                if($host){
                    DB::table('host')->where('id',$hostid)->update($data);
                }else{
                    $data['createtime'] = date("Y-m-d H:i:s");
                    $data['id'] = $hostid;
                    DB::table('host')->insert($data);
                    DB::table('host_user')->insert(['userid'=>$this->uid,'hostid'=>$hostid]);
                }
            }

        }catch(Exception $e){
            $this->delete();
            Log::info($e->getMessage());
        }

        return;

    }


}