<?php
/**
 * 手动推送自定义任务到 Redis 队列
 * 
 * 使用方式：
 * php push_custom_task.php [订单号] [浮动金额] [商户号]
 * 
 * 示例：
 * php push_custom_task.php B20251025001234 15.24 000000000300808
 * 
 * 不提供参数则使用默认值
 */

// 引入框架
define('APP_PATH', './Application/');
define('THINK_PATH', './core/');
define('RUNTIME_PATH', './Runtime/');

require THINK_PATH . 'ThinkPHP.php';

echo "======================================\n";
echo "手动推送任务到 Redis 队列\n";
echo "======================================\n\n";

// 获取命令行参数
$orderId = isset($argv[1]) ? $argv[1] : 'B20251027042973802918';
$floatMoney = isset($argv[2]) ? floatval($argv[2]) : 42.00;
$merchantNum = isset($argv[3]) ? $argv[3] : '000000000300808';
$third_order_no = isset($argv[4]) ? $argv[4] : '202623080411';
// 连接 Redis
$redis = new Redis();
try {
    $redis->connect('127.0.0.1', 6379);
    echo "✅ 已连接到 Redis (127.0.0.1:6379)\n\n";
} catch (Exception $e) {
    echo "❌ Redis 连接失败: " . $e->getMessage() . "\n";
    exit(1);
}

// 显示任务参数
echo "任务参数：\n";
echo str_repeat('-', 50) . "\n";
printf("%-15s : %s\n", "订单号", $orderId);
printf("%-15s : %.2f 元\n", "浮动金额", $floatMoney);
printf("%-15s : %s\n", "商户号", $merchantNum);
printf("%-15s : %s\n", "创建时间", date('Y-m-d H:i:s'));
printf("%-15s : %s\n", "过期时间", date('Y-m-d H:i:s', time() + 180));
echo str_repeat('-', 50) . "\n\n";

// 构建任务数据
$task = [
    'order_id' => $orderId,
    'type' => 2,
    'float_money' => sprintf('%.2f', $floatMoney),
    'merchant_num' => $merchantNum,
    'third_order_no' => $third_order_no,
    'create_time' => time(),
    'expire_time' => time() + 180,
];

// 确认推送
echo "是否推送此任务到队列？(y/n): ";
$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));
fclose($handle);

if ($line !== 'y' && $line !== 'Y') {
    echo "已取消推送\n";
    exit(0);
}

// 推送到队列
$queueName = 'order_poll_queue';
$taskJson = json_encode($task, JSON_UNESCAPED_UNICODE);

$result = $redis->rPush($queueName, $taskJson);

if ($result) {
    echo "\n";
    echo "✅ 任务推送成功！\n";
    echo str_repeat('=', 50) . "\n";
    echo "队列名称: {$queueName}\n";
    echo "当前队列长度: {$result}\n";
    echo "任务 JSON: {$taskJson}\n";
    echo str_repeat('=', 50) . "\n\n";
    
    echo "📊 监控命令：\n";
    echo "  守护进程日志: tail -f Runtime/Logs/Daemon/poll_" . date('Y-m-d') . ".log\n";
    echo "  匹配日志:     tail -f Runtime/Logs/OrderMatch/match_" . date('Y-m-d') . ".log\n";
    echo "  支付日志:     tail -f Runtime/Logs/Payment/payment_" . date('Y-m-d') . ".log\n\n";
    
    echo "⏱️  轮询说明：\n";
    echo "  - 守护进程会每5秒查询一次第三方接口\n";
    echo "  - 最多查询36次（3分钟）\n";
    echo "  - 匹配条件：金额={$floatMoney}元，时间差<3分钟，状态=2\n\n";
    
    echo "🔍 查看队列状态：\n";
    echo "  php cli.php order/status\n\n";
    
} else {
    echo "\n❌ 任务推送失败！\n";
    exit(1);
}

echo "======================================\n";
echo "推送完成！请查看守护进程日志\n";
echo "======================================\n";
