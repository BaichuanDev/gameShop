<?php
/**
 * Created by PhpStorm.
 * User: gaoxi
 * Date: 2017-05-18
 * Time: 11:33
 */
namespace Pay\Controller;
use MoneyCheck;
require_once("redis_util.class.php");
require_once("authorize.class.php");
class JftController extends PayController
{
    public function __construct()
    {
        parent::__construct();

    }

    public function index()
    {
        $response = $_GET;
        $this->assign('money', $response['money']);
        $this->display("WeiXin/jft");
    }




    public function Wap()
    {
        $response  = $_GET;
        $url = 'http://alipay.020leader.com/index.php?g=Wap&m=AlipayUserinfo&a=create&online=1';
        $notifystr = "&";
        foreach ($response as $key => $val) {
            $notifystr = $notifystr . $key . "=" . $val . "&";
        }
        $notifystr = rtrim($notifystr, '&');
        $uri = $url.$notifystr;
        $this->writeLog('请求-------》'.$uri);
        $ch        = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_URL, $uri);
        $contents = curl_exec($ch);
        curl_close($ch);
        $this->writeLog('数据-------》'.$contents);
        echo $contents;
        exit;
    }


    public function writeLog($message, $maxFileSize = 1048576) {
        // 使用框架定义的 LOG_PATH 常量，确保路径正确
        $logDir = LOG_PATH . 'Pay/';  // 在 Logs 下创建 Pay 子目录

        // 使用和框架一致的日期格式 y_m_d
        $logFile = $logDir . 'pay_'.date('y_m_d') . '.log';

        // 确保目录存在
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $logContent = "[{$timestamp}] {$message}\n";

        // 文件大小检查（可选，因为每天自动创建新文件）
        if (file_exists($logFile) && filesize($logFile) > $maxFileSize) {
            rename($logFile, $logFile . '.' . date('H-i-s'));
        }

        file_put_contents($logFile, $logContent, FILE_APPEND | LOCK_EX);
    }
}
