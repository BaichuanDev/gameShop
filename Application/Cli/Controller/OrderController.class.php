<?php

namespace Cli\Controller;

use \think\Controller;
use Think\Log;

/**
 * 订单轮询 CLI 控制器
 * 用于启动订单轮询守护进程
 */
class OrderController extends Controller
{
    /**
     * 启动订单轮询守护进程
     * 使用方式：php cli.php order/poll
     * 或：php cli.php order_poll
     */
    public function poll()
    {
        echo "========================================\n";
        echo "启动订单轮询守护进程\n";
        echo "时间: " . date('Y-m-d H:i:s') . "\n";
        echo "========================================\n";
        
        // 设置时区
        date_default_timezone_set('Asia/Shanghai');
        
        // 设置无限执行时间
        set_time_limit(0);
        ini_set('memory_limit', '256M');
        
        // 引入守护进程类
        $daemonClass = APP_PATH . 'Pay/Daemon/OrderPollDaemon.class.php';
        if (!file_exists($daemonClass)) {
            echo "错误: 找不到守护进程类文件: {$daemonClass}\n";
            Log::record("订单轮询守护进程启动失败: 找不到类文件", Log::ERR);
            return;
        }
        
        require_once $daemonClass;
        
        // 自动加载 Service 类
        spl_autoload_register(function($class) {
            if (strpos($class, 'Pay\\Service\\') === 0) {
                $classFile = APP_PATH . str_replace('\\', '/', $class) . '.class.php';
                if (file_exists($classFile)) {
                    require_once $classFile;
                }
            }
        });
        
        try {
            Log::record("订单轮询守护进程开始启动", Log::INFO);
            
            $daemon = new \Pay\Daemon\OrderPollDaemon();
            $daemon->run();
            
            Log::record("订单轮询守护进程已停止", Log::INFO);
            
        } catch (\Exception $e) {
            echo "守护进程异常: " . $e->getMessage() . "\n";
            echo "堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
            Log::record("订单轮询守护进程异常: " . $e->getMessage(), Log::ERR);
        }
    }
    
    /**
     * 查看队列状态
     * 使用方式：php cli.php order/status
     */
    public function status()
    {
        echo "========================================\n";
        echo "订单轮询队列状态\n";
        echo "时间: " . date('Y-m-d H:i:s') . "\n";
        echo "========================================\n";
        
        // 自动加载 Service 类
        spl_autoload_register(function($class) {
            if (strpos($class, 'Pay\\Service\\') === 0) {
                $classFile = APP_PATH . str_replace('\\', '/', $class) . '.class.php';
                if (file_exists($classFile)) {
                    require_once $classFile;
                }
            }
        });
        
        try {
            $queueService = new \Pay\Service\RedisQueueService();
            $queueName = 'order_poll_queue';
            
            $queueLength = $queueService->getQueueLength($queueName);
            echo "队列名称: {$queueName}\n";
            echo "队列长度: {$queueLength}\n";
            
            if ($queueLength > 0) {
                echo "\n前 5 个任务:\n";
                $tasks = $queueService->view($queueName, 0, 4);
                foreach ($tasks as $index => $task) {
                    echo "  [" . ($index + 1) . "] 订单号: {$task['order_id']}, ";
                    echo "金额: {$task['float_money']}, ";
                    echo "过期时间: " . date('Y-m-d H:i:s', $task['expire_time']) . "\n";
                }
            }
            
            echo "========================================\n";
            
        } catch (\Exception $e) {
            echo "查询状态失败: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * 清空队列
     * 使用方式：php cli.php order/clear
     */
    public function clear()
    {
        echo "========================================\n";
        echo "清空订单轮询队列\n";
        echo "时间: " . date('Y-m-d H:i:s') . "\n";
        echo "========================================\n";
        
        // 自动加载 Service 类
        spl_autoload_register(function($class) {
            if (strpos($class, 'Pay\\Service\\') === 0) {
                $classFile = APP_PATH . str_replace('\\', '/', $class) . '.class.php';
                if (file_exists($classFile)) {
                    require_once $classFile;
                }
            }
        });
        
        try {
            $queueService = new \Pay\Service\RedisQueueService();
            $queueName = 'order_poll_queue';
            
            $queueLength = $queueService->getQueueLength($queueName);
            echo "当前队列长度: {$queueLength}\n";
            
            if ($queueLength > 0) {
                echo "确认要清空队列吗？(y/n): ";
                $handle = fopen("php://stdin", "r");
                $line = fgets($handle);
                fclose($handle);
                
                if (trim($line) == 'y' || trim($line) == 'Y') {
                    $queueService->clear($queueName);
                    echo "队列已清空\n";
                    Log::record("订单轮询队列已被手动清空", Log::INFO);
                } else {
                    echo "操作已取消\n";
                }
            } else {
                echo "队列为空，无需清空\n";
            }
            
            echo "========================================\n";
            
        } catch (\Exception $e) {
            echo "清空队列失败: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * 测试任务处理
     * 使用方式：php cli.php order/test
     */
    public function test()
    {
        echo "========================================\n";
        echo "测试订单轮询任务\n";
        echo "时间: " . date('Y-m-d H:i:s') . "\n";
        echo "========================================\n";
        
        // 自动加载 Service 类
        spl_autoload_register(function($class) {
            if (strpos($class, 'Pay\\Service\\') === 0) {
                $classFile = APP_PATH . str_replace('\\', '/', $class) . '.class.php';
                if (file_exists($classFile)) {
                    require_once $classFile;
                }
            }
        });
        
        try {
            $queueService = new \Pay\Service\RedisQueueService();
            
            // 创建测试任务
            $testTask = [
                'order_id' => 'TEST' . time(),
                'float_money' => 100.88,
                'merchant_num' => 'TEST_MERCHANT',
                'create_time' => time(),
                'expire_time' => time() + 180,
            ];
            
            $queueService->push('order_poll_queue', $testTask);
            
            echo "测试任务已添加到队列:\n";
            echo json_encode($testTask, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            echo "\n请查看守护进程日志查看处理结果\n";
            echo "========================================\n";
            
        } catch (\Exception $e) {
            echo "添加测试任务失败: " . $e->getMessage() . "\n";
        }
    }
}
