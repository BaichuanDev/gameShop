<?php
namespace Pay\Daemon;

use Pay\Service\RedisQueueService;
use Pay\Service\ThirdPartyOrderService;
use Pay\Service\OrderMatchService;

/**
 * 订单轮询守护进程
 * 从 Redis 队列获取任务，执行3分钟轮询，匹配第三方订单
 */
class OrderPollDaemon
{
    private $queueService;
    private $thirdPartyService;
    private $matchService;
    private $running = true;
    private $queueName = 'order_poll_queue';
    
    public function __construct()
    {
        $this->queueService = new RedisQueueService();
        $this->thirdPartyService = new ThirdPartyOrderService();
        $this->matchService = new OrderMatchService();
        
        // 注册信号处理（优雅退出）
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'signalHandler']);
            pcntl_signal(SIGINT, [$this, 'signalHandler']);
        }
    }
    
    /**
     * 运行守护进程
     */
    public function run()
    {
        $this->log('========================================');
        $this->log('订单轮询守护进程启动');
        $this->log('进程 PID: ' . getmypid());
        $this->log('队列名称: ' . $this->queueName);
        $this->log('========================================');
        
        $idleCount = 0;
        
        while ($this->running) {
            try {
                // 从队列取出任务（阻塞5秒）
                $task = $this->queueService->pop($this->queueName, 5);
                
                if ($task) {
                    $idleCount = 0;
                    $this->log("接收到新任务: " . json_encode($task, JSON_UNESCAPED_UNICODE));
                    $this->processTask($task);
                } else {
                    $idleCount++;
                    if ($idleCount % 12 == 0) { // 每1分钟打印一次
                        $queueLen = $this->queueService->getQueueLength($this->queueName);
                        $this->log("等待任务中... (队列长度: {$queueLen})");
                    }
                }
                
                // 处理信号
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                
            } catch (\Exception $e) {
                $this->log("异常: " . $e->getMessage());
                $this->log("堆栈: " . $e->getTraceAsString());
                sleep(1);
            }
        }
        
        $this->log('守护进程已停止');
    }
    
    /**
     * 处理单个任务
     * @param array $task 任务数据
     */
    private function processTask($task)
    {
        $orderId = isset($task['order_id']) ? $task['order_id'] : '';
        $third_order_no = isset($task['third_order_no']) ? $task['third_order_no'] : '';
        $type = isset($task['type']) ? $task['type'] : '';
        $floatMoney = isset($task['float_money']) ? $task['float_money'] : 0;
        $merchantNum = isset($task['merchant_num']) ? $task['merchant_num'] : '';
        $expireTime = isset($task['expire_time']) ? $task['expire_time'] : (time() + 180);
        $createTime = isset($task['create_time']) ? $task['create_time'] : time();
        
        if (!$orderId || !$floatMoney || !$merchantNum) {
            $this->log("任务数据不完整，跳过: " . json_encode($task, JSON_UNESCAPED_UNICODE));
            return;
        }
        
        $this->log("开始处理订单: {$orderId}, 金额: {$floatMoney}, 商户号: {$merchantNum}");
        
        $attempts = 0;
        $maxAttempts = 36; // 3分钟 / 5秒 = 36次
        $pollInterval = 5; // 每5秒查询一次
        
        while ($attempts < $maxAttempts && time() < $expireTime) {
            $attempts++;
            
            // 更新轮询次数到数据库
            M('OrderFloatMapping')->where(['order_id' => $orderId])->save([
                'poll_count' => $attempts,
                'update_time' => date('Y-m-d H:i:s')
            ]);
            
            $this->log("[{$orderId}] 第 {$attempts}/{$maxAttempts} 次查询");
            if($type == 1){
                // 查询第三方订单
                $orderList = $this->thirdPartyService->getOrderList($merchantNum);

                if (!empty($orderList)) {
                    $this->log("[{$orderId}] 获取到 " . count($orderList) . " 条订单");

                    // 匹配订单
                    $matchResult = $this->matchService->matchByAmount($floatMoney, $orderList, $createTime);

                    if ($matchResult) {
                        $this->log("[{$orderId}] ✅ 匹配成功！");
                        $this->handleSuccess($orderId, $matchResult, $task);
                        return; // 完成任务
                    }
                } else {
                    $this->log("[{$orderId}] 第三方订单列表为空");
                }
            }else{
                // 查询第三方订单
                $orderDetail = $this->thirdPartyService->getOrderDetail($merchantNum,$third_order_no);
                if (!empty($orderDetail)) {
                    $this->log("[{$orderId}] 获取订单详情成功!订单".$third_order_no.'状态:'.$orderDetail['status']);
                    if($orderDetail['status'] == 2 || $orderDetail['status'] == '2'){
                        $this->log("状态匹配成功: {$orderDetail['status'] }");
                        $matchResult = [
                            'matched' => true,
                            'third_order_no' => isset($orderDetail['orderNum']) ? $orderDetail['orderNum'] : '',
                            'trade_no' => isset($orderDetail['orderNumOfficial']) ? $orderDetail['orderNumOfficial'] : '',
                            'money' => $orderDetail['totalFee'],
                            'status' => $orderDetail['status'],
                            'pay_time' => isset($orderDetail['transTime']) ? $orderDetail['transTime'] : $orderDetail['transStart'],
                        ];
                        $this->handleSuccess($orderId, $matchResult, $task);
                        return; // 完成任务
                    }

                } else {
                    $this->log("[{$orderId}] 第三方订单列表为空");
                }
            }

            // 检查是否需要继续
            if ($attempts >= $maxAttempts) {
                $this->log("[{$orderId}] 达到最大尝试次数");
                break;
            }
            
            if (time() >= $expireTime) {
                $this->log("[{$orderId}] 任务已过期");
                break;
            }
            
            // 等待下一次查询
            sleep($pollInterval);
        }
        
        // 超时处理
        $this->log("[{$orderId}] ⏰ 轮询超时，未找到匹配订单");
        $this->handleTimeout($orderId, $task);
    }
    
    /**
     * 处理成功
     * @param string $orderId 订单号
     * @param array $matchResult 匹配结果
     * @param array $taskData 任务数据
     */
    private function handleSuccess($orderId, $matchResult, $taskData)
    {
        // 更新数据库状态
        $updated = $this->matchService->updateOrderStatus($orderId, $matchResult);
        
        if ($updated) {
            $this->log("[{$orderId}] 订单状态更新成功");
        } else {
            $this->log("[{$orderId}] ⚠️ 订单状态更新失败");
        }
        
        // 保存成功日志
        $logData = array_merge($taskData, $matchResult);
        $this->matchService->saveSuccessLog($orderId, $logData);
        
        $this->log("[{$orderId}] 任务处理完成");
    }
    
    /**
     * 处理超时
     * @param string $orderId 订单号
     * @param array $taskData 任务数据
     */
    private function handleTimeout($orderId, $taskData)
    {
        // 保存超时日志
        $this->matchService->saveTimeoutLog($orderId, $taskData);
        
        $this->log("[{$orderId}] 超时任务已记录");
    }
    
    /**
     * 信号处理器
     * @param int $signo 信号编号
     */
    public function signalHandler($signo)
    {
        $this->log("接收到信号: {$signo}，准备退出");
        $this->running = false;
    }
    
    /**
     * 记录日志
     * @param string $message 日志内容
     */
    private function log($message)
    {
        $logDir = RUNTIME_PATH . 'Logs/Daemon/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $content = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        
        // 写入文件
        file_put_contents(
            $logDir . 'poll_' . date('Y-m-d') . '.log',
            $content,
            FILE_APPEND | LOCK_EX
        );
        
        // 输出到控制台
        echo $content;
    }
    
    /**
     * 获取队列状态信息
     * @return array
     */
    public function getStatus()
    {
        return [
            'running' => $this->running,
            'pid' => getmypid(),
            'queue_length' => $this->queueService->getQueueLength($this->queueName),
            'queue_name' => $this->queueName
        ];
    }
}
