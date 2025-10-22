<?php
/**
 * Created by PhpStorm.
 * User: gaoxi
 * Date: 2017-05-18
 * Time: 11:33
 */
namespace Pay\Controller;

use Think\Log;

class AliwapController extends PayController
{
    public function __construct()
    {
        parent::__construct();
    }

    //支付
    public function Pay($array)
    {
        $orderid     = I("request.pay_orderid");
        $body        = '游戏礼包-'.$orderid;
        $notifyurl   = $this->_site . 'Pay_Aliwap_notifyurl.html'; //异步通知
        $callbackurl = $this->_site . 'Pay_Aliwap_callbackurl.html'; //返回通知

        $parameter = array(
            'code'         => 'Aliwap', // 通道名称
            'title'        => '支付宝H5',
            'exchange'     => 1, // 金额比例
            'gateway'      => '',
            'orderid'      => $orderid,
            'out_trade_id' => $orderid,
            'body'         => $body,
            'channel'      => $array,
        );

        // 订单号，可以为空，如果为空，由系统统一的生成
        $return = $this->orderadd($parameter);
        $return['subject'] = $body;

        //---------------------引入支付宝第三方类-----------------
        vendor('Alipay.aop.AopClient');
        vendor('Alipay.aop.SignData');
        vendor('Alipay.aop.request.AlipayTradeWapPayRequest');
        //组装系统参数
        $data = [
            'out_trade_no' => $return['orderid'],
            'total_amount' => $return['amount'],
            'subject'      => $return['subject'],
            'product_code' => "QUICK_WAP_WAY",
        ];

        $sysParams               = json_encode($data, JSON_UNESCAPED_UNICODE);
        $aop                     = new \AopClient();
        $aop->gatewayUrl         = "https://openapi.alipay.com/gateway.do";
        $aop->appId              = $return['appid'];
        $aop->rsaPrivateKey      = $return['appsecret'];
        $aop->alipayrsaPublicKey = $return['signkey'];
        $aop->apiVersion         = '1.0';
        $aop->signType           = 'RSA2';
        $aop->postCharset        = 'UTF-8';
        $aop->format             = 'json';
        $aop->debugInfo          = false;
        $request                 = new \AlipayTradeWapPayRequest();
        $request->setBizContent($sysParams);
        $request->setNotifyUrl($notifyurl);
        $request->setReturnUrl($callbackurl);
        $pay_url = $aop->pageExecute($request,'get');
        $info['pay_url'] = $pay_url;
        $info['order_sn'] = $orderid;
        $result = json_encode(['status' => 'success', 'msg' => '创建成功', 'data' => $info]);
        echo $result;
        exit;
    }


