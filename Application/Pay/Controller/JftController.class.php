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








    // 测试页面（在支付宝内显示）
    public function TestUserIdPage()
    {
        $this->writeLog('测试页面已加载');
        $this->display("WeiXin/test_userid");
    }

    public function TestGetUserId()
    {
        $this->writeLog('========== 测试页面：获取userId ==========');

        // 构造测试页面URL
        $testPageUrl = $this->_site . 'Pay_Jft_TestUserIdPage';
        $encodedUrl = urlencode($testPageUrl);

        // 唤起支付宝打开测试页面
        $alipayScheme = "alipays://platformapi/startapp?appId=20000067&url=" . $encodedUrl;

        $html = '<!DOCTYPE html>                                                                                                                                                                                  
     <html>                                                                                                                                                                                                        
     <head>                                                                                                                                                                                                        
         <meta charset="UTF-8">                                                                                                                                                                                    
         <meta name="viewport" content="width=device-width,initial-scale=1">                                                                                                                                       
         <title>测试获取 userId</title>                                                                                                                                                                            
         <style>                                                                                                                                                                                                   
             body {                                                                                                                                                                                                
                 display: flex;                                                                                                                                                                                    
                 justify-content: center;                                                                                                                                                                          
                 align-items: center;                                                                                                                                                                              
                 min-height: 100vh;                                                                                                                                                                                
                 background: #f5f5f5;                                                                                                                                                                              
                 margin: 0;                                                                                                                                                                                        
             }                                                                                                                                                                                                     
             .container {                                                                                                                                                                                          
                 text-align: center;                                                                                                                                                                               
                 background: white;                                                                                                                                                                                
                 padding: 40px;                                                                                                                                                                                    
                 border-radius: 10px;                                                                                                                                                                              
                 box-shadow: 0 2px 10px rgba(0,0,0,0.1);                                                                                                                                                           
             }                                                                                                                                                                                                     
             h2 { color: #333; margin-bottom: 20px; }                                                                                                                                                              
             .btn {                                                                                                                                                                                                
                 display: inline-block;                                                                                                                                                                            
                 padding: 15px 40px;                                                                                                                                                                               
                 background: #1678ff;                                                                                                                                                                              
                 color: white;                                                                                                                                                                                     
                 text-decoration: none;                                                                                                                                                                            
                 border-radius: 25px;                                                                                                                                                                              
                 font-size: 16px;                                                                                                                                                                                  
             }                                                                                                                                                                                                     
         </style>                                                                                                                                                                                                  
     </head>                                                                                                                                                                                                       
     <body>                                                                                                                                                                                                        
         <div class="container">                                                                                                                                                                                   
             <h2>测试获取支付宝 userId</h2>                                                                                                                                                                        
             <p style="color:#666;margin-bottom:20px;">点击按钮在支付宝中打开测试页面</p>                                                                                                                          
             <a href="' . $alipayScheme . '" class="btn">打开支付宝测试</a>                                                                                                                                        
         </div>                                                                                                                                                                                                    
         <script>                                                                                                                                                                                                  
         setTimeout(function() {                                                                                                                                                                                   
             window.location.href = "' . $alipayScheme . '";                                                                                                                                                       
         }, 1000);                                                                                                                                                                                                 
         </script>                                                                                                                                                                                                 
     </body>                                                                                                                                                                                                       
     </html>';

        echo $html;
        exit;
    }




}