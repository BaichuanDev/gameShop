<?php
namespace Pay\Service;

/**
 * 第三方订单接口服务类
 * 用于查询第三方平台的订单信息
 */
class ThirdPartyOrderService
{
    private $baseUrl = 'http://app.020leader.com';
    
    /**
     * 获取订单列表
     * @param string $merchantNum 商户号
     * @param string $startTime 开始时间
     * @param string $endTime 结束时间
     * @return array 订单列表
     */
    public function getOrderList($merchantNum, $startTime = null, $endTime = null)
    {
        if (!$startTime) {
            $startTime = date('Y-m-d H:i:s', time() - 180); // 默认查询10分钟内
        }
        if (!$endTime) {
            $endTime = date('Y-m-d H:i:s');
        }
        
        $url = $this->baseUrl . '/checkstand/v3/orderlist';
        
        $params = [
            'size' => '100',
            'cashierNum' => 'all',
            'merchantNum' => $merchantNum,
            'status' => 2,
            'startTime' => $startTime,
            'endTime' => $endTime,
        ];
        
        $fullUrl = $url . '?' . http_build_query($params);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode != 200 || !$response) {
            $this->log("第三方接口请求失败: HTTP {$httpCode}, Error: {$error}");
            return [];
        }
        
        return $this->parseResponse($response);
    }
    
    /**
     * 解析响应数据
     * @param string $response 响应内容
     * @return array 订单列表
     */
    private function parseResponse($response)
    {
        $data = json_decode($response, true);
        
        if (!$data) {
            $this->log("第三方接口响应解析失败: " . $response);
            return [];
        }
        
        // 根据实际返回格式调整
        // 假设返回格式：{"code": 0, "msg": "success", "data": {"list": [...]}}
        if (isset($data['resultCode']) && $data['resultCode'] == 'SUCCESS' && isset($data['resultData']['orderList'])) {
            return $data['resultData']['orderList'];
        }

        
        // 如果是数组直接返回
        if (is_array($data) && isset($data[0])) {
            return $data;
        }
        
        $this->log("第三方接口返回格式不符合预期: " . json_encode($data, JSON_UNESCAPED_UNICODE));
        return [];
    }
    
    /**
     * 根据订单号查询订单详情
     * @param string $merchantNum 商户号
     * @param string $orderNo 订单号
     * @return array|null 订单详情
     */
    public function getOrderDetail($merchantNum, $orderNo)
    {
        $url = $this->baseUrl . '/checkstand/v3/orderdetail';
        
        $params = [
            'merchantNum' => $merchantNum,
            'orderNum' => $orderNo,
        ];
        
        $fullUrl = $url . '?' . http_build_query($params);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);

        if (!$data) {
            $this->log("第三方接口响应解析失败: " . $response);
            return [];
        }
        if (isset($data['resultCode']) && $data['resultCode'] == 'SUCCESS' && isset($data['resultData']['order'])) {
            return $data['resultData']['order'];
        }

        // 如果是数组直接返回
        if (is_array($data) && isset($data[0])) {
            return $data;
        }

        $this->log("第三方接口返回格式不符合预期: " . json_encode($data, JSON_UNESCAPED_UNICODE));
        return [];
    }
    
    /**
     * 记录日志
     * @param string $message 日志内容
     */
    private function log($message)
    {
        $logDir = RUNTIME_PATH . 'Logs/ThirdPartyApi/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $content = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        file_put_contents($logDir . 'api_' . date('Y-m-d') . '.log', $content, FILE_APPEND | LOCK_EX);
    }
}
