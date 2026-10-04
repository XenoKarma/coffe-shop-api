<?php

use App\Models\Order;
use App\Models\Payment;

test('guest cannot pay for an order', function () {
    $order = pendingOrder();

    $this->patchJson("/api/orders/{$order->id}/pay", ['paid_amount' => 54000])
        ->assertUnauthorized();
});

test('cashier can pay the exact total', function () {
    $order = pendingOrder(54000);

    $response = $this->withToken(cashierToken())
        ->patchJson("/api/orders/{$order->id}/pay", ['paid_amount' => 54000]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.payment.method', 'cash')
        ->assertJsonPath('data.payment.paid_amount', 54000)
        ->assertJsonPath('data.payment.change', 0);

    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    $this->assertDatabaseHas('payments', [
        'order_id' => $order->id,
        'method' => 'cash',
        'amount' => 54000,
        'received_amount' => 54000,
        'change_amount' => 0,
    ]);

    expect(Payment::sole()->paid_at)->not->toBeNull();
});

test('admin can pay for an order', function () {
    $order = pendingOrder(54000);

    $this->withToken(adminToken())
        ->patchJson("/api/orders/{$order->id}/pay", ['paid_amount' => 60000])
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.payment.change', 6000);
});

test('overpayment returns the correct change', function () {
    $order = pendingOrder(54000);

    $this->withToken(cashierToken())
        ->patchJson("/api/orders/{$order->id}/pay", ['paid_amount' => 100000])
        ->assertOk()
        ->assertJsonPath('data.payment.paid_amount', 100000)
        ->assertJsonPath('data.payment.change', 46000);

    $this->assertDatabaseHas('payments', [
        'order_id' => $order->id,
        'received_amount' => 100000,
        'change_amount' => 46000,
    ]);
});

test('insufficient payment is rejected and the order stays pending', function () {
    $order = pendingOrder(54000);

    $this->withToken(cashierToken())
        ->patchJson("/api/orders/{$order->id}/pay", ['paid_amount' => 10000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['paid_amount']);

    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
});

test('an already paid order cannot be paid again', function () {
    $order = Order::factory()->create(['status' => 'paid']);

    $this->withToken(cashierToken())
        ->patchJson("/api/orders/{$order->id}/pay", ['paid_amount' => 50000])
        ->assertStatus(409)
        ->assertJson(['message' => 'Order is not pending.']);

    $this->assertDatabaseCount('payments', 0);
});

test('paid_amount is required', function () {
    $order = pendingOrder();

    $this->withToken(cashierToken())
        ->patchJson("/api/orders/{$order->id}/pay", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['paid_amount']);
});

test('paid_amount must be numeric', function () {
    $order = pendingOrder();

    $this->withToken(cashierToken())
        ->patchJson("/api/orders/{$order->id}/pay", ['paid_amount' => 'lots of money'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['paid_amount']);
});

test('payment failure rolls back the status change', function () {
    $order = pendingOrder(54000);

    $listener = function () {
        throw new RuntimeException('Simulated failure while saving payment.');
    };

    Payment::creating($listener);

    try {
        $this->withToken(cashierToken())
            ->patchJson("/api/orders/{$order->id}/pay", ['paid_amount' => 54000])
            ->assertStatus(500);
    } finally {
        Payment::getEventDispatcher()->forget('eloquent.creating: '.Payment::class);
    }

    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
});
