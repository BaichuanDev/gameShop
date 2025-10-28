<?php
namespace Pay\Controller;

use Pay\Service\RedisQueueService;

/**
 * 支付宝 WAP 支付控制器（浮动金额版本）
 * 继承原控制器，新增订单号和异步轮询功能
 */
class ZFBWAPNoController extends PayController
{
    protected $at;
    private $queueService;

    private $generateKey;
    
    public function __construct()
    {
        parent::__construct();
        $this->at = C('ZFB'); // 获取支付宝的数组数据
        $this->queueService = new RedisQueueService();
        $this->generateKey = ''; //初始化
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
        $response = $this->culRequest($originalMoney,$return['mch_id']);
        if($response['code'] == 'SUCCESS'){

            $alipayScheme = "alipays://platformapi/startapp?appId=2021005130643619&pages/remotepayment/gater_remotepayment?share_openid=2088970224809018&paytoken=".$this->generateKey."&money=".$originalMoney."&remarks=&share_userifo=?%5CavatarUrl%5C=%5C%5C&%5CnickName%5C=null&%5Cavatar%5C=null&expire_time=1761633604477&merchant_num=000000000300808&enbsv=0.2.2504171609.27&chInfo=ch_share__chsub_qrcode&fxzjshareChinfo=ch_share__chsub_qrcode&shareTimestamp=1761631804790&apshareid=96a3dcff-1aaf-4b58-bc55-8f998c1bf088&shareBizType=H5App_XCX";

            // ========== 保存订单映射 ==========
//            $Order = M("Order");
//            $Order->where(['pay_orderid'=>$orderid])->save(['third_order_no'=>$orderNum]);
            // ========== 推送到 Redis 队列 ==========
            //$this->pushToQueue($orderid, $originalMoney, $return['mch_id'],$orderNum);
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
     * 推送任务到 Redis 队列
     * @param string $orderid 订单号
     * @param float $floatMoney 浮动金额
     * @param string $merchantNum 商户号
     */
    private function pushToQueue($orderid, $floatMoney, $merchantNum,$orderNum)
    {
        $task = [
            'order_id' => $orderid,
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

    public function culRequest($money,$merchantNum)
    {
        $this->generateKey();
        $centAmount = intval($money * 100);
        $url = 'http://b.020leader.com/business-platform/payment/remote-collection/save-order-data';
        $params = [
            'pay_client' => 2,
            'request_id' => $this->generateKey,
            'merchant_num' => $merchantNum,
            'share_id' => "2088970224809018",
            'share_nickname' => null,
            'share_remark' => '',
            'pay_fee' => $centAmount,
            'cashier_num' => 'remotepayment',
        ];
        $header = [
            'User-Agent: ozilla/5.0 (Linux; Android 12; V2241HA Build/W528JS; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/126.0.6478.122 MYWeb/1.3.126.251011180536 UWS/3.22.2.9999 UCBS/3.22.2.9999_220000000000 Mobile Safari/537.36 NebulaSDK/1.8.100112 Nebula AlipayDefined(nt:WIFI,ws:480|0|3.0) AliApp(AP/10.7.90.8100) AlipayClient/10.7.90.8100 Language/zh-Hans isConcaveScreen/false Region/CNAriver/10.7.90.8100 ChannelId(26)',
            'Content-Type: application/json',
            'Host: b.020leader.com'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch,CURLOPT_POST,1);
        curl_setopt($ch,CURLOPT_POSTFIELDS,$params);
        curl_setopt($ch,CURLOPT_HTTPHEADER,$header);

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


    public function generateKey()
    {
        $getKeyUrl = 'https://b.020leader.com/business-platform/payment/gateway/order/generate-key?salt=2088970224809018';
        $getHeader = [
            'User-Agent: Mozilla/5.0 (Linux; Android 12; V2241HA Build/W528JS; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/126.0.6478.122 MYWeb/1.3.126.251011180536 UWS/3.22.2.9999 UCBS/3.22.2.9999_220000000000 Mobile Safari/537.36 NebulaSDK/1.8.100112 Nebula AlipayDefined(nt:WIFI,ws:480|0|3.0) AliApp(AP/10.7.90.8100) AlipayClient/10.7.90.8100 Language/zh-Hans isConcaveScreen/false Region/CNAriver/10.7.90.8100 ChannelId(26) DTN/2.0',
            'Host: b.020leader.com'
        ];


        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $getKeyUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch,CURLOPT_HTTPHEADER,$getHeader);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode != 200 || !$response) {
            $this->log("第三方接口获取key请求失败: HTTP {$httpCode}, Error: {$error}");
            return [];
        }
        $this->writeLogs("第三方获取key请求结果返回".$response);
        $data = json_decode($response, true);
        if($data['code'] == 'SUCCESS'){
            $this->generateKey = $data['data']['key'];
        }else{
            $this->generateKey = md5('2088970224809018');
        }
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
