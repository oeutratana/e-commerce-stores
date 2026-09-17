<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BakongPaymentTest extends TestCase
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

    public function test_authenticated_user_can_create_khqr_payment_for_own_order(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, 25.00);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/khqr', [
                'order_id' => $order->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.amount', 25)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.status', 'PENDING');

        $this->assertNotEmpty($response->json('data.qr'));
        $this->assertNotEmpty($response->json('data.md5'));

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'payment_method' => 'KHQR',
            'payment_status' => 'PENDING',
            'amount' => 25.00,
            'currency' => 'USD',
        ]);
    }

    public function test_user_cannot_create_payment_for_another_users_order(): void
    {
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $order = $this->makeOrder($user2, 50.00);

        $response = $this->actingAs($user1, 'sanctum')
            ->postJson('/api/payments/khqr', [
                'order_id' => $order->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_cannot_create_payment_for_already_paid_order(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, 25.00, 'PAID');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/khqr', [
                'order_id' => $order->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Order is already paid.');
    }

    public function test_cannot_create_payment_for_cancelled_order(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, 25.00, 'cancelled');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/khqr', [
                'order_id' => $order->id,
            ]);

        $response->assertStatus(422);
    }

    public function test_payment_starts_as_pending(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, 15.50);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/khqr', [
                'order_id' => $order->id,
            ]);

        $response->assertStatus(201);
        $paymentId = $response->json('data.payment_id');

        $payment = Payment::find($paymentId);
        $this->assertNotNull($payment);
        $this->assertEquals('PENDING', $payment->payment_status);
        $this->assertNull($payment->paid_at);
        $this->assertNotNull($payment->qr_data);
        $this->assertNotNull($payment->md5);
    }

    public function test_payment_status_endpoint_requires_authentication(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, 30.00);
        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_method' => 'KHQR',
            'payment_status' => 'PENDING',
            'amount' => 30.00,
            'currency' => 'USD',
            'md5' => 'dummy_md5_hash',
        ]);

        $response = $this->getJson("/api/payments/{$payment->id}/status");
        $response->assertStatus(401);
    }

    public function test_user_cannot_view_another_users_payment_status(): void
    {
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $order = $this->makeOrder($user2, 30.00);
        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_method' => 'KHQR',
            'payment_status' => 'PENDING',
            'amount' => 30.00,
            'currency' => 'USD',
            'md5' => 'dummy_md5_hash',
        ]);

        $response = $this->actingAs($user1, 'sanctum')
            ->getJson("/api/payments/{$payment->id}/status");

        $response->assertStatus(403);
    }

    public function test_successful_bakong_verification_changes_payment_to_success(): void
    {
        Http::fake([
            '*/v1/check_transaction_by_md5' => Http::response([
                'responseCode' => 0,
                'responseMessage' => 'Success',
                'data' => [
                    'hash' => 'bakong_tx_hash_12345',
                    'amount' => 25.00,
                    'currency' => 'USD',
                ],
            ], 200),
        ]);

        $user = $this->makeUser();
        $order = $this->makeOrder($user, 25.00);
        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_method' => 'KHQR',
            'payment_status' => 'PENDING',
            'amount' => 25.00,
            'currency' => 'USD',
            'md5' => 'md5_pending_hash_123',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/payments/{$payment->id}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'SUCCESS')
            ->assertJsonPath('data.transaction_hash', 'bakong_tx_hash_12345');

        $payment->refresh();
        $this->assertEquals('SUCCESS', $payment->payment_status);
        $this->assertEquals('bakong_tx_hash_12345', $payment->transaction_hash);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_successful_payment_changes_order_to_paid(): void
    {
        Http::fake([
            '*/v1/check_transaction_by_md5' => Http::response([
                'responseCode' => 0,
                'responseMessage' => 'Success',
                'data' => [
                    'hash' => 'bakong_hash_order_paid',
                    'amount' => 45.00,
                    'currency' => 'USD',
                ],
            ], 200),
        ]);

        $user = $this->makeUser();
        $order = $this->makeOrder($user, 45.00, 'pending');
        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_method' => 'KHQR',
            'payment_status' => 'PENDING',
            'amount' => 45.00,
            'currency' => 'USD',
            'md5' => 'md5_hash_order_paid',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/payments/{$payment->id}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.order_status', 'PAID');

        $order->refresh();
        $this->assertEquals('PAID', $order->status);
    }

    public function test_repeated_verification_does_not_create_duplicate_payment_effects(): void
    {
        Http::fake([
            '*/v1/check_transaction_by_md5' => Http::response([
                'responseCode' => 0,
                'responseMessage' => 'Success',
                'data' => [
                    'hash' => 'first_verification_hash',
                    'amount' => 10.00,
                    'currency' => 'USD',
                ],
            ], 200),
        ]);

        $user = $this->makeUser();
        $order = $this->makeOrder($user, 10.00);
        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_method' => 'KHQR',
            'payment_status' => 'PENDING',
            'amount' => 10.00,
            'currency' => 'USD',
            'md5' => 'md5_test_idempotent',
        ]);

        // First call: verifies with Bakong and updates to SUCCESS
        $response1 = $this->actingAs($user, 'sanctum')
            ->getJson("/api/payments/{$payment->id}/status");
        $response1->assertStatus(200)->assertJsonPath('data.status', 'SUCCESS');

        $payment->refresh();
        $firstPaidAt = $payment->paid_at;

        // Second call: already SUCCESS, should return SUCCESS immediately without re-updating
        Http::assertSentCount(1);

        $response2 = $this->actingAs($user, 'sanctum')
            ->getJson("/api/payments/{$payment->id}/status");
        $response2->assertStatus(200)->assertJsonPath('data.status', 'SUCCESS');

        // External Bakong endpoint was not called again
        Http::assertSentCount(1);

        $payment->refresh();
        $this->assertEquals($firstPaidAt->toIso8601String(), $payment->paid_at->toIso8601String());
        $this->assertEquals('PAID', $order->refresh()->status);
    }

    public function test_bakong_api_failure_is_handled_correctly(): void
    {
        Http::fake([
            '*/v1/check_transaction_by_md5' => Http::response([
                'message' => 'Service Unavailable',
            ], 500),
        ]);

        $user = $this->makeUser();
        $order = $this->makeOrder($user, 20.00, 'pending');
        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_method' => 'KHQR',
            'payment_status' => 'PENDING',
            'amount' => 20.00,
            'currency' => 'USD',
            'md5' => 'md5_failure_test',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/payments/{$payment->id}/status");

        // Should return 200 with status still PENDING, order remaining unchanged
        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'PENDING');

        $payment->refresh();
        $this->assertEquals('PENDING', $payment->payment_status);
        $this->assertNull($payment->paid_at);

        $order->refresh();
        $this->assertEquals('pending', $order->status);
    }
}