    //同步通知
    public function callbackurl()
    {
        $Order      = M("Order");               
        $orderid=I('request.out_trade_no/s');  
        $pay_status = $Order->where(['pay_orderid' => $orderid])->getField("pay_status");
        if ($pay_status <> 0) {
            $this->EditMoney($_REQUEST["out_trade_no"], '', 1);
        } else {
            exit("交易成功！");
        }
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

    //异步通知
    public function notifyurl()
    {
        $response  = $_POST;
        $sign      = $response['sign'];
        $sign_type = $response['sign_type'];
        $outno=I('post.out_trade_no/s');
        $publiKey = getKey($outno); // 密钥
        vendor('Alipay.aop.AopClient');
        vendor('Alipay.aop.SignData');
        vendor('Alipay.aop.request.AlipayTradeWapPayRequest');
        $aop = new \AopClient();
        $aop->alipayrsaPublicKey = $publiKey;
        $result = $aop->rsaCheckV1($response, $publiKey, $sign_type);
        if ($result) {
            if ($response['trade_status'] == 'TRADE_SUCCESS' || $response['trade_status'] == 'TRADE_FINISHED') {
                // ============ 在这里调用风控检查 ============
                $riskCheck = $this->checkAccountPayRiskControl($response);
                if ($riskCheck !== true) {
                    // 风控不通过，已退款，直接返回success但不处理订单
                    exit("success");
                }
                // ==========================================
                //回调后逻辑处理
                $this->EditMoney($response['out_trade_no'], '', 0);
                // 分账控制器
                $data = [
                    'separate_orderid'=>$response['out_trade_no'],
                    'separate_trade_no'=>$response['trade_no'],
                ];
                R("Separate/index",[$data]);
                exit("success");
            }
        } else {
            exit('error:check sign Fail!');
        }

    }

    /**
     * 触发退款
     */
    private function triggerRefund($out_trade_no, $trade_no, $reason)
    {
        // 获取订单信息
        $order = M('Order')->where(['pay_orderid' => $out_trade_no])->find();
        if (!$order) {
            return false;
        }
        // 准备退款参数
        $channel_account = M('channel_account')->where(['id' => $order['account_id'],'channel_id'=>$order['channel_id']])->find();
        $return = [
            'orderid' => $trade_no,  // 支付宝交易号
            'amount' => $order['pay_amount'],
            'appid' => $channel_account['appid'],
            'appsecret' => $channel_account['appsecret'],
            'signkey' => $channel_account['signkey']
        ];

        // 调用退款
        $this->returnOrder($return);

        // 记录日志
        $this->writeLog("风控触发退款: 订单{$out_trade_no}, 原因: {$reason}");

        return false;
    }




    /**
     *   每个用户5分钟之类只能下 1单
     *   每个用户1天只能下10单
     *   设置用户黑名单
     *   设置风控限制开关
     *   buyer_open_id 支付人唯一id
     *   所有的限制都是用户支付成功后判断如果达到限制条件就直接进行退款，不做后面处理，订单在自己系统显示未支付，
     * @param $response
     * @return bool
     */
    public function checkAccountPayRiskControl($response)
    {
        $buyer_open_id = $response['buyer_open_id'];
        $out_trade_no  = $response['out_trade_no'];
        $trade_no      = $response['trade_no'];

        // 1. 获取风控配置
        $config = M('BuyerRiskcontrolConfig')->find(1);

        // 风控开关未开启，直接返回true
        if (!$config || $config['status'] != 1) {
            return true;
        }

        // 2. 检查黑名单
        $isBlack = M('BuyerBlacklist')->where(['buyer_open_id' => $buyer_open_id])->find();
        if ($isBlack) {
            $this->writeLog("支付人在黑名单中: {$buyer_open_id}");
            return $this->triggerRefund($out_trade_no, $trade_no, '支付人在黑名单');
        }

        // 3. 检查单位时间内订单数（5分钟内）
        $unit_time_ago = time() - $config['unit_time'];
        $unit_count = M('Order')->where([
            'buyer_open_id' => $buyer_open_id,
            'pay_status' => ['in', [1, 2]],
            'pay_successdate' => ['gt', $unit_time_ago]
        ])->count();

        if ($unit_count >= $config['unit_max_orders']) {
            $this->writeLog("支付人{$buyer_open_id}在{$config['unit_time']}秒内已有{$unit_count}笔订单");
            return $this->triggerRefund($out_trade_no, $trade_no, '单位时间订单超限');
        }

        // 4. 检查当天订单数
        $today_start = strtotime(date('Y-m-d 00:00:00'));
        $day_count = M('Order')->where([
            'buyer_open_id' => $buyer_open_id,
            'pay_status' => ['in', [1, 2]],
            'pay_successdate' => ['gt', $today_start]
        ])->count();

        if ($day_count >= $config['day_max_orders']) {
            $this->writeLog("支付人{$buyer_open_id}今日已有{$day_count}笔订单");
            return $this->triggerRefund($out_trade_no, $trade_no, '当天订单超限');
        }

        // 5. 更新订单的buyer_open_id
        M('Order')->where(['pay_orderid' => $out_trade_no])->save([
            'buyer_open_id' => $buyer_open_id
        ]);

        return true;
    }




    public function returnOrder($return)
    {
        try{
            vendor('Alipay.aop.AopClient');
            vendor('Alipay.aop.SignData');
            vendor('Alipay.aop.request.AlipayTradeRefundRequest');
            $refundRequest  = new \AlipayTradeRefundRequest();
            //组装系统参数
            $data = [
                'trade_no' => $return['orderid'],
                'refund_amount' => $return['amount'],
                'refund_reason' => '正常退款'
            ];
            $sysParams = json_encode($data, JSON_UNESCAPED_UNICODE);
            $aop                     = new \AopClient();
            $aop->gatewayUrl         = "https://openapi.alipay.com/gateway.do";
            $aop->appId              = $return['appid'];
            $aop->rsaPrivateKey      = $return['appsecret'];
            $aop->alipayrsaPublicKey = $return['signkey'];
            $aop->apiVersion         = '1.0';
            $aop->signType           = 'RSA2';
            $aop->postCharset        = 'UTF-8';
            $aop->format             = 'json';
            $refundRequest->setBizContent($sysParams);
            $responseResult = $aop->execute($refundRequest);
            // 解析响应
            $responseNode = str_replace(".", "_", $refundRequest->getApiMethodName()) . "_response";
            $resultCode = $responseResult->$responseNode->code;
            if($resultCode == 10000){
                // 退款成功
                $this->writeLog("退款成功: 订单{$return['orderid']}, 金额{$return['amount']}");
                return true;
            }else{
                // 退款失败
                $msg = isset($responseResult->$responseNode->msg) ? $responseResult->$responseNode->msg : '未知错误';
                $subMsg = isset($responseResult->$responseNode->sub_msg) ? $responseResult->$responseNode->sub_msg : '';
                $this->writeLog("退款失败: 订单{$return['orderid']}, code:{$resultCode}, msg:{$msg}, sub_msg:{$subMsg}");
                return false;
            }
        }catch (\Exception $e){
            $this->writeLog("退款异常: 订单{$return['orderid']}, 错误:" . $e->getMessage());
            return false;
        }
    }

}
