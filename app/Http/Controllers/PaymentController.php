<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Services\BakongPaymentService;
use App\Services\PayWayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    public function index(Request $req)
    {
        $payments = Payment::with('order')
            ->whereHas('order', function ($query) use ($req) {
                $query->where('user_id', $req->user()->id);
            })
            ->get();

        return apiResponse($payments, 200, 'Get payments successfully...');
    }

    public function show(Request $req, $id)
    {
        $payment = Payment::with('order')
            ->where('id', $id)
            ->whereHas('order', function ($query) use ($req) {
                $query->where('user_id', $req->user()->id);
            })
            ->firstOrFail();

        return apiResponse($payment, 200, 'Get payment successfully...');
    }

    public function store(Request $req)
    {
        $validator = Validator::make($req->all(), [
            'order_id' => 'required|exists:orders,id',
            'payment_method' => 'required|string|in:KHQR',
            'payment_status' => 'nullable|string',
            'transaction_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $data = $validator->validated();

        $req->user()->orders()->where('id', $data['order_id'])->firstOrFail();

        $payment = Payment::create($data);

        return apiResponse($payment->load('order'), 201, 'Add payment successfully...');
    }

    public function update(Request $req, $id)
    {
        $payment = Payment::where('id', $id)
            ->whereHas('order', function ($query) use ($req) {
                $query->where('user_id', $req->user()->id);
            })
            ->firstOrFail();

        $validator = Validator::make($req->all(), [
            'order_id' => 'sometimes|required|exists:orders,id',
            'payment_method' => 'sometimes|required|string',
            'payment_status' => 'sometimes|required|string',
            'transaction_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $data = $validator->validated();

        if (array_key_exists('order_id', $data)) {
            $req->user()->orders()->where('id', $data['order_id'])->firstOrFail();
        }

        $payment->update($data);

        return apiResponse($payment->load('order'), 200, 'Update payment successfully...');
    }

    public function destroy(Request $req, $id)
    {
        Payment::where('id', $id)
            ->whereHas('order', function ($query) use ($req) {
                $query->where('user_id', $req->user()->id);
            })
            ->firstOrFail()
            ->delete();

        return apiResponse(null, 200, 'Delete payment successfully...');
    }

    /**
     * Create a KHQR payment for an order.
     * POST /api/payments/khqr
     */
    public function createKhqr(Request $req, BakongPaymentService $bakongService)
    {
        $validator = Validator::make($req->all(), [
            'order_id' => 'required|integer',
            'currency' => 'nullable|string|in:USD,KHR,usd,khr',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $orderId = $req->input('order_id');
        $order = Order::find($orderId);

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        // Ownership verification
        if ($order->user_id !== $req->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this order.',
            ], 403);
        }

        // Check if order is already paid
        if (in_array(strtoupper($order->status), ['PAID', 'COMPLETED'])) {
            return response()->json([
                'success' => false,
                'message' => 'Order is already paid.',
            ], 422);
        }

        // Check if order is cancelled
        if (strtoupper($order->status) === 'CANCELLED') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot create payment for a cancelled order.',
            ], 422);
        }

        // Validate order amount
        if ((float) $order->total_price <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid order total price.',
            ], 422);
        }

        // Check for conflicting successful payment
        $hasSuccessfulPayment = Payment::where('order_id', $order->id)
            ->where(function ($query) {
                $query->where('payment_status', 'SUCCESS')
                    ->orWhere('payment_status', 'paid');
            })
            ->exists();

        if ($hasSuccessfulPayment) {
            return response()->json([
                'success' => false,
                'message' => 'Order already has a successful payment.',
            ], 422);
        }

        $currency = $req->input('currency', 'USD');
        $khqrData = $bakongService->generatePaymentKhqr($order, $currency);

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_method' => 'KHQR',
            'payment_status' => 'PENDING',
            'amount' => $order->total_price,
            'currency' => $khqrData['currency'],
            'qr_data' => $khqrData['qr'],
            'md5' => $khqrData['md5'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'KHQR created successfully',
            'data' => [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->payment_status,
                'qr' => $payment->qr_data,
                'md5' => $payment->md5,
                'created_at' => $payment->created_at,
            ],
        ], 201);
    }

    /**
     * Check KHQR payment status and verify transaction with Bakong if needed.
     * GET /api/payments/{payment}/status
     */
    public function status(Request $req, $id, BakongPaymentService $bakongService)
    {
        $payment = Payment::with('order')->find($id);

        if (! $payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found.',
            ], 404);
        }

        // Verify payment ownership via associated order
        if (! $payment->order || $payment->order->user_id !== $req->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this payment.',
            ], 403);
        }

        // If already SUCCESS, return immediately (idempotent, avoid redundant external calls)
        if ($payment->payment_status === 'SUCCESS') {
            return response()->json([
                'success' => true,
                'message' => 'Payment status retrieved successfully',
                'data' => [
                    'payment_id' => $payment->id,
                    'order_id' => $payment->order_id,
                    'status' => 'SUCCESS',
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->currency,
                    'transaction_hash' => $payment->transaction_hash,
                    'paid_at' => $payment->paid_at,
                    'order_status' => $payment->order->status,
                ],
            ], 200);
        }

        // If payment is KHQR and has MD5, verify with Bakong API
        if ($payment->payment_method === 'KHQR' && ! empty($payment->md5)) {
            $verification = $bakongService->checkTransactionByMd5($payment->md5);

            if ($verification['status'] === 'SUCCESS') {
                DB::transaction(function () use ($payment, $verification) {
                    $payment->refresh();
                    if ($payment->payment_status !== 'SUCCESS') {
                        $hash = $verification['hash'] ?? $payment->transaction_hash ?? ('TXN-'.uniqid());
                        $payment->update([
                            'payment_status' => 'SUCCESS',
                            'transaction_hash' => $hash,
                            'transaction_id' => $hash,
                            'paid_at' => now(),
                        ]);

                        $payment->order->update([
                            'status' => 'PAID',
                        ]);
                    }
                });

                $payment->refresh();
            } elseif ($verification['status'] === 'FAILED') {
                $payment->update([
                    'payment_status' => 'FAILED',
                ]);
            }
            // If PENDING or API_ERROR, keep status as is without marking failed prematurely
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment status retrieved successfully',
            'data' => [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'status' => $payment->payment_status,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'transaction_hash' => $payment->transaction_hash,
                'paid_at' => $payment->paid_at,
                'order_status' => $payment->order ? $payment->order->status : null,
            ],
        ], 200);
    }

    /**
     * Create a PayWay payment for an order.
     * POST /api/payments/payway
     */
    public function createPayway(Request $req, PayWayService $paywayService): JsonResponse
    {
        $validator = Validator::make($req->all(), [
            'order_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $orderId = $req->input('order_id');
        $order = Order::find($orderId);

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        if ($order->user_id !== $req->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this order.',
            ], 403);
        }

        if (in_array(strtoupper($order->status), ['PAID', 'COMPLETED'])) {
            return response()->json([
                'success' => false,
                'message' => 'Order is already paid.',
            ], 422);
        }

        if (strtoupper($order->status) === 'CANCELLED') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot create payment for a cancelled order.',
            ], 422);
        }

        if ((float) $order->total_price <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid order total price.',
            ], 422);
        }

        $hasSuccessfulPayment = Payment::where('order_id', $order->id)
            ->where(function ($query) {
                $query->where('payment_status', 'SUCCESS')
                    ->orWhere('payment_status', 'paid');
            })
            ->exists();

        if ($hasSuccessfulPayment) {
            return response()->json([
                'success' => false,
                'message' => 'Order already has a successful payment.',
            ], 422);
        }

        $tranId = $paywayService->generateTransactionId($order);

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_method' => 'PAYWAY',
            'payment_status' => 'PENDING',
            'amount' => $order->total_price,
            'currency' => 'USD',
            'transaction_id' => $tranId,
        ]);

        $result = $paywayService->createPurchase($order, $tranId);

        if (! $result['success']) {
            $payment->update(['payment_status' => 'FAILED']);

            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Failed to create PayWay transaction.',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'PayWay transaction created successfully',
            'data' => [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->payment_status,
                'transaction_id' => $tranId,
                'checkout_url' => $result['checkout_url'],
                'checkout_html' => $result['html'],
                'payway_data' => $result['data'],
                'created_at' => $payment->created_at,
            ],
        ], 201);
    }

    /**
     * Check PayWay payment status and verify with PayWay API if needed.
     * GET /api/payments/{payment_id}/payway/status
     */
    public function paywayStatus(Request $req, $id, PayWayService $paywayService): JsonResponse
    {
        $payment = Payment::with('order')->find($id);

        if (! $payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found.',
            ], 404);
        }

        if ($payment->payment_method !== 'PAYWAY') {
            return response()->json([
                'success' => false,
                'message' => 'This endpoint is for PayWay payments only.',
            ], 422);
        }

        if (! $payment->order || $payment->order->user_id !== $req->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this payment.',
            ], 403);
        }

        if ($payment->payment_status === 'SUCCESS') {
            return response()->json([
                'success' => true,
                'message' => 'Payment status retrieved successfully',
                'data' => [
                    'payment_id' => $payment->id,
                    'order_id' => $payment->order_id,
                    'status' => 'SUCCESS',
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->currency,
                    'transaction_id' => $payment->transaction_id,
                    'paid_at' => $payment->paid_at,
                    'order_status' => $payment->order->status,
                ],
            ], 200);
        }

        if ($payment->payment_status === 'PENDING' && ! empty($payment->transaction_id)) {
            $verification = $paywayService->checkTransaction($payment->transaction_id);

            if ($verification['status'] === 'SUCCESS') {
                DB::transaction(function () use ($payment) {
                    $payment->refresh();
                    if ($payment->payment_status !== 'SUCCESS') {
                        $payment->update([
                            'payment_status' => 'SUCCESS',
                            'transaction_hash' => $payment->transaction_id,
                            'paid_at' => now(),
                        ]);

                        $payment->order->update([
                            'status' => 'PAID',
                        ]);
                    }
                });

                $payment->refresh();
            } elseif ($verification['status'] === 'FAILED') {
                $payment->update([
                    'payment_status' => 'FAILED',
                ]);
            }
            // If API_ERROR or PENDING, keep status as is
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment status retrieved successfully',
            'data' => [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'status' => $payment->payment_status,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'transaction_id' => $payment->transaction_id,
                'paid_at' => $payment->paid_at,
                'order_status' => $payment->order ? $payment->order->status : null,
            ],
        ], 200);
    }

    /**
     * PayWay callback endpoint (server-to-server notification).
     * POST /api/payments/payway/callback
     */
    public function paywayCallback(Request $req, PayWayService $paywayService): JsonResponse
    {
        $tranId = $req->input('tran_id') ?? $req->input('transaction_id');

        if (empty($tranId)) {
            return response()->json([
                'success' => false,
                'message' => 'Missing transaction ID.',
            ], 400);
        }

        $payment = Payment::with('order')
            ->where('payment_method', 'PAYWAY')
            ->where('transaction_id', $tranId)
            ->first();

        if (! $payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found.',
            ], 404);
        }

        // Idempotent: if already SUCCESS, do nothing
        if ($payment->payment_status === 'SUCCESS') {
            return response()->json([
                'success' => true,
                'message' => 'Payment already processed.',
            ], 200);
        }

        // Verify transaction with PayWay
        $verification = $paywayService->checkTransaction($tranId);

        if ($verification['status'] === 'SUCCESS') {
            DB::transaction(function () use ($payment) {
                $payment->refresh();
                if ($payment->payment_status !== 'SUCCESS') {
                    $payment->update([
                        'payment_status' => 'SUCCESS',
                        'transaction_hash' => $payment->transaction_id,
                        'paid_at' => now(),
                    ]);

                    $payment->order->update([
                        'status' => 'PAID',
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Payment verified and processed.',
            ], 200);
        }

        if ($verification['status'] === 'FAILED') {
            $payment->update([
                'payment_status' => 'FAILED',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment failed.',
            ], 200);
        }

        // API_ERROR or PENDING — keep as is, do not mark failed
        return response()->json([
            'success' => false,
            'message' => 'Payment verification pending or unavailable.',
        ], 200);
    }
}
