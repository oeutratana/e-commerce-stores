<?php

namespace App\Services;

use App\Models\Order;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BakongPaymentService
{
    protected string $apiUrl;

    protected ?string $apiToken;

    protected string $merchantId;

    protected string $merchantName;

    protected string $merchantCity;

    protected string $defaultCurrency;

    public function __construct()
    {
        $this->apiUrl = rtrim(config('services.bakong.api_url', 'https://api-bakong.nbc.gov.kh'), '/');
        $this->apiToken = config('services.bakong.api_token');
        $this->merchantId = config('services.bakong.merchant_id', 'merchant@devb');
        $this->merchantName = config('services.bakong.merchant_name', 'E-Commerce Store');
        $this->merchantCity = config('services.bakong.merchant_city', 'Phnom Penh');
        $this->defaultCurrency = config('services.bakong.currency', 'USD');
    }

    /**
     * Generate KHQR for an order.
     *
     * @return array{qr: string, md5: string, amount: float, currency: string}
     */
    public function generatePaymentKhqr(Order $order, ?string $currency = null): array
    {
        $currency = $currency ? strtoupper(trim($currency)) : $this->defaultCurrency;
        $amount = (float) $order->total_price;

        $khqrData = KhqrGenerator::generate([
            'bakong_account_id' => $this->merchantId,
            'merchant_name' => $this->merchantName,
            'merchant_city' => $this->merchantCity,
            'currency' => $currency,
            'amount' => $amount,
            'bill_number' => (string) $order->id,
        ]);

        return [
            'qr' => $khqrData['qr'],
            'md5' => $khqrData['md5'],
            'amount' => $amount,
            'currency' => $currency,
        ];
    }

    /**
     * Check transaction status with Bakong using MD5 hash.
     *
     * @return array{
     *     status: string,
     *     hash: ?string,
     *     data: ?array,
     *     message: string
     * }
     */
    public function checkTransactionByMd5(string $md5): array
    {
        $url = $this->apiUrl.'/v1/check_transaction_by_md5';

        try {
            $client = Http::timeout(15)
                ->acceptJson()
                ->asJson();

            if (! empty($this->apiToken)) {
                $client = $client->withToken($this->apiToken);
            }

            $response = $client->post($url, [
                'md5' => $md5,
            ]);

            if ($response->successful()) {
                $json = $response->json();
                $responseCode = $json['responseCode'] ?? null;
                $data = $json['data'] ?? null;

                // Bakong standard: responseCode 0 means transaction succeeded
                if ($responseCode === 0 && ! empty($data)) {
                    return [
                        'status' => 'SUCCESS',
                        'hash' => $data['hash'] ?? $data['externalTransactionId'] ?? null,
                        'data' => $data,
                        'message' => $json['responseMessage'] ?? 'Payment verified successfully.',
                    ];
                }

                // If responseCode indicates not found or still pending
                return [
                    'status' => 'PENDING',
                    'hash' => null,
                    'data' => null,
                    'message' => $json['responseMessage'] ?? 'Transaction is still pending or not found.',
                ];
            }

            // HTTP 404 or specific code from Bakong
            if ($response->status() === 404) {
                return [
                    'status' => 'PENDING',
                    'hash' => null,
                    'data' => null,
                    'message' => 'Transaction not found in Bakong.',
                ];
            }

            Log::warning('Bakong API non-successful HTTP response', [
                'http_status' => $response->status(),
            ]);

            return [
                'status' => 'API_ERROR',
                'hash' => null,
                'data' => null,
                'message' => 'Received unexpected response from payment provider.',
            ];
        } catch (Exception $e) {
            Log::error('Bakong API request failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'API_ERROR',
                'hash' => null,
                'data' => null,
                'message' => 'Unable to connect to Bakong payment service. Please try again later.',
            ];
        }
    }
}
