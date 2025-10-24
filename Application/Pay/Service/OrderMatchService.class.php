<?php
namespace Pay\Service;

/**
 * 订单匹配服务类
 * 根据浮动金额匹配第三方订单，并更新数据库状态
 */
class OrderMatchService
{
    /**
     * 根据金额匹配订单
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
        
        foreach ($orderList as $order) {
            // 获取订单金额（根据实际字段调整）
            $orderMoney = isset($order['money']) ? floatval($order['money']) : 0;
            $orderTime = isset($order['create_time']) ? strtotime($order['create_time']) : 0;
            $orderStatus = isset($order['status']) ? $order['status'] : '';
            
            // 金额匹配（精确到分）
            $moneyDiff = abs($orderMoney - $floatMoney);
            if ($moneyDiff < 0.01) {
                // 时间匹配（10分钟内）
                $timeDiff = abs($orderTime - $createTime);
                if ($timeDiff <= 600) {
                    // 状态检查（已支付）
                    if (in_array($orderStatus, ['success', 'paid', '1', 1])) {
                        return [
                            'matched' => true,
                            'third_order_no' => isset($order['order_no']) ? $order['order_no'] : '',
                            'trade_no' => isset($order['trade_no']) ? $order['trade_no'] : '',
                            'money' => $orderMoney,
                            'status' => $orderStatus,
                            'pay_time' => isset($order['pay_time']) ? $order['pay_time'] : $order['create_time'],
                            'raw_data' => $order
                        ];
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * 更新订单状态
     * @param string $orderId 本地订单号
     * @param array $thirdData 第三方订单数据
     * @return bool
     */
    public function updateOrderStatus($orderId, $thirdData)
    {
        $db = \Think\Db::getInstance();
        
        // 构建更新数据
        $updateData = [
            'pay_status' => 1,
            'status' => 1,
            'pay_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s')
        ];
        
        // 添加第三方订单号
        if (isset($thirdData['third_order_no'])) {
            $updateData['third_order_no'] = $thirdData['third_order_no'];
        }
        if (isset($thirdData['trade_no'])) {
            $updateData['trade_no'] = $thirdData['trade_no'];
        }
        if (isset($thirdData['pay_time'])) {
            $updateData['pay_time'] = $thirdData['pay_time'];
        }
        
        // 构建 SET 子句
        $setClause = [];
        foreach ($updateData as $key => $value) {
            $setClause[] = "`{$key}` = '{$value}'";
        }
        $setStr = implode(', ', $setClause);
        
        // 执行更新（假设表名为 order）
        $sql = "UPDATE __PREFIX__order SET {$setStr} WHERE pay_orderid = '{$orderId}'";
        
        try {
            $result = $db->execute($sql);
            $this->log("订单状态更新成功: {$orderId}, 影响行数: {$result}");
            return $result > 0;
        } catch (\Exception $e) {
            $this->log("订单状态更新失败: {$orderId}, 错误: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 保存成功日志
     * @param string $orderId 订单号
     * @param array $data 订单数据
     */
    public function saveSuccessLog($orderId, $data)
    {
        $logDir = RUNTIME_PATH . 'Logs/OrderSuccess/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logData = array_merge([
            'order_id' => $orderId,
            'match_time' => date('Y-m-d H:i:s')
        ], $data);
        
        $content = json_encode($logData, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents(
            $logDir . 'success_' . date('Y-m-d') . '.json',
            $content,
            FILE_APPEND | LOCK_EX
        );
        
        $this->log("订单匹配成功: {$orderId}");
    }
    
    /**
     * 保存超时日志
     * @param string $orderId 订单号
     * @param array $taskData 任务数据
     */
    public function saveTimeoutLog($orderId, $taskData = [])
    {
        $logDir = RUNTIME_PATH . 'Logs/OrderTimeout/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logData = array_merge([
            'order_id' => $orderId,
            'timeout_time' => date('Y-m-d H:i:s')
        ], $taskData);
        
        $content = json_encode($logData, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents(
            $logDir . 'timeout_' . date('Y-m-d') . '.json',
            $content,
            FILE_APPEND | LOCK_EX
        );
        
        $this->log("订单轮询超时: {$orderId}");
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
