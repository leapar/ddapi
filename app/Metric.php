<?php
/**
 * Created by PhpStorm.
 * User: admin
 * Date: 2016/11/3
 * Time: 13:29
 */

namespace App;
use Illuminate\Support\Facades\DB;
use Log;

class Metric
{
    private $metrics_in;
    private $host;
    private $custom_id;

    public function __construct($metrics_in,$host,$custom_id)
    {
        $this->metrics_in = $metrics_in;
        $this->host = $host;
        $this->custom_id = $custom_id;
    }

    public function post2tsdb($arrPost) {
        $headers = array('Content-Type: application/json','Content-Encoding: gzip',);
        $gziped_xml_content = gzencode(json_encode($arrPost));
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, 'http://172.29.225.222:4242/api/put?details'); //opentsdb服务器      //'http://172.29.231.123:4242/api/put');
        curl_setopt($ch, CURLOPT_TIMEOUT,120);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $gziped_xml_content);
        $res = curl_exec($ch);
        curl_close($ch);
        if($res == NULL) {
            Log::info("response ===opentsdb error");
        } else {
            $res = json_decode($res);

            if($res->failed > 0) {
                Log::info("post ===".json_encode($arrPost));
                Log::info("response ===".$res);
            }
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

    public function getTags($metric)
    {
        $sub = new \stdClass();
        $sub->metric = $metric[0];
        $sub->timestamp = $metric[1];
        $sub->value = $metric[2];
        $tag = $metric[3];
        $sub->tags = new \stdClass();//$metric[3];
        $sub->tags->host = $this->host;
        $sub->tags->uid = $this->custom_id;//1;//$metrics_in->uuid;
        if(isset($tag->device_name)) {
            $sub->tags->device = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\-\/]/u","",$tag->device_name);//str_replace(array("{",":","*","}"), "_", $tag->device_name);//$tag->device_name;
        }
        if(isset($tag->tags)) {
            foreach($tag->tags as $value) {
                $tmps = explode(":",$value);

                if(count($tmps) == 2) {
                    //	Log::info("value===".$tmps[1]);
                    //	Log::info("valuevalue===".str_replace(array("{","}"), "_", $tmps[1]));

                    $sub->tags->$tmps[0] = preg_replace("/[^\x{4e00}-\x{9fa5}A-Za-z0-9\.\-\/]/u","",$tmps[1]);//str_replace(array("{",":","*","}"), "_", $tmps[1]);
                } else {
                    $sub->tags->$tmps[0] = "NULL";//$sub->tags->$tmps[0];
                }
            }
        }

        return $sub;
    }

    public function getTagsByOS($arrPost)
    {
        $os = $this->metrics_in->os;
        switch($os){
            case "linux":
                $arrPost = $this->getLinuxMetric($arrPost);
                break;
            case "mac":
                $arrPost = $this->getMacMetric($arrPost);
                break;
        }

        //内存指标
        $arrPost = $this->getMemMetric($arrPost);
        //cpu指标
        $arrPost = $this->getCpuMetric($arrPost);

        return $arrPost;
    }

    private function getLinuxMetric($arrPost)
    {
        //io指标
        $arrPost = $this->getLinuxIOMetric($arrPost);

        return $arrPost;
    }

    private function getMacMetric($arrPost)
    {
        //io指标
        $arrPost = $this->getMacIOMetric($arrPost);

        return $arrPost;
    }

    //metric 数据
    private function createMetric($arr,$data,$arrPost,$device="")
    {
        foreach($arr as $key => $item){
            if(!empty($data->$item)){
                $sub = new \stdClass();
                $sub->metric = $key;
                $sub->timestamp = time();
                $sub->value = $data->$item;
                $sub->tags = new \stdClass();//$metric[3];
                $sub->tags->host = $this->host;
                $sub->tags->uid = $this->custom_id;//1;//$metrics_in->uuid;
                if($device) $sub->tags->device = $device;

                array_push( $arrPost ,$sub);
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

    public function saveHost($data)
    {
        $sql =<<<EOD
            insert into host (
                id,
                pname,
                status,
                ptype,
                cpu,
                iowait,
                gohai,
                load15,
                createtime,
                updatetime,
                colletcionstamp,
                processmetrics,
                diskutilization,
                disksize,
                uuid,
                is_delete
            ) values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
EOD;


        DB::insert($sql, $data);

    }

    public function saveMetricHost($data)
    {
        $sql = 'insert into metric_host (id,metricid,hostid) value (?,?,?)';
        DB::insert($sql, $data);
    }

    public function selectMetric()
    {

    }

}

