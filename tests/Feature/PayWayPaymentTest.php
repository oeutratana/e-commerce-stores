<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayWayPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeOrder(User $user, float $total = 25.00, string $status = 'pending'): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'total_price' => $total,
            'status' => $status,
        ]);
    }

    private function makePendingPaywayPayment(Order $order, string $tranId, float $amount): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'payment_method' => 'PAYWAY',
            'payment_status' => 'PENDING',
            'amount' => $amount,
            'currency' => 'USD',
            'transaction_id' => $tranId,
        ]);
    }

    /**
     * Fake a successful PayWay check-transaction response.
     */
    private function fakeSuccessfulCheck(): void
    {
        Http::fake([
            '*/api/payment-gateway/v1/payments/check-transaction-2' => Http::response([
                'data' => [
                    'payment_status_code' => 0,
                    'total_amount' => 25.00,
                    'payment_amount' => 25.00,
                    'payment_currency' => 'USD',
                    'apv' => 'PAYWAY_APV_001',
                    'payment_status' => 'APPROVED',
                    'transaction_date' => '2026-09-14 10:00:00',
                ],
                'status' => [
                    'code' => '00',
                    'message' => 'Success!',
                ],
            ], 200),
        ]);
    }

    public function test_authenticated_user_can_create_payway_payment_for_own_order(): void
    {
        Http::fake([
            '*/api/payment-gateway/v1/payments/purchase' => Http::response(
                '<html><body>PayWay Checkout</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $user = $this->makeUser();
        $order = $this->makeOrder($user, 25.00);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/payway', [
                'order_id' => $order->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.amount', 25)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.status', 'PENDING');

        $this->assertStringStartsWith('TXN-'.$order->id.'-', $response->json('data.transaction_id'));

        $this->assertNotEmpty($response->json('data.checkout_html'));

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'payment_method' => 'PAYWAY',
            'payment_status' => 'PENDING',
            'amount' => 25.00,
            'currency' => 'USD',
        ]);
    }

    public function test_unauthenticated_user_cannot_create_payway_payment(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, 25.00);

        $response = $this->postJson('/api/payments/payway', [
            'order_id' => $order->id,
        ]);

        $response->assertStatus(401);
    }

    public function test_user_cannot_create_payway_payment_for_another_users_order(): void
    {
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $order = $this->makeOrder($user2, 50.00);

        $response = $this->actingAs($user1, 'sanctum')
            ->postJson('/api/payments/payway', [
                'order_id' => $order->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_cannot_create_payway_payment_for_cancelled_order(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, 25.00, 'cancelled');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/payway', [
                'order_id' => $order->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Cannot create payment for a cancelled order.');
    }

    public function test_cannot_create_duplicate_successful_payment(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, 25.00);

        Payment::create([
            'order_id' => $order->id,
            'payment_method' => 'PAYWAY',
            'payment_status' => 'SUCCESS',
            'amount' => 25.00,
            'currency' => 'USD',
            'transaction_id' => 'TXN-EXISTING-000001',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/payway', [
                'order_id' => $order->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Order already has a successful payment.');
    }

    public function test_amount_is_taken_from_database_order_total(): void
    {
        Http::fake([
            '*/api/payment-gateway/v1/payments/purchase' => Http::response(
                '<html><body>PayWay Checkout</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $user = $this->makeUser();
        $order = $this->makeOrder($user, 55.50);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/payway', [
                'order_id' => $order->id,
                'amount' => 1.00,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.amount', 55.5);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'amount' => 55.50,
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'payments/purchase');
        });
    }

    public function test_successful_payway_status_check_changes_payment_to_success(): void
    {
        $this->fakeSuccessfulCheck();

        $user = $this->makeUser();
        $order = $this->makeOrder($user, 25.00);
        $payment = $this->makePendingPaywayPayment($order, 'TXN-ORDER-2601010001', 25.00);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/payments/{$payment->id}/payway/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'SUCCESS');

        $payment->refresh();
        $this->assertEquals('SUCCESS', $payment->payment_status);
        $this->assertEquals('TXN-ORDER-2601010001', $payment->transaction_hash);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_successful_payway_payment_changes_order_to_paid(): void
    {
        $this->fakeSuccessfulCheck();

        $user = $this->makeUser();
        $order = $this->makeOrder($user, 45.00, 'pending');
        $payment = $this->makePendingPaywayPayment($order, 'TXN-ORDER-2601010002', 45.00);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/payments/{$payment->id}/payway/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.order_status', 'PAID');

        $order->refresh();
        $this->assertEquals('PAID', $order->status);
    }

    public function test_failed_payway_transaction_changes_payment_to_failed(): void
    {
        Http::fake([
            '*/api/payment-gateway/v1/payments/check-transaction-2' => Http::response([
                'data' => [
                    'payment_status_code' => 3,
                    'payment_status' => 'DECLINED',
                ],
                'status' => [
                    'code' => '00',
                    'message' => 'Success!',
                ],
            ], 200),
        ]);

        $user = $this->makeUser();
        $order = $this->makeOrder($user, 20.00);
        $payment = $this->makePendingPaywayPayment($order, 'TXN-ORDER-2601010003', 20.00);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/payments/{$payment->id}/payway/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'FAILED');

        $payment->refresh();
        $this->assertEquals('FAILED', $payment->payment_status);

        $order->refresh();
        $this->assertEquals('pending', $order->status);
    }

    public function test_payway_timeout_keeps_payment_pending(): void
    {
        Http::fake([
            '*/api/payment-gateway/v1/payments/check-transaction-2' => Http::response('', 500),
        ]);

        $user = $this->makeUser();
        $order = $this->makeOrder($user, 20.00);
        $payment = $this->makePendingPaywayPayment($order, 'TXN-ORDER-2601010004', 20.00);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/payments/{$payment->id}/payway/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'PENDING');

        $payment->refresh();
        $this->assertEquals('PENDING', $payment->payment_status);
        $this->assertNull($payment->paid_at);

        $order->refresh();
        $this->assertEquals('pending', $order->status);
    }

    public function test_already_successful_payment_does_not_make_unnecessary_payway_calls(): void
    {
        Http::fake();

        $user = $this->makeUser();
        $order = $this->makeOrder($user, 30.00, 'PAID');
        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_method' => 'PAYWAY',
            'payment_status' => 'SUCCESS',
            'amount' => 30.00,
            'currency' => 'USD',
            'transaction_id' => 'TXN-ORDER-2601010005',
            'transaction_hash' => 'TXN-ORDER-2601010005',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/payments/{$payment->id}/payway/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'SUCCESS');

        Http::assertSentCount(0);
    }

    public function test_cannot_check_another_users_payway_status(): void
    {
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $order = $this->makeOrder($user2, 30.00);
        $payment = $this->makePendingPaywayPayment($order, 'TXN-ORDER-2601010006', 30.00);

        $response = $this->actingAs($user1, 'sanctum')
            ->getJson("/api/payments/{$payment->id}/payway/status");

        $response->assertStatus(403);
    }

    public function test_invalid_callback_is_rejected(): void
    {
        $response = $this->postJson('/api/payments/payway/callback', []);

        $response->assertStatus(400);

        $response = $this->postJson('/api/payments/payway/callback', [
            'tran_id' => 'UNKNOWN-TRAN-ID',
        ]);

        $response->assertStatus(404);
    }

    public function test_callback_verifies_and_marks_payment_success(): void
    {
        $this->fakeSuccessfulCheck();

        $user = $this->makeUser();
        $order = $this->makeOrder($user, 25.00);
        $payment = $this->makePendingPaywayPayment($order, 'TXN-ORDER-2601010007', 25.00);

        $response = $this->postJson('/api/payments/payway/callback', [
            'tran_id' => 'TXN-ORDER-2601010007',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $payment->refresh();
        $this->assertEquals('SUCCESS', $payment->payment_status);
        $this->assertNotNull($payment->paid_at);

        $order->refresh();
        $this->assertEquals('PAID', $order->status);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        Http::fake([
            '*/api/payment-gateway/v1/payments/check-transaction-2' => Http::response([
                'status' => [
                    'code' => 5,
                    'message' => 'Invalid hash',
                ],
            ], 200),
        ]);

        $user = $this->makeUser();
        $order = $this->makeOrder($user, 25.00);
        $payment = $this->makePendingPaywayPayment($order, 'TXN-ORDER-2601010009', 25.00);

        $response = $this->postJson('/api/payments/payway/callback', [
            'tran_id' => 'TXN-ORDER-2601010009',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', false);

        $payment->refresh();
        $this->assertEquals('PENDING', $payment->payment_status);

        $order->refresh();
        $this->assertEquals('pending', $order->status);
    }

    public function test_duplicate_callback_is_handled_safely(): void
    {
        $this->fakeSuccessfulCheck();

        $user = $this->makeUser();
        $order = $this->makeOrder($user, 25.00);
        $payment = $this->makePendingPaywayPayment($order, 'TXN-ORDER-2601010008', 25.00);

        $response1 = $this->postJson('/api/payments/payway/callback', [
            'tran_id' => 'TXN-ORDER-2601010008',
        ]);
        $response1->assertStatus(200)->assertJsonPath('success', true);

        $payment->refresh();
        $firstPaidAt = $payment->paid_at;

        $response2 = $this->postJson('/api/payments/payway/callback', [
            'tran_id' => 'TXN-ORDER-2601010008',
        ]);
        $response2->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Payment already processed.');

        $payment->refresh();
        $this->assertEquals($firstPaidAt->toIso8601String(), $payment->paid_at->toIso8601String());

        $this->assertEquals(1, Payment::where('order_id', $order->id)->count());
        $this->assertEquals('PAID', $order->refresh()->status);
    }
}
