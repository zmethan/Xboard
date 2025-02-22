<?php

namespace App\Payments;

use App\Exceptions\ApiException;
use App\Models\PaymentGateway; // Adjust if model differs

class Paypal
{
    protected $config;
    
    public function __construct($config = null)
    {
        if (is_null($config)) {
            $gateway = PaymentGateway::where('gateway', 'Paypal')->first();
            if (!$gateway) {
                throw new \InvalidArgumentException("Paypal gateway not configured in admin panel");
            }
            $this->config = json_decode($gateway->config, true);
        } else {
            $this->config = $config;
        }

        $required = ['paypal_url', 'paypal_client_id', 'paypal_secret', 'paypal_currency', 'base_url', 'callback_suffix'];
        foreach ($required as $key) {
            if (!isset($this->config[$key])) {
                error_log("Warning: Missing $key in Paypal config, using default");
                $this->config[$key] = $key === 'base_url' ? 'https://example.com' : '';
            }
        }
    }

    public function form()
    {
        return [
            'paypal_url' => [
                'label' => '接口地址',
                'description' => 'e.g. https://api-m.sandbox.paypal.com for sandbox, https://api-m.paypal.com for live',
                'type' => 'input',
            ],
            'paypal_client_id' => [
                'label' => 'Client ID',
                'description' => '',
                'type' => 'input',
            ],
            'paypal_secret' => [
                'label' => 'Secret',
                'description' => '',
                'type' => 'input',
            ],
            'paypal_currency' => [
                'label' => '币种',
                'description' => 'e.g. USD, EUR, GBP',
                'type' => 'input',
            ],
            'base_url' => [
                'label' => '网站地址',
                'description' => 'e.g. https://yourdomain.com',
                'type' => 'input',
            ],
            'callback_suffix' => [
                'label' => '通知地址 Suffix',
                'description' => '需要先保存才能获得通知地址，填入最后那串乱码 (例: X67UZu8)',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order)
    {
        $accessToken = $this->getAccessToken();
        
        $callbackUrl = $this->config['base_url'] . '/api/v1/guest/payment/notify/Paypal/' . 
                       $this->config['callback_suffix'] . '?trade_no=' . $order['trade_no'];

        $params = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'amount' => [
                        'currency_code' => $this->config['paypal_currency'],
                        'value' => sprintf('%.2f', $order['total_amount'] / 100)
                    ],
                    'description' => 'Order ' . $order['trade_no'],
                    'custom_id' => $order['trade_no']
                ]
            ],
            'application_context' => [
                'return_url' => $callbackUrl,
                'cancel_url' => $this->config['base_url'] . '/#/order',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW'
            ],
            'payment_source' => [
                'card' => [
                    'experience_context' => [
                        'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
                        'brand_name' => 'Your Store Name',
                        'locale' => 'en-US',
                        'shipping_preference' => 'NO_SHIPPING',
                        'user_action' => 'PAY_NOW'
                    ]
                ]
            ]
        ];

