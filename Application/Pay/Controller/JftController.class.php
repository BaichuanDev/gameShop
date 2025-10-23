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
        $this->writeLog('index------'.$response['money']);
        $this->assign('money', $response['money']);
        $this->display("WeiXin/jft");
    }


    public function With()
    {
        $response = $_GET;
        $money = isset($response['money']) ? $response['money'] : 1;

        // 先获取 trade_no
        $url = 'http://alipay.020leader.com/index.php?g=Wap&m=AlipayUserinfo&a=create&online=1';
        $params = [
            'payfreemoney' => $money,
            'cid' => '290329',
            'token' => 'vigpuk1760521609',
            'userId'=> '2088702376166934'
        ];
        $notifystr = "&";
        foreach ($params as $key => $val) {
            $notifystr = $notifystr . $key . "=" . $val . "&";
        }
        $notifystr = rtrim($notifystr, '&');
        $uri = $url.$notifystr;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $uri);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $contents = curl_exec($ch);
        curl_close($ch);

        $this->writeLog('获取trade_no: ' . $contents);

        $result = json_decode($contents, true);

        if($result['status'] == 1 && !empty($result['trade_no'])) {
            // 构造目标页面URL（jft.html），带上 trade_no 和自动支付标记
            $targetUrl = $this->_site . 'Pay_Jft_index?money=' . $money . '&trade_no=' . $result['trade_no'] . '&auto_pay=1';
            $encodedUrl = urlencode($targetUrl);

            // 使用支付宝打开这个页面，尝试多个 appId
            // 优先尝试 20000067（扫一扫/网页打开）
            $alipayUrl = "alipays://platformapi/startapp?appId=20000067&url=" . $encodedUrl;

            $this->writeLog('唤起支付宝: ' . $alipayUrl);

            $html = '<!DOCTYPE html>                                                                                                                                                                    
             <html>                                                                                                                                                                                      
             <head>                                                                                                                                                                                      
                 <meta charset="UTF-8">                                                                                                                                                                  
                 <meta name="viewport" content="width=device-width,initial-scale=1">                                                                                                                     
                 <title>正在打开支付宝...</title>                                                                                                                                                        
             </head>                                                                                                                                                                                     
             <body style="text-align:center;padding-top:50px;">                                                                                                                                          
                 <p>正在打开支付宝...</p>                                                                                                                                                                
                 <p style="font-size:12px;color:#999;">如果没有自动跳转，<a href="' . $alipayUrl . '" style="color:#1677ff;">点击这里</a></p>                                                            
                 <script>                                                                                                                                                                                
                     window.location.href = "' . $alipayUrl . '";                                                                                                                                        

                     // 3秒后检测                                                                                                                                                                        
                     setTimeout(function() {                                                                                                                                                             
                         var fallbackUrl = "alipays://platformapi/startapp?appId=68687715&url=' . $encodedUrl . '";                                                                                      
                         document.body.innerHTML += \'<p style="margin-top:20px;"><a href="\' + fallbackUrl + \'" style="color:#1677ff;">或者点击这里尝试另一种方式</a></p>\';                           
                     }, 3000);                                                                                                                                                                           
                 </script>                                                                                                                                                                               
             </body>                                                                                                                                                                                     
             </html>';

            echo $html;
        } else {
            echo '获取支付信息失败: ' . ($result['msg'] ?? '未知错误');
        }
        exit();
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
