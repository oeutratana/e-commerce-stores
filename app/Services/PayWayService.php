<?php

namespace App\Services;

use App\Models\Order;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PayWayService
{
    protected string $merchantId;

    protected string $apiKey;

    protected string $purchaseUrl;

    protected string $checkUrl;

    protected string $returnUrl;

    protected string $callbackUrl;

    public function __construct()
    {
        $this->merchantId = config('services.payway.merchant_id', '');
        $this->apiKey = config('services.payway.api_key', '');
        $this->purchaseUrl = config('services.payway.purchase_url', '');
        $this->checkUrl = config('services.payway.check_url', '');
        $this->returnUrl = config('services.payway.return_url', '');
        $this->callbackUrl = config('services.payway.callback_url', '');
    }

    /**
     * Generate a unique transaction ID for PayWay (max 20 chars).
     */
    public function generateTransactionId(Order $order): string
    {
        return 'TXN-'.$order->id.'-'.date('ymdHis');
    }

    /**
     * Generate HMAC-SHA512 hash for PayWay Purchase API.
     *
     * Hash order: req_time, merchant_id, tran_id, amount, items, shipping,
     * firstname, lastname, email, phone, type, payment_option, return_url,
     * cancel_url, continue_success_url, return_deeplink, currency,
     * custom_fields, return_params, payout, lifetime, additional_params,
     * google_pay_token, skip_success_page
     */
    public function generateHash(array $params): string
    {
        $apiKey = $this->apiKey;

        $b4hash = ($params['req_time'] ?? '')
            .($params['merchant_id'] ?? '')
            .($params['tran_id'] ?? '')
            .($params['amount'] ?? '')
            .($params['items'] ?? '')
            .($params['shipping'] ?? '')
            .($params['firstname'] ?? '')
            .($params['lastname'] ?? '')
            .($params['email'] ?? '')
            .($params['phone'] ?? '')
            .($params['type'] ?? '')
            .($params['payment_option'] ?? '')
            .($params['return_url'] ?? '')
            .($params['cancel_url'] ?? '')
            .($params['continue_success_url'] ?? '')
            .($params['return_deeplink'] ?? '')
            .($params['currency'] ?? '')
            .($params['custom_fields'] ?? '')
            .($params['return_params'] ?? '')
            .($params['payout'] ?? '')
            .($params['lifetime'] ?? '')
            .($params['additional_params'] ?? '')
            .($params['google_pay_token'] ?? '')
            .($params['skip_success_page'] ?? '');

        return base64_encode(hash_hmac('sha512', $b4hash, $apiKey, true));
    }

    /**
     * Create a PayWay purchase transaction.
     *
     * @return array{success: bool, checkout_url: ?string, html: ?string, data: ?array, error: ?string}
     */
    public function createPurchase(Order $order, string $tranId): array
    {
        $amount = number_format((float) $order->total_price, 2, '.', '');
        $reqTime = $this->getReqTime();
        $returnUrl = base64_encode($this->returnUrl);

        $hashParams = [
            'req_time' => $reqTime,
            'merchant_id' => $this->merchantId,
            'tran_id' => $tranId,
            'amount' => $amount,
            'items' => '',
            'shipping' => '',
            'firstname' => '',
            'lastname' => '',
            'email' => '',
            'phone' => '',
            'type' => 'purchase',
            'payment_option' => '',
            'return_url' => $returnUrl,
            'cancel_url' => '',
            'continue_success_url' => '',
            'return_deeplink' => '',
            'currency' => 'USD',
            'custom_fields' => '',
            'return_params' => $tranId,
            'payout' => '',
            'lifetime' => '',
            'additional_params' => '',
            'google_pay_token' => '',
            'skip_success_page' => '',
        ];

        $hash = $this->generateHash($hashParams);

        // ABA flags sending empty optional fields as a "Wrong Hash" risk.
        // Only non-empty fields are included in the request body; the hash
        // computation still treats unset fields as empty.
        //
        // Note: PayWay delivers server-to-server pushbacks to `return_url`
        // (base64-encoded), so no separate `callback_url` field is sent.
        $body = [
            'req_time' => $reqTime,
            'merchant_id' => $this->merchantId,
            'tran_id' => $tranId,
            'amount' => $amount,
            'hash' => $hash,
            'type' => 'purchase',
            'currency' => 'USD',
            'return_url' => $returnUrl,
            'return_params' => $tranId,
        ];

        try {
            $response = Http::timeout(30)
                ->asMultipart()
                ->post($this->purchaseUrl, $body);

            if ($response->failed()) {
                $safeBody = Str::limit(substr($response->body(), 0, 500), 500);

                Log::warning('PayWay API returned error', [
                    'http_status' => $response->status(),
                    'tran_id' => $tranId,
                    'response_body' => $safeBody,
                ]);

                return [
                    'success' => false,
                    'checkout_url' => null,
                    'html' => null,
                    'data' => null,
                    'error' => 'PayWay API returned an error ('.$response->status().').',
                ];
            }

            $contentType = $response->header('Content-Type') ?? '';
            $responseBody = $response->body();

            if (str_contains($contentType, 'text/html')) {
                return [
                    'success' => true,
                    'checkout_url' => null,
                    'html' => $responseBody,
                    'data' => null,
                    'error' => null,
                ];
            }

            $json = $response->json();

            if (isset($json['status']['code']) && $json['status']['code'] === '00') {
                return [
                    'success' => true,
                    'checkout_url' => $json['checkout_qr_url'] ?? null,
                    'html' => null,
                    'data' => $json,
                    'error' => null,
                ];
            }

            $errorMsg = $json['status']['message'] ?? 'PayWay returned an error.';

            Log::warning('PayWay purchase failed', [
                'tran_id' => $tranId,
                'code' => $json['status']['code'] ?? null,
                'message' => $errorMsg,
            ]);

            return [
                'success' => false,
                'checkout_url' => null,
                'html' => null,
                'data' => $json,
                'error' => $errorMsg,
            ];
        } catch (Exception $e) {
            Log::error('PayWay API request failed', [
                'error' => $e->getMessage(),
                'tran_id' => $tranId,
            ]);

            return [
                'success' => false,
                'checkout_url' => null,
                'html' => null,
                'data' => null,
                'error' => 'Unable to connect to PayWay payment service.',
            ];
        }
    }

    /**
     * Generate hash for PayWay Check Transaction API.
     */
    public function generateCheckHash(string $reqTime, string $tranId): string
    {
        $b4hash = $reqTime.$this->merchantId.$tranId;

        return base64_encode(hash_hmac('sha512', $b4hash, $this->apiKey, true));
    }

    /**
     * Check transaction status with PayWay.
     *
     * @return array{status: string, data: ?array, error: ?string}
     */
    public function checkTransaction(string $tranId): array
    {
        $reqTime = $this->getReqTime();
        $hash = $this->generateCheckHash($reqTime, $tranId);

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->asJson()
                ->post($this->checkUrl, [
                    'req_time' => $reqTime,
                    'merchant_id' => $this->merchantId,
                    'tran_id' => $tranId,
                    'hash' => $hash,
                ]);

            if ($response->failed()) {
                Log::warning('PayWay check-transaction HTTP error', [
                    'http_status' => $response->status(),
                    'tran_id' => $tranId,
                ]);

                return [
                    'status' => 'API_ERROR',
                    'data' => null,
                    'error' => 'Received unexpected response from PayWay.',
                ];
            }

            $json = $response->json();

            if (isset($json['status']['code']) && $json['status']['code'] === '00') {
                $data = $json['data'] ?? null;
                $paymentStatusCode = $data['payment_status_code'] ?? null;

                if ($paymentStatusCode === 0) {
                    return [
                        'status' => 'SUCCESS',
                        'data' => $data,
                        'error' => null,
                    ];
                }

                if (in_array($paymentStatusCode, [2])) {
                    return [
                        'status' => 'PENDING',
                        'data' => $data,
                        'error' => null,
                    ];
                }

                // 3 = DECLINED, 4 = REFUNDED, 7 = CANCELLED
                return [
                    'status' => 'FAILED',
                    'data' => $data,
                    'error' => $data['payment_status'] ?? 'Transaction failed.',
                ];
            }

            $errorMsg = $json['status']['message'] ?? 'Transaction not found.';

            Log::warning('PayWay check-transaction returned error', [
                'tran_id' => $tranId,
                'code' => $json['status']['code'] ?? null,
                'message' => $errorMsg,
            ]);

            return [
                'status' => 'API_ERROR',
                'data' => null,
                'error' => $errorMsg,
            ];
        } catch (Exception $e) {
            Log::error('PayWay check-transaction request failed', [
                'error' => $e->getMessage(),
                'tran_id' => $tranId,
            ]);

            return [
                'status' => 'API_ERROR',
                'data' => null,
                'error' => 'Unable to connect to PayWay payment service.',
            ];
        }
    }

    /**
     * Get current time in UTC format required by PayWay (YYYYMMDDHHmmss).
     */
    public function getReqTime(): string
    {
        return now('UTC')->format('YmdHis');
    }
}
