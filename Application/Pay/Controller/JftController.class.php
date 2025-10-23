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
        $this->writeLog('index------' . $response['money']);
        $wrapperUrl = 'http://alipay.020leader.com/index.php?g=Wap&m=CashierPayfreeApi&a=pay&merchant_num=000000000300808&money=' . $response['money'];
        $encodedUrl = urlencode($wrapperUrl);

        $alipayScheme = "alipays://platformapi/startapp?appId=20000067&url=" . $encodedUrl;

        $this->assign('alipayScheme', $alipayScheme);
        $this->display("WeiXin/jft");
    }

    public function writeLog($message, $maxFileSize = 1048576)
    {
        $logDir = LOG_PATH . 'Pay/';  // 在 Logs 下创建 Pay 子目录
        $logFile = $logDir . 'pay_' . date('y_m_d') . '.log';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $timestamp = date('Y-m-d H:i:s');
        $logContent = "[{$timestamp}] {$message}\n";
        if (file_exists($logFile) && filesize($logFile) > $maxFileSize) {
            rename($logFile, $logFile . '.' . date('H-i-s'));
        }
        file_put_contents($logFile, $logContent, FILE_APPEND | LOCK_EX);
    }
}