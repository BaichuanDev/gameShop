<?php


use Pay\Service\RedisQueueService;

/**
 * 支付宝 WAP 支付控制器（浮动金额版本）
 * 继承原控制器，新增订单号和异步轮询功能
 */
class ZFBWAPNoController extends \Pay\Controller\PayController
{
    protected $at;
    private $queueService;
    
    public function __construct()
    {
        \Pay\Controller\PayController::__construct();
        $this->at = C('ZFB'); // 获取支付宝的数组数据
        $this->queueService = new RedisQueueService();
    }

    /**
     * 支付主流程
     */
    public function pay($array)
    {
        $gateWay = $this->at['gatewayUrl']; // 获取网关
        $orderid = I("request.pay_orderid");
        $body = I('request.pay_productname');
        $originalMoney = I('request.pay_amount'); // 原始金额

        $parameter = array(
            'code' => "ZFBWAP",
            'title' => '支付宝WAP',
            'exchange' => 1,
            'gateway' => $gateWay,
            'orderid' => $orderid,
            'out_trade_id' => $orderid,
            'body' => $body,
            'channel' => $array,
            'original_money' => $originalMoney,
        );
        
        $return = $this->orderadd($parameter); // 生成系统订单
        

        

        
        // ========== 生成支付链接 ==========
        $response = $this->culRequest($originalMoney);
        if($response['code'] == 1){
            $encodedUrl = urlencode($response['url']);
            $alipayScheme = "alipays://platformapi/startapp?appId=20000067&url=" . $encodedUrl;
            $query = parse_url($response['failNotifyUrl'], PHP_URL_QUERY);
            $params = [];
            parse_str($query, $params);
            $orderNum = $params['orderNum'] ?? null;

            // ========== 保存订单映射 ==========
            $this->saveOrderMapping([
                'order_id' => $orderid,
                'original_money' => $originalMoney,
                'float_money' => $originalMoney,
                'merchant_num' => $return['mch_id'],
                'third_order_no' => $orderNum,
                'create_time' => date('Y-m-d H:i:s'),
                'status' => 'pending'
            ]);
            // ========== 推送到 Redis 队列 ==========
            $this->pushToQueue($orderid, $originalMoney, $return['mch_id'],$orderNum);

            $info['pay_url'] = $alipayScheme;
            $info['order_sn'] = $orderid;
            $result = json_encode(['status' => 'success', 'msg' => '创建成功', 'data' => $info]);
            echo $result;
            exit;
        }else{
            $this->showmessage('系统错误10005');
        }

    }
    



    /**
     * 保存订单映射信息到数据库
     * @param array $data 映射数据
     */
    private function saveOrderMapping($data)
    {
        $insertData = [
            'order_id' => $data['order_id'],
            'original_money' => $data['original_money'],
            'float_money' => $data['float_money'],
            'merchant_num' => $data['merchant_num'],
            'third_order_no' => $data['third_order_no'],
            'status' => 0, // 0-待匹配
            'poll_count' => 0,
            'create_time' => $data['create_time'],
        ];
        
        M('OrderFloatMapping')->add($insertData);
        
        $this->writeLog("订单映射已保存到数据库: {$data['order_id']}");
    }
    
    /**
     * 推送任务到 Redis 队列
     * @param string $orderid 订单号
     * @param float $floatMoney 浮动金额
     * @param string $merchantNum 商户号
     */
    private function pushToQueue($orderid, $floatMoney, $merchantNum,$orderNum)
    {
        $task = [
            'order_id' => $orderid,
            'type' => 2,
            'float_money' => $floatMoney,
            'third_order_no' => $orderNum,
            'merchant_num' => $merchantNum,
            'create_time' => time(),
            'expire_time' => time() + 180, // 3分钟后过期
        ];
        
        $queueLen = $this->queueService->push('order_poll_queue', $task);
        
        $this->writeLogs("订单 {$orderid} 已加入队列，当前队列长度: {$queueLen}");
    }
    
    /**
     * 写日志
     * @param string $message 日志内容
     */
    private function writeLogs($message)
    {
        $logDir = RUNTIME_PATH . 'Logs/Payment/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $content = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        file_put_contents($logDir . 'payment_' . date('Y-m-d') . '.log', $content, FILE_APPEND | LOCK_EX);
    }

    public function culRequest($money)
    {
        $url = 'http://alipay.020leader.com/wxpay/index.php';
        $params = [
            'g' => 'Wap',
            'm' => 'WeixinfreeBank',
            'a' => 'new_pay',
            'online' => 1,
            'version' => '3.0',
            'token' => 'vigpuk1760521609',
            'cid' => '290329',
            'payfreemoney' => $money,
        ];

        $fullUrl = $url . '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode != 200 || !$response) {
            $this->writeLogs("第三方接口请求失败: HTTP {$httpCode}, Error: {$error}");
            return [];
        }

        $this->writeLogs("第三方订单请求结果返回".$response);
        $data = json_decode($response, true);
        return $data;
    }




    /**
     * 异步通知（继承原方法）
     */
    public function notify()
    {
        $param = $_POST;
        Vendor("AlipaySdk.aop.AopClient");
        $aop = new \AopClient();
        $order_info = M("Order")->where(['pay_orderid' => $param['out_trade_no']])->field('key,account_id')->find();
        $account = M('ChannelAccount')->where(['id' => $order_info['account_id']])->field('fenzhuanzhang')->find();
        $aop->alipayrsaPublicKey = $order_info['key'];
        $verify = $aop->rsaCheckV1($param, null, $this->at['sign_type']);
        
        if ($verify) {
            if ($param['trade_status'] == "TRADE_SUCCESS") {
                $this->EditMoney($param['out_trade_no'], '', 0);
                
                if ($account['fenzhuanzhang'] == 1) {
                    $data = [
                        'separate_orderid' => $param['out_trade_no'],
                        'separate_trade_no' => $param['trade_no'],
                    ];
                    R("Separate/index", [$data]);
                } elseif ($account['fenzhuanzhang'] == 2) {
                    $data = [
                        'transfer_orderid' => $param['out_trade_no'],
                    ];
                    R("Transfer/index", [$data]);
                }
                
                echo 'success';
            }
        } else {
            echo 'fail';
        }
    }
}
