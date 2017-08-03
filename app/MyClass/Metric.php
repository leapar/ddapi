<?php
/**
 * Created by PhpStorm.
 * User: admin
 * Date: 2016/11/3
 * Time: 13:29
 */

namespace App\MyClass;

use Illuminate\Support\Facades\Cache;
use Log;
use DB;
use Mockery\CountValidator\Exception;
use Symfony\Component\Debug\Exception\FatalErrorException;
use App\MyClass\Vcenter;

class Metric
{
    private $metrics_in;
    private $host;
    private $uid;
    private $tags;

    public function __construct($metrics_in=null,$host=null,$uid=null)
    {
        $this->metrics_in = $metrics_in;
        $this->host = $host;
        $this->uid = $uid;
        $this->tags = array();
    }

    public function post2tsdb($arrPost) {
        $headers = array('Content-Type: application/json','Content-Encoding: gzip',);
        $gziped_xml_content = gzencode(json_encode($arrPost));

        try{
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_URL, config('myconfig.tsdb_put_url').'/api/put');//?details
            curl_setopt($ch, CURLOPT_TIMEOUT,120);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $gziped_xml_content);
            $res = curl_exec($ch);
            curl_close($ch);
        }catch(Exception $e){
            Log::info("opentsdb error == " . $e->getMessage());
        }catch(FatalErrorException $e){
            Log::info("opentsdb error == " . $e->getMessage());
        }
    }

    public function checkarrPost($arrPost)
    {
        if(count($arrPost) > 30) {
            $this->post2tsdb($arrPost);
            $arrPost = array();
        }
        return $arrPost;
    }

    public function getMetric($metric)
    {
        $num = 2;
        $sub = new \stdClass();
        $sub->metric = $metric[0];
        $sub->timestamp = $metric[1];
        $sub->value = $metric[2];
        $tag = $metric[3];
        $sub->tags = new \stdClass();//$metric[3];
        $sub->tags->host = $this->host;
        $sub->tags->uid = $this->uid;//1;//$metrics_in->uuid;
        if(isset($tag->device_name) && !empty($tag->device_name)) {
            $value = $this->setTgv($tag->device_name);
            if($value){
                $num ++;
                $sub->tags->device = $value;
            }

        }

        if(isset($tag->tags)) {
            foreach($tag->tags as $value) {
                $tmps = explode(":",$value);
                if(count($tmps) == 2 && $num < 6 && !empty($tmps[0]) && !empty($tmps[1])){
                    $tgk = $tmps[0];
                    $value = $this->setTgv($tmps[1]);
                    if($value){
                        $num ++;
                        $sub->tags->$tgk = $value;
                    }
                }
            }
        }

        return $sub;
    }

    /**
     * 已经不使用待丢弃
     */
    private function setTgv($tgv)
    {
        $value = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\_\-\/\xC2\xA0]/u","",$tgv);
        if(!empty($value)){
            return $value;
        }
        return false;
    }

    public function getMetricByOS($arrPost)
    {
        $os = $this->metrics_in->os;
        switch($os){
            case "linux":
                //IO 指标
                $arrPost = $this->getLinuxIOMetric($arrPost);
                break;
            case "mac":
                //IO指标
                $arrPost = $this->getMacIOMetric($arrPost);

                break;
        }
        //内存指标
        $arrPost = $this->getMemMetric($arrPost);
        //cpu指标
        $arrPost = $this->getCpuMetric($arrPost);
        //load指标
        $arrPost = $this->getLoadMetric($arrPost);

        return $arrPost;
    }

    //metric 数据
    private function createMetric($arr,$data,$arrPost,$device="")
    {
        foreach($arr as $key => $item){
            if(!empty($data->$item)){
                $sub = new \stdClass();
                $sub->metric = $key;
                //$sub->timestamp = time();
                $sub->timestamp = floatval($this->metrics_in->collection_timestamp);
                $sub->value = floatval($data->$item);
                $sub->tags = new \stdClass();//$metric[3];
                $sub->tags->host = $this->host;
                $sub->tags->uid = $this->uid;//1;//$metrics_in->uuid;
                if(!empty($device)) $sub->tags->device = $device;

                array_push( $arrPost ,$sub);
                //array_push($this->tags,$sub);
            }

            $arrPost = $this->checkarrPost($arrPost);
        }

        return $arrPost;
    }

    //IO metric 数据
    private function createIOMetric($arr,$arrPost)
    {
        if(!isset($this->metrics_in->ioStats)) return $arrPost;
        $ioStats = $this->metrics_in->ioStats;
        if(empty($ioStats)){
            return $arrPost;
        }

        foreach($ioStats as $device => $data){
            $arrPost = $this->createMetric($arr,$data,$arrPost,$device);
        }

        return $arrPost;
    }

    // 内存 metric linux mac 相同
    private function getMemMetric($arrPost)
    {
        $arr = [
            "system.mem.used" => "emPhysUsed",
            "system.mem.pct_usable" => "memPhysPctUsable",
            "system.mem.free" => "memPhysFree",
            "system.mem.total" => "memPhysTotal",
            "system.mem.usable" => "memPhysUsable",
            "system.swap.used" => "memSwapUsed",
            "system.mem.cached" => "memCached",
            "system.swap.free" => "memSwapFree",
            "system.swap.pct_free" => "memSwapPctFree",
            "system.swap.total" => "memSwapTotal",
            "system.mem.buffered" => "memBuffers",
            "system.mem.shared" => "memShared",
            "system.mem.slab" => "memSlab",
            "system.mem.page_tables" => "memPageTables",
            "system.swap.cached" => "memSwapCached"
        ];

        $arrPost = $this->createMetric($arr,$this->metrics_in,$arrPost);

        return $arrPost;
    }

    //load metric
    private function getLoadMetric($arrPost)
    {
        $arr = [
            'system.load.1' => 'system.load.1',
            'system.load.5' => 'system.load.5',
            'system.load.15' => 'system.load.15',
            'system.load.norm.1' => 'system.load.norm.1',
            'system.load.norm.2' => 'system.load.norm.2',
            'system.load.norm.3' => 'system.load.norm.3'
        ];

        $arrPost = $this->createMetric($arr,$this->metrics_in,$arrPost);

        return $arrPost;

    }

    private function getLinuxIOMetric($arrPost)
    {
        $arr = [
            "system.io.wkb_s" => "wkB/s",
            "system.io.w_s" => "w/s",
            "system.io.rkb_s" => "rkB/s",
            "system.io.r_s" => "r/s",
            "system.io.avg_q_sz" => "avgrq-sz",
            "system.io.await" => "await",
            "system.io.util" => "%util",
        ];

        $arrPost = $this->createIOMetric($arr,$arrPost);

        return $arrPost;
    }

    private function getMacIOMetric($arrPost)
    {
        $arr = [
            "system.io.bytes_per_s" => "system.io.bytes_per_s"
        ];

        $arrPost = $this->createIOMetric($arr,$arrPost);

        return $arrPost;
    }

    //cpu metric linux mac 相同
    private function getCpuMetric($arrPost)
    {
        $arr = [
            "system.cpu.user" => "cpuUser",
            "system.cpu.idle" => "cpuIdle",
            "system.cpu.system" => "cpuSystem",
            "system.cpu.iowait" => "cpuWait",
            "system.cpu.stolen" => "cpuStolen",
            "system.cpu.guest" => "cpuGuest"
        ];

        $arrPost = $this->createMetric($arr,$this->metrics_in,$arrPost);

        return $arrPost;
    }

    public function serise($arrPost)
    {
        foreach($this->metrics_in as $item) {
            $sub = new \stdClass();
            $sub->metric = $item->metric;
            $sub->timestamp = $item->points[0][0];
            $sub->value = $item->points[0][1];
            $sub->tags = new \stdClass();//$metric[3];
            $sub->tags->host = $item->host;
            $sub->tags->uid = $this->uid;
            if (isset($item->device_name) && !empty($item->device_name)) {
                $value = $this->setTgv($item->device_name);
                if($value){
                    $sub->tags->device = $value;
                }
            }
            if(isset($item->tags)) {
                foreach($item->tags as $value) {
                    $tmps = explode(":",$value);

                    if(count($tmps) == 2) {
                        //	Log::info("value===".$tmps[1]);
                        if($tmps[0] == "instance" && !empty($tmps[1])){
                            $value = $this->setTgv($tmps[1]);
                            if($value){
                                $sub->tags->instance = $value;
                            }
                        }
                    }
                }
            }
            array_push($arrPost, $sub);

            $arrPost = $this->checkarrPost($arrPost);
            //$this->setSeriseTag($item);
        }
        return $arrPost;
    }

    public function checktime($key,$time=null)
    {
        $minutes = 5;
        if(!is_null($time)) $minutes = $time;
        if (Cache::has($key)) {
            $r_time = Cache::get($key);
            if(time() - $r_time > $minutes*60){
                Cache::forget($key);
                Cache::put($key,time(),$minutes);
                return true;
            }
            return false;
        }else{
            Cache::put($key,time(),$minutes);
            return true;
        }

    }

    /**
     * snmp 指标
     */
    public function snmpMetric($arrPost)
    {
        $cpuIdle = 0;
        $diskutilization = 0;
        $disk_total = 0;
        $iowait = null;
        $load15 = null;
        foreach($this->metrics_in as $item) {
            $sub = new \stdClass();
            $sub->metric = $item->metric;
            $sub->timestamp = !empty($item->timestamp) ? $item->timestamp : time();
            $sub->value = $item->value;
            $sub->tags = new \stdClass();//$metric[3];
            $sub->tags->host = $this->host;
            $sub->tags->uid = $this->uid;
            $num = 2;
            if (isset($item->device_name) && !empty($item->device_name)) {
                $value = $this->setTgv($item->device_name);
                if($value){
                    $sub->tags->device = $value;
                    $num++;
                }
            }
            if(isset($item->tags)) {
                foreach($item->tags as $value) {
                    if(!is_string($value)) continue;
                    $tmps = explode(":",$value);
                    if(count($tmps) == 2 && $num < 6 && !empty($tmps[0]) && !empty($tmps[1])) {
                        $tgk = $tmps[0];
                        $value = $this->setTgv($tmps[1]);
                        if($value){
                            $sub->tags->$tgk = $value;
                            $num++;
                        }
                    }
                }
            }
            $redis_data = new static();
            if($item->metric == 'system.disk.total') $redis_data->disk_total = $item->value;
            if($item->metric == 'system.disk.in_use') $redis_data->diskutilization = $item->value * 100;
            if($item->metric == 'system.cpu.idle') $redis_data->cpuIdle = $item->value;
            if($item->metric == 'system.load.15') $redis_data->load15 = $item->value;
            if($item->metric == 'system.cpu.iowait') $redis_data->iowait = $item->value;
            MyApi::recevieDataPutRedis($this->host,$this->uid,$redis_data);
            array_push($arrPost, $sub);

            $arrPost = $this->checkarrPost($arrPost);
        }
        return $arrPost;
    }

    /**
     * vcenter 指标
     */
    public function vcenterMetric($arrPost)
    {
        $redis_data = [];
        $g_metric_service = [];
        $g_metric_check = [];

        foreach($this->metrics_in as $item) {
            $host = null;
            $ptype = null;
            $uuid = null;

            $sub = new \stdClass();
            $sub->metric = $item->metric;
            $sub->timestamp = !empty($item->timestamp) ? $item->timestamp : time();
            $sub->value = $item->value;
            $sub->tags = new \stdClass();//$metric[3];
            //$sub->tags->host = $this->host;
            $sub->tags->uid = $this->uid;
            $num = 1;
            if(isset($item->tags)) {
                foreach($item->tags as $tgk => $tgv) {
                    if(empty($tgv) || empty($tgk)) continue;
                    if($tgk == 'HostSystem'){
                        $host = preg_replace("/\_/u",".",$tgv);
                        $ptype = $tgk;
                        $sub->tags->host = $host;
                        $num++;
                    }else if($tgk == 'VirtualMachine'){
                        $host = $tgv;
                        $ptype = $tgk;
                        $sub->tags->host = $host;
                        $num++;
                    }else{

                        if($num < 6) {
                            $value = $this->setTgv($tgv);
                            if($value){
                                $sub->tags->$tgk = $value;
                                $num++;
                            }
                        }
                        if($tgk == 'hostUUID' || $tgk == 'vmUUID') $uuid = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\_\-\/\xC2\xA0]/u","",$tgv);
                    }
                }
            }

            array_push($arrPost, $sub);

            $arrPost = $this->checkarrPost($arrPost);

            $redis_data = [];
            $redis_data['cpu_use'] = null;
            $redis_data['disk_used'] = null;
            $redis_data['disk_total'] = null;
            $redis_data['cpu_wait'] = null;
            $redis_data['load15'] = null;
            if($item->metric == 'cpu.usage.average')  $redis_data['cpu_use'] = $item->value;
            if($item->metric == 'disk.used.latest')  $redis_data['disk_used'] = $item->value;
            if($item->metric == 'disk.capacity.latest')  $redis_data['disk_total'] = $item->value;
            if($item->metric == 'cpu.wait.summation')  $redis_data['cpu_wait'] = $item->value;
            if($item->metric == 'rescpu.actav15.latest')  $redis_data['load15'] = $item->value;

            $cpu_use = $redis_data['cpu_use'];
            $diskutilization = $redis_data['disk_total'] && $redis_data['disk_total'] != 0 ?
                                    ($redis_data['disk_used']/$redis_data['disk_total']) * 100 : null;
            $disk_total = $redis_data['disk_total'];
            $iowait = $redis_data['cpu_wait'];
            $load15 = $redis_data['load15'];
            if($cpu_use || $diskutilization || $disk_total || $iowait || $load15){
                //Log::info("vcenter_redis host = {$host} -- cpu_use = {$cpu_use} -- diskutilization = {$diskutilization} -- disk_total = {$disk_total} -- iowait = {$iowait} -- load15 = {$load15}");
                Vcenter::recevieDataPutRedis($host,$this->uid,$ptype,$uuid,$cpu_use,$diskutilization,$disk_total,$iowait,$load15);
            }


            if(!isset($g_metric_service[$host])) $g_metric_service[$host] = [];
            if(!isset($g_metric_check[$host])) $g_metric_check[$host] = [];
            $m_service = explode('.',$item->metric);
            if(!in_array($m_service[0],$g_metric_service[$host])){
                $std = new \stdClass();
                $std->status = 0;
                $std->tags = ["check:".$m_service[0]];
                $std->timestamp = time();
                $std->check = "datadog.agent.check_status";
                $std->message = null;
                array_push($g_metric_check[$host],$std);
                array_push($g_metric_service[$host],$m_service[0]);
            }
        }

        foreach ($g_metric_check as $host => $check){
            $hostid = md5(md5($this->uid).md5($host));

            DB::table('metric_host')->where('hostid',$hostid)->delete();
            DB::table('metric_host')->insert(['hostid'=>$hostid,'service_checks'=>json_encode($check)]);
        }

        return $arrPost;
    }


    public function kvmMetric($arrPost)
    {
        $g_metric_service = [];
        $g_metric_check = [];
        foreach($this->metrics_in as $item) {
            $sub = new \stdClass();
            $met = explode(".",$item->metric);
            unset($met[0]);
            $metric_name = implode(".",$met);
            $sub->metric = $metric_name;
            $sub->timestamp = empty($item->timestamp) ? time() : $item->timestamp;
            $sub->value = $item->value;
            $sub->tags = new \stdClass();//$metric[3];
            $sub->tags->uid = $this->uid;
            $num = 1;
            if(isset($item->tags)) {
                foreach($item->tags as $tgk => $tgv) {
                    if($num < 6 && !empty($tgk) && !empty($tgv)) {
                        $value = $this->setTgv($tgv);
                        if($value){
                            $sub->tags->$tgk = $value;
                            $num++;

                            if($tgk == 'host'){
                                if(!isset($g_metric_service[$value])) $g_metric_service[$value] = [];
                                if(!isset($g_metric_check[$value])) $g_metric_check[$value] = [];
                                $m_service = explode('.',$metric_name);
                                if(!in_array($m_service[0],$g_metric_service[$value])){
                                    $std = new \stdClass();
                                    $std->status = 0;
                                    $std->tags = ["check:".$m_service[0]];
                                    $std->timestamp = time();
                                    $std->check = "datadog.agent.check_status";
                                    $std->message = null;
                                    array_push($g_metric_check[$value],$std);
                                    array_push($g_metric_service[$value],$m_service[0]);
                                }
                            }
                        }
                    }
                }
            }
            array_push($arrPost, $sub);

            $arrPost = $this->checkarrPost($arrPost);
        }

        foreach ($g_metric_check as $host => $check){
            $hostid = md5(md5($this->uid).md5($host));

            DB::table('metric_host')->where('hostid',$hostid)->delete();
            DB::table('metric_host')->insert(['hostid'=>$hostid,'service_checks'=>json_encode($check)]);
        }
        return $arrPost;
    }

}


