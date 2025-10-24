<?php

namespace Pay\Controller;

use Pay\Service\RedisQueueService;

/**
 * 支付宝 WAP 支付控制器（浮动金额版本）
 * 继承原控制器，新增浮动金额生成和异步轮询功能
 */
class ZFBWAPFloatController extends PayController
{
    protected $at;
    private $queueService;
    
    public function __construct()
    {
        parent::__construct();
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
        
        // ========== 生成浮动金额 ==========
        $floatMoney = $this->generateFloatMoney($originalMoney, $orderid);
        
        $this->writeLogs("订单 {$orderid}: 原始金额 {$originalMoney}, 浮动金额 {$floatMoney}");
        
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
            'float_money' => $floatMoney,
        );
        
        $return = $this->orderadd($parameter); // 生成系统订单
        
        // ========== 保存订单映射 ==========
        $this->saveOrderMapping([
            'order_id' => $orderid,
            'original_money' => $originalMoney,
            'float_money' => $floatMoney,
            'merchant_num' => $return['mch_id'],
            'create_time' => date('Y-m-d H:i:s'),
            'status' => 'pending'
        ]);
        
        // ========== 推送到 Redis 队列 ==========
        $this->pushToQueue($orderid, $floatMoney, $return['mch_id']);
        
        // ========== 生成支付链接 ==========
        $wrapperUrl = 'http://alipay.020leader.com/index.php?g=Wap&m=CashierPayfreeApi&a=pay&merchant_num=' . $return['mch_id'] . '&money=' . $floatMoney;
        $encodedUrl = urlencode($wrapperUrl);
        $alipayScheme = "alipays://platformapi/startapp?appId=20000067&url=" . $encodedUrl;
        $info['pay_url'] = $alipayScheme;
        $info['order_sn'] = $orderid;
        $result = json_encode(['status' => 'success', 'msg' => '创建成功', 'data' => $info]);
        echo $result;
        exit;
    }
    
    /**
     * 生成浮动金额
     * @param float $originalMoney 原始金额
     * @param string $orderid 订单号
     * @return float 浮动金额
     */
    private function generateFloatMoney($originalMoney, $orderid)
    {
        // 方案1：基于订单号生成固定偏移
        $offset = (intval(substr($orderid, -4)) % 99 + 1) / 100;
        
        // 方案2：使用时间戳的毫秒部分
        // $offset = (intval(microtime(true) * 100) % 99 + 1) / 100;
        
        // 方案3：随机生成（推荐用于测试）
        // $offset = mt_rand(1, 99) / 100;
        
        $floatMoney = $originalMoney + $offset;
        
        // 确保格式化为两位小数
        return sprintf('%.2f', $floatMoney);
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
    private function pushToQueue($orderid, $floatMoney, $merchantNum)
    {
        $task = [
            'order_id' => $orderid,
            'float_money' => $floatMoney,
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
