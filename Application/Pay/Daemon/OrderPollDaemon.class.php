<?php
namespace Pay\Daemon;

use Pay\Service\RedisQueueService;
use Pay\Service\ThirdPartyOrderService;


/**
 * 订单轮询守护进程
 * 从 Redis 队列获取任务，执行5分钟轮询，匹配第三方订单
 */
class OrderPollDaemon
{
    private $queueService;
    private $thirdPartyService;

    private $running = true;
    private $queueName = 'order_poll_queue';
    
    public function __construct()
    {
        $this->queueService = new RedisQueueService();
        $this->thirdPartyService = new ThirdPartyOrderService();

        
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
        $floatMoney = isset($task['float_money']) ? $task['float_money'] : 0;
        $merchantNum = isset($task['merchant_num']) ? $task['merchant_num'] : '';
        $expireTime = isset($task['expire_time']) ? $task['expire_time'] : (time() + 180);
        if (!$orderId || !$floatMoney || !$merchantNum) {
            $this->log("任务数据不完整，跳过: " . json_encode($task, JSON_UNESCAPED_UNICODE));
            return;
        }
        $this->log("开始处理订单: {$orderId}, 金额: {$floatMoney}, 商户号: {$merchantNum}");

        // ========== 获取 Redis 锁 ==========
        $redis = $this->queueService->getRedis();
        $lockKey = "order_lock:{$orderId}";
        $lockValue = uniqid(getmypid() . '_', true);

        // 尝试加锁（NX=不存在才设置，EX=300秒过期）
        $locked = $redis->set($lockKey, $lockValue, ['NX', 'EX' => 300]);

        if (!$locked) {
            // 获取锁失败，其他进程正在处理
            $this->log("[{$orderId}] 其他进程正在处理，跳过");
            return;
        }

        $this->log("[{$orderId}] ✅ 成功获取处理锁");

        try {
            // ========== 检查订单是否已支付 ==========
            $Order = M("Order");
            $orderInfo = $Order->where(['pay_orderid' => $orderId])->find();
            if ($orderInfo && ($orderInfo['pay_status'] == 1 || $orderInfo['pay_status'] == 2)) {
                $this->log("[{$orderId}] 订单已支付，跳过处理");
                return; // 注意：finally 中会释放锁
            }
            $attempts = 0;
            $maxAttempts = 60; // 3分钟 / 3秒 = 100次
            $pollInterval = 3; // 每3秒查询一次

            while ($attempts < $maxAttempts && time() < $expireTime) {
                $attempts++;
                $this->log("[{$orderId}] 第 {$attempts}/{$maxAttempts} 次查询");
                // 查询第三方订单
                $orderDetail = $this->thirdPartyService->getOrderDetail($merchantNum,$third_order_no);
                if (!empty($orderDetail)) {
                    $this->log("[{$orderId}] 获取订单详情成功!订单".$third_order_no.'状态:'.$orderDetail['status']);
                    if($orderDetail['status'] == 2 || $orderDetail['status'] == '2'){
                        $this->log("[{$orderId}]状态匹配成功: {$orderDetail['status'] }");
                        $this->handleSuccess($orderId);
                        break; // 完成任务
                    }
                } else {
                    $this->log("[{$orderId}] 第三方订单列表为空");
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
                // ========== 检查锁是否还持有（建议添加）==========
                if (!$this->checkLockOwnership($redis, $lockKey, $lockValue)) {
                    $this->log("[{$orderId}] ⚠️ 锁已失效，停止处理");
                    break;
                }
                // 等待下一次查询
                sleep($pollInterval);
            }
            // 如果没有成功，记录超时
            if ($attempts >= $maxAttempts || time() >= $expireTime) {
                $this->log("[{$orderId}] ⏰ 轮询超时，未找到匹配订单");
                $this->handleTimeout($orderId);
            }
        } catch (\Exception $e) {
            $this->log("[{$orderId}] 处理异常: " . $e->getMessage());

        } finally {
            // 释放锁（使用 Lua 脚本）
            $this->releaseLock($redis, $lockKey, $lockValue);
        }


    }
    
    /**
     * 处理成功
     * @param string $orderId 订单号
     * @param array $matchResult 匹配结果
     * @param array $taskData 任务数据
     */
    private function handleSuccess($orderId)
    {
        // 更新数据库状态
        $updated = $this->processPaymentSuccess($orderId);
        if ($updated) {
            $this->log("[{$orderId}] 订单状态更新成功");
        } else {
            $this->log("[{$orderId}] ⚠️ 订单状态更新失败");
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
     * 处理超时
     * @param string $orderId 订单号
     * @param array $taskData 任务数据
     */
    private function handleTimeout($orderId)
    {

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
     * 释放 Redis 锁（Lua 脚本保证原子性）
     */
    private function releaseLock($redis, $lockKey, $lockValue)
    {
        $script = "                                                                                                                                                                                               
             if redis.call('get', KEYS[1]) == ARGV[1] then                                                                                                                                                         
                 return redis.call('del', KEYS[1])                                                                                                                                                                 
             else                                                                                                                                                                                                  
                 return 0                                                                                                                                                                                          
             end                                                                                                                                                                                                   
         ";

        $result = $redis->eval($script, [$lockKey, $lockValue], 1);

        if ($result == 1) {
            $orderId = str_replace('order_lock:', '', $lockKey);
            $this->log("[{$orderId}] 🔓 已释放处理锁");
        }
    }


    /**
     * 检查锁是否还由当前进程持有
     */
    private function checkLockOwnership($redis, $lockKey, $lockValue)
    {
        $currentValue = $redis->get($lockKey);
        return $currentValue === $lockValue;
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
