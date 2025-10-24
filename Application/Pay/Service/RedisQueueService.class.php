<?php
namespace Pay\Service;

/**
 * Redis 队列服务类
 * 用于异步任务的队列管理
 */
class RedisQueueService
{
    private $redis;
    private $host = '127.0.0.1';
    private $port = 6379;
    
    public function __construct()
    {
        $this->redis = new \Redis();
        $this->redis->connect($this->host, $this->port);
    }
    
    /**
     * 推送任务到队列
     * @param string $queueName 队列名称
     * @param mixed $data 任务数据
     * @return int 队列长度
     */
    public function push($queueName, $data)
    {
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
        return $this->redis->rPush($queueName, $jsonData);
    }
    
    /**
     * 从队列取出任务（阻塞式）
     * @param string $queueName 队列名称
     * @param int $timeout 超时时间（秒）
     * @return array|null 任务数据
     */
    public function pop($queueName, $timeout = 5)
    {
        $result = $this->redis->blPop($queueName, $timeout);
        if ($result && isset($result[1])) {
            return json_decode($result[1], true);
        }
        return null;
    }
    
    /**
     * 获取队列长度
     * @param string $queueName 队列名称
     * @return int 队列长度
     */
    public function getQueueLength($queueName)
    {
        return $this->redis->lLen($queueName);
    }
    
    /**
     * 清空队列
     * @param string $queueName 队列名称
     * @return bool
     */
    public function clear($queueName)
    {
        return $this->redis->del($queueName);
    }
    
    /**
     * 查看队列内容（不消费）
     * @param string $queueName 队列名称
     * @param int $start 开始位置
     * @param int $end 结束位置
     * @return array
     */
    public function view($queueName, $start = 0, $end = -1)
    {
        $items = $this->redis->lRange($queueName, $start, $end);
        $result = [];
        foreach ($items as $item) {
            $result[] = json_decode($item, true);
        }
        return $result;
    }
    
    /**
     * 获取 Redis 实例
     * @return \Redis
     */
    public function getRedis()
    {
        return $this->redis;
    }
}