        $result = $this->_curlPost(
            $this->config['paypal_url'] . '/v2/checkout/orders',
            json_encode($params),
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'PayPal-Request-Id' => uniqid()
            ]
        );

        $response = json_decode($result, true);

        // $logData = [
        //     'timestamp' => date('Y-m-d H:i:s'),
        //     'trade_no' => $order['trade_no'],
        //     'request_params' => $params,
        //     'raw_response' => $result,
        //     'decoded_response' => $response
        // ];
        // $logMessage = "=== Paypal Order Creation Debug ===\n" . print_r($logData, true);
        // file_put_contents('/tmp/paypal_order_creation.log', $logMessage . "\n", FILE_APPEND);
        // error_log($logMessage);

        if (empty($response['links'])) {
            throw new ApiException("Paypal API error: Unable to create order - " . ($response['message'] ?? 'Unknown error'));
        }

        $approvalUrl = '';
        foreach ($response['links'] as $link) {
            if ($link['rel'] === 'approve') {
                $approvalUrl = $link['href'];
                break;
            }
        }

        return [
            'type' => 1,
            'data' => $approvalUrl
        ];
    }

    public function notify($params)
    {
        try {
            // Handle capture from return_url (GET request)
            if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['trade_no']) && isset($_GET['token'])) {
                $trade_no = $_GET['trade_no'];
                $token = $_GET['token'];

                $accessToken = $this->getAccessToken();
                $result = $this->_curlPost(
                    $this->config['paypal_url'] . '/v2/checkout/orders/' . $token . '/capture',
                    '',
                    [
                        'Authorization: Bearer ' . $accessToken,
                        'Content-Type: application/json',
                        'PayPal-Request-Id' => uniqid()
                    ]
                );

                $response = json_decode($result, true);

                // $logData = [
                //     'timestamp' => date('Y-m-d H:i:s'),
                //     'trade_no' => $trade_no,
                //     'token' => $token,
                //     'raw_response' => $result,
                //     'decoded_response' => $response
                // ];
                // $logMessage = "=== Paypal Capture Debug ===\n" . print_r($logData, true);
                // file_put_contents('/tmp/paypal_capture.log', $logMessage . "\n", FILE_APPEND);
                // error_log($logMessage);

                if (empty($response['status']) || $response['status'] !== 'COMPLETED') {
                    $errorDetail = $response['details'][0]['issue'] ?? 'Unknown error';
                    $errorDescription = $response['details'][0]['description'] ?? 'Capture failed';
                    return [
                        'trade_no' => $trade_no,
                        'callback_no' => $token,
                        'status' => 'failed',
                        'error' => $errorDescription,
                        'redirect' => $this->config['base_url'] . "/#/order"
                    ];
                }

                // Redirect to order page
                return [
                    'trade_no' => $trade_no,
                    'callback_no' => $response['id'],
                    'redirect' => $this->config['base_url'] . "/#/order/" . $trade_no
                ];
            }
        } catch (ApiException $e) {
            $errorMessage = "Paypal API ERROR: " . $e->getMessage();
            error_log($errorMessage);
            file_put_contents('/tmp/paypal_webhook_error.log', $errorMessage . "\n", FILE_APPEND);
            throw $e;
        }
    }

    private function getAccessToken()
    {
        $auth = base64_encode($this->config['paypal_client_id'] . ':' . $this->config['paypal_secret']);
        
        $result = $this->_curlPost(
            $this->config['paypal_url'] . '/v1/oauth2/token',
            'grant_type=client_credentials',
            [
                'Authorization: Basic ' . $auth,
                'Content-Type: application/x-www-form-urlencoded'
            ]
        );

        $response = json_decode($result, true);
        
        // $logData = [
        //     'timestamp' => date('Y-m-d H:i:s'),
        //     'url' => $this->config['paypal_url'] . '/v1/oauth2/token',
        //     'client_id' => $this->config['paypal_client_id'],
        //     'secret' => substr($this->config['paypal_secret'], 0, 4) . '...',
        //     'raw_response' => $result,
        //     'decoded_response' => $response
        // ];
        // $logMessage = "=== Paypal Access Token Debug ===\n" . print_r($logData, true);
        // file_put_contents('/tmp/paypal_access_token.log', $logMessage . "\n", FILE_APPEND);
        // error_log($logMessage);

        if (empty($response['access_token'])) {
            $errorDetail = $response['error'] ?? 'Unknown error';
            $errorDescription = $response['error_description'] ?? 'No access token returned';
            throw new ApiException("Failed to get Paypal access token: {$errorDetail} - {$errorDescription}");
        }
        
        return $response['access_token'];
    }


    private function _curlPost($url, $params = false, $headers = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            curl_close($ch);
            throw new ApiException("cURL error: {$error_msg}");
        }
        
        curl_close($ch);
        return $result;
    }
}

