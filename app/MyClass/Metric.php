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
            $num ++;
            $sub->tags->device = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\_\-\/\xC2\xA0]/u","",$tag->device_name);//str_replace(array("{",":","*","}"), "_", $tag->device_name);//$tag->device_name;
        }

        if(isset($tag->tags)) {
            foreach($tag->tags as $value) {
                $tmps = explode(":",$value);
                if(count($tmps) == 2 && $num < 6 && !empty($tmps[0]) && !empty($tmps[1])){
                    $tgk = $tmps[0];
                    $sub->tags->$tgk = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\_\-\/\xC2\xA0]/u","",$tmps[1]);
                    $num ++;
                }
                /*if(count($tmps) == 2) {
                    //	Log::info("value===".$tmps[1]);
                    if($tmps[0] == "instance"){
                        $sub->tags->instance = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\_\-\/\xC2\xA0]/u","",$tmps[1]);
                    }
                }*/
            }
        }

        //$this->setTags($metric);
        return $sub;
    }

    /**
     * 已经不使用待丢弃
     */
    private function setTags($metric)
    {
        $sub = new \stdClass();
        $sub->metric = $metric[0];
        $sub->timestamp = $metric[1];
        $sub->value = $metric[2];
        $tag = $metric[3];
        $sub->tags = new \stdClass();//$metric[3];
        $sub->tags->host = $this->host;
        $sub->tags->uid = $this->uid;//1;//$metrics_in->uuid;
        if(isset($tag->device_name) && !empty($tag->device_name)) {
            $sub->tags->device = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\_\-\/\xC2\xA0]/u","",$tag->device_name);//str_replace(array("{",":","*","}"), "_", $tag->device_name);//$tag->device_name;
        }
        if(isset($tag->tags)) {
            foreach($tag->tags as $value) {
                $tmps = explode(":",$value);
                $key = $tmps[0];
                if(count($tmps) == 2) {
                    //	Log::info("value===".$tmps[1]);
                    //	Log::info("valuevalue===".str_replace(array("{","}"), "_", $tmps[1]));
                    $sub->tags->$key = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\_\-\/\xC2\xA0]/u","",$tmps[1]);//str_replace(array("{",":","*","}"), "_", $tmps[1]);
                } else {
                    $sub->tags->$key = "NULL";//$sub->tags->$tmps[0];
                }
            }
        }
        //array_push($this->tags,$sub);
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
                $sub->tags->device = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\_\-\/\xC2\xA0]/u", "", $item->device_name);
            }
            if(isset($item->tags)) {
                foreach($item->tags as $value) {
                    $tmps = explode(":",$value);

                    if(count($tmps) == 2) {
                        //	Log::info("value===".$tmps[1]);
                        if($tmps[0] == "instance"){
                            $sub->tags->instance = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\_\-\/\xC2\xA0]/u","",$tmps[1]);
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

    /**
     * 已经不使用待丢弃
     */
    private function setSeriseTag($item)
    {
        $sub = new \stdClass();
        $sub->metric = $item->metric;
        $sub->timestamp = $item->points[0][0];
        $sub->value = $item->points[0][1];
        $sub->tags = new \stdClass();//$metric[3];
        $sub->tags->host = $item->host;
        $sub->tags->uid = $this->uid;
        if(isset($item->device_name) && !empty($item->device_name)) {
            $sub->tags->device = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\_\-\/\xC2\xA0]/u","",$item->device_name);
        }
        if(isset($item->tags)) {
            foreach($item->tags as $value) {
                $tmps = explode(":",$value);
                $key = $tmps[0];
                if(count($tmps) == 2) {
                    //	Log::info("value===".$tmps[1]);
                    $sub->tags->$key = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\_\-\/\xC2\xA0]/u","",$tmps[1]);//str_replace(array("{",":","*","}"), "_", $tmps[1]);
                } else {
                    $sub->tags->$key = "NULL";//$sub->tags->$tmps[0];
                }
            }
        }
        //array_push($this->tags,$sub);
    }

    /**
     * 已经不使用待丢弃
     */
    public function setHostTag()
    {
        $host_tags = 'host-tags';
        $host_tag = $this->metrics_in->$host_tags;
        if(!isset($host_tag->system)) return;

        foreach($host_tag->system as $val){
            $res = explode(":",$val);
            $key = $res[0];
            $value = isset($res[1]) ? $res[1] : 'null';

            $sub = new \stdClass();
            $sub->$host_tags = 'host-tags';
            $sub->tags = new \stdClass();//$metric[3];
            $sub->tags->host = $this->host;
            $sub->tags->uid = $this->uid;
            $sub->tags->$host_tags = new \stdClass();
            $sub->tags->$host_tags->$key = $value;

            //array_push($this->tags,$sub);
        }
    }

    /**
     * 已经不使用待丢弃
     */
    public function getTags()
    {
        return $this->tags;
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
            $sub->timestamp = $item->timestamp;
            $sub->value = $item->value;
            $sub->tags = new \stdClass();//$metric[3];
            $sub->tags->host = $this->host;
            $sub->tags->uid = $this->uid;
            $num = 2;
            if (isset($item->device_name) && !empty($item->device_name)) {
                $sub->tags->device = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\_\-\/\xC2\xA0]/u", "", $item->device_name);
                $num++;
            }
            if(isset($item->tags)) {
                foreach($item->tags as $value) {
                    if(!is_string($value)) continue;
                    $tmps = explode(":",$value);
                    if(count($tmps) == 2 && $num < 6 && !empty($tmps[0]) && !empty($tmps[1])) {
                        $tgk = $tmps[0];
                        $sub->tags->$tgk = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\_\-\/\xC2\xA0]/u","",$tmps[1]);
                        $num++;
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
        foreach($this->metrics_in as $item) {
            $host = null;
            $ptype = null;
            $uuid = null;

            $sub = new \stdClass();
            $sub->metric = $item->metric;
            $sub->timestamp = $item->timestamp;
            $sub->value = $item->value;
            $sub->tags = new \stdClass();//$metric[3];
            //$sub->tags->host = $this->host;
            $sub->tags->uid = $this->uid;
            $num = 1;
            if(isset($item->tags)) {
                foreach($item->tags as $tgk => $tgv) {

                    if($tgk == 'HostSystem'){
                        $host = preg_replace("/\_/u",".",$tgv);
                        $ptype = $tgk;
                    }else if($tgk == 'VirtualMachine'){
                        $host = $tgv;
                        $ptype = $tgk;
                    }else{
                        if($num < 6 ) {
                            $sub->tags->$tgk = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\_\-\/\xC2\xA0]/u","",$tgv);
                            $num++;
                        }
                        if($tgk == 'hostUUID' || $tgk == 'vmUUID') $uuid = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\_\-\/\xC2\xA0]/u","",$tgv);
                    }
                }
            }
            if(!is_null($host)){
                $redis_data[$host] = [];
                $redis_data[$host]['host'] = $host;
                $redis_data[$host]['ptype'] = $ptype;
                $redis_data[$host]['uuid'] = $uuid;
                $redis_data[$host]['cpu_use'] = null;
                $redis_data[$host]['disk_used'] = null;
                $redis_data[$host]['disk_total'] = null;
                $redis_data[$host]['cpu_wait'] = null;
                $redis_data[$host]['cpu_used'] = null;
                $redis_data[$host]['load15'] = null;
                if($item->metric == 'cpu.usage.average')  $redis_data[$host]['cpu_use'] = $item->value;
                if($item->metric == 'disk.used.latest')  $redis_data[$host]['disk_used'] = $item->value;
                if($item->metric == 'disk.capacity.latest')  $redis_data[$host]['disk_total'] = $item->value;
                if($item->metric == 'cpu.wait.summation')  $redis_data[$host]['cpu_wait'] = $item->value;
                if($item->metric == 'cpu.used.summation')  $redis_data[$host]['cpu_used'] = $item->value;
                if($item->metric == 'rescpu.actav15.latest')  $redis_data[$host]['load15'] = $item->value;
            }
            if(!empty($redis_data)){
                foreach ($redis_data as $data){
                    $host = $data['host'];
                    $ptype = $data['ptype'];
                    $uuid = $data['uuid'];
                    $cpu_use = $data['cpu_use'];
                    $diskutilization = $data['disk_total'] != 0 ? ($data['disk_used']/$data['disk_total']) * 100 : 0;
                    $disk_total = $data['disk_total'];
                    $iowait = $data['cpu_used'] != 0 ? ($data['cpu_wait']/$data['cpu_used']) * 100 : 0;
                    $load15 = $data['load15'];
                    Vcenter::recevieDataPutRedis($host,$this->uid,$ptype,$uuid,$cpu_use,$diskutilization,$disk_total,$iowait,$load15);
                }
            }
            array_push($arrPost, $sub);

            $arrPost = $this->checkarrPost($arrPost);
        }

        return $arrPost;
    }

}


