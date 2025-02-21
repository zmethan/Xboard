<?php

namespace App\Payments;

use App\Exceptions\ApiException;

class Coinbase
{
    protected $config;
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'coinbase_url' => [
                'label' => '接口地址',
                'description' => '',
                'type' => 'input',
            ],
            'coinbase_api_key' => [
                'label' => 'API KEY',
                'description' => '',
                'type' => 'input',
            ],
            'coinbase_webhook_key' => [
                'label' => 'WEBHOOK SECRET',
                'description' => '',
                'type' => 'input',
            ],
            'coinbase_product_name' => [
                'label' => '自定义产品名称',
                'description' => '',
                'type' => 'input',
            ],
            'coinbase_currency' => [
                'label' => '币种',
                'description' => '',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order)
    {

        $params = [
            'name' => $this->config['coinbase_product_name'],
            'description' => 'Order ' . $order['trade_no'],
            'pricing_type' => 'fixed_price',
            'local_price' => [
                'amount' => sprintf('%.2f', $order['total_amount'] / 100),
                'currency' => $this->config['coinbase_currency']
            ],
            'metadata' => [
                "outTradeNo" => $order['trade_no'],
            ],
        ];

        $params_string = http_build_query($params);

        $ret_raw = self::_curlPost($this->config['coinbase_url'], $params_string);

        $ret = @json_decode($ret_raw, true);

        if (empty($ret['data']['hosted_url'])) {
            throw new ApiException("error!");
        }
        return [
            'type' => 1,
            'data' => $ret['data']['hosted_url'],
        ];
    }


    public function notify($params)
    {
        try {
            $payload = get_request_content();
            $payload_old = $payload
            // 反转义斜杠
            $payload = str_replace('\\/', '/', $payload);

            $json_param = json_decode($payload, true);

            $headerName = 'X-Cc-Webhook-Signature';
            $headers = getallheaders();
            $signatureHeader = $headers[$headerName] ?? '';
            
            // 计算 HMAC 并进行 Base64 编码
            $computedSignature = hash_hmac('sha256', $payload, $this->config['coinbase_webhook_key'], false);
            
            // 记录Debug Log
            // $logData = [
            //     'timestamp' => date('Y-m-d H:i:s'),
            //     'received_signature' => $signatureHeader,
            //     'computed_signature' => $computedSignature,
            //     'raw_payload_old' => $payload_old,
            //     'raw_payload' => $payload,
            //     'decoded_payload' => $json_param,
            //     'headers' => $headers
            // ];
            // $logMessage = "=== Coinbase Webhook Debug ===\n" . print_r($logData, true);
            // file_put_contents('/tmp/coinbase_webhook.log', $logMessage . "\n", FILE_APPEND);
            // error_log($logMessage);

            // 验证签名
            if (!$this->hashEqual($signatureHeader, $computedSignature)) {
                throw new ApiException("HMAC signature does not match. \nExpected: {$computedSignature}, \nActual: {$signatureHeader}", 400);
            }
            
            // 提取业务数据
            $out_trade_no = $json_param['event']['data']['metadata']['outTradeNo'] ?? 'N/A';
            $pay_trade_no = $json_param['event']['id'] ?? 'N/A';
            
            return [
                'trade_no' => $out_trade_no,
                'callback_no' => $pay_trade_no
            ];
        } catch (ApiException $e) {
            $errorMessage = "API ERROR: " . $e->getMessage();
            error_log($errorMessage);
            file_put_contents('/tmp/coinbase_webhook_error.log', $errorMessage . "\n", FILE_APPEND);
            throw $e; 
        }
    }


    private function _curlPost($url, $params = false)
    {

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array('X-CC-Api-Key:' . $this->config['coinbase_api_key'], 'X-CC-Version: 2018-03-22')
        );
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }


    /**
    * @param string $computedSignature
    * @param string $receivedSignature
    * @return bool
    */
    public function hashEqual($computedSignature, $receivedSignature)
    {
        if (function_exists('hash_equals')) {
            return hash_equals($computedSignature, $receivedSignature);
        }

        if (strlen($computedSignature) !== strlen($receivedSignature)) {
            return false;
        }

        $res = $computedSignature ^ $receivedSignature;
        $ret = 0;

        for ($i = strlen($res) - 1; $i >= 0; $i--) {
            $ret |= ord($res[$i]);
        }

        return !$ret;
    }

}
