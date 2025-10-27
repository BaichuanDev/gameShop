<?php
namespace Pay\Service;

use Pay\Service\RedisQueueService;
/**
 * 订单匹配服务类
 * 根据浮动金额匹配第三方订单，并更新数据库状态
 */
class OrderMatchService
{
    /**
     * 根据金额和时间匹配订单（只用这两个条件）
     * @param float $floatMoney 浮动金额
     * @param array $orderList 第三方订单列表
     * @param int $createTime 订单创建时间戳
     * @return array|null 匹配到的订单数据
     */
    public function matchByAmount($floatMoney, $orderList, $createTime)
    {
        if (empty($orderList)) {
            return null;
        }
        
        $this->log("开始匹配订单，浮动金额: {$floatMoney}，订单列表数量: " . count($orderList));
        
        foreach ($orderList as $order) {
            // 获取订单金额
            $orderMoney = isset($order['totalFee']) ? floatval($order['totalFee']) : 0;
            $orderTime = isset($order['transStart']) ? strtotime($order['transStart']) : 0;
            $orderStatus = isset($order['status']) ? $order['status'] : '';
            
            // 【条件1】金额匹配（精确到分）
            $moneyDiff = abs($orderMoney - $floatMoney);
            if ($moneyDiff < 0.01) {
                $this->log("金额匹配成功: {$orderMoney} ≈ {$floatMoney}");
                
                // 【条件2】时间匹配（3分钟内）
                $timeDiff = abs($orderTime - $createTime);
                if ($timeDiff <= 180) {
                    $this->log("时间匹配成功: 时间差 {$timeDiff} 秒");
                    
                    // 【条件3】状态检查（已支付）
                    if ($orderStatus == 2 || $orderStatus == '2') {
                        $this->log("状态匹配成功: {$orderStatus}");

                        $redisKey = "float_money:{$floatMoney}";
                        $redis = (new RedisQueueService())->getRedis();
                        $redis->del($redisKey);

                        return [
                            'matched' => true,
                            'third_order_no' => isset($order['orderNum']) ? $order['orderNum'] : '',
                            'trade_no' => isset($order['orderNumOfficial']) ? $order['orderNumOfficial'] : '',
                            'money' => $orderMoney,
                            'status' => $orderStatus,
                            'pay_time' => isset($order['transTime']) ? $order['transTime'] : $order['transStart'],
                        ];
                    } else {
                        $this->log("状态不匹配: {$orderStatus}（未支付）");
                    }
                } else {
                    $this->log("时间不匹配: 时间差 {$timeDiff} 秒（超过3分钟）");
                }
            }
        }
        
        $this->log("未找到匹配的订单");
        return null;
    }


    /**
     * 根据订单号匹配订单
     * @param float $floatMoney 浮动金额
     * @param array orderDetail 第三方订单列表
     * @param int $createTime 订单创建时间戳
     * @return array|null 匹配到的订单数据
     */
    public function matchByOrderNo($third_order_no, $orderDetail)
    {
        if (empty($orderDetail)) {
            return null;
        }
        $this->log("开始匹配订单，订单号:".$third_order_no);
        // 获取订单状态
        $orderStatus = isset($order['status']) ? $order['status'] : '';
        // 【条件】状态检查（已支付）
        if ($orderStatus == 2 || $orderStatus == '2') {
            $this->log("状态匹配成功: {$orderStatus}");
            return [
                'matched' => true,
                'third_order_no' => isset($order['orderNum']) ? $order['orderNum'] : '',
                'trade_no' => isset($order['orderNumOfficial']) ? $order['orderNumOfficial'] : '',
                'status' => $orderStatus,
                'pay_time' => isset($order['transTime']) ? $order['transTime'] : $order['transStart'],
            ];
        } else {
            $this->log("状态不匹配: {$orderStatus}（未支付）");
        }

        $this->log("未找到匹配的订单");
        return null;
    }

    /**
     * 更新订单状态并处理支付成功逻辑
     * @param string $orderId 本地订单号
     * @param array $thirdData 第三方订单数据
     * @return bool
     */
    public function updateOrderStatus($orderId, $thirdData)
    {
        try {
            // ========== 调用支付成功处理逻辑 ==========
            $this->processPaymentSuccess($orderId, $thirdData);

            return true;
        } catch (\Exception $e) {
            $this->log("订单状态更新失败: {$orderId}, 错误: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 处理支付成功后的逻辑（EditMoney）
     * @param $orderId
     * @return bool
     */
    public function processPaymentSuccess($orderId)
    {
        try {
            $this->log("开始处理支付成功逻辑: {$orderId}");
            // ========== 调用 EditMoney 方法 ==========
            $this->callEditMoney($orderId, '', 0);
            $this->log("支付成功处理完成: {$orderId}");
            return true;
        } catch (\Exception $e) {
            $this->log("支付成功处理异常: {$orderId}, 错误: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 调用 EditMoney 方法
     * @param string $orderId 订单号
     * @param string $payName 支付方式名称
     * @param int $returnType 返回类型
     */
    private function callEditMoney($orderId, $payName = '', $returnType = 0)
    {
        try {
            $this->log("调用 EditMoney: {$orderId}");
            
            // 实例化支付控制器
            $payController = new \Pay\Controller\ZFBWAPFloatController();
            
            // 通过反射调用 protected 方法
            $reflection = new \ReflectionClass($payController);
            $method = $reflection->getMethod('EditMoney');
            $method->setAccessible(true);
            $method->invoke($payController, $orderId, $payName, $returnType, '');
            
            $this->log("EditMoney 调用成功: {$orderId}");
            
        } catch (\Exception $e) {
            $this->log("EditMoney 调用失败: {$orderId}, 错误: " . $e->getMessage());
        }
    }
    

    

    
    /**
     * 记录日志
     * @param string $message 日志内容
     */
    private function log($message)
    {
        $logDir = RUNTIME_PATH . 'Logs/OrderMatch/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $content = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        file_put_contents($logDir . 'match_' . date('Y-m-d') . '.log', $content, FILE_APPEND | LOCK_EX);
    }
}
