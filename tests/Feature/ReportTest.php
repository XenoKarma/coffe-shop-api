<?php

use App\Models\Order;
use App\Models\OrderItem;

test('guest cannot view the sales report', function () {
    $this->getJson('/api/reports/sales')->assertUnauthorized();
});

test('cashier cannot view the sales report', function () {
    $this->withToken(cashierToken())
        ->getJson('/api/reports/sales')
        ->assertForbidden()
        ->assertJson(['message' => 'Forbidden.']);
});

test('admin can view the sales report structure', function () {
    $this->withToken(adminToken())
        ->getJson('/api/reports/sales')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'today' => ['sales', 'orders'],
                'month' => ['sales', 'orders'],
                'total' => ['sales', 'orders'],
                'best_sellers',
            ],
        ]);
});

test('report returns zeros when there are no paid orders', function () {
    $token = adminToken();

    $response = $this->withToken($token)
        ->getJson('/api/reports/sales')
        ->assertOk()
        ->assertJsonPath('data.today.sales', 0)
        ->assertJsonPath('data.today.orders', 0)
        ->assertJsonPath('data.total.sales', 0);

    expect($response->json('data.best_sellers'))->toBe([]);
});

test('today sales only count paid orders created today', function () {
    Order::factory()->count(2)->create(['status' => 'paid', 'total' => 54000]);
    Order::factory()->create(['status' => 'pending', 'total' => 99000]);
    Order::factory()->create(['status' => 'paid', 'total' => 77000, 'created_at' => now()->subDay()]);

    $this->withToken(adminToken())
        ->getJson('/api/reports/sales')
        ->assertOk()
        ->assertJsonPath('data.today.sales', 108000)
        ->assertJsonPath('data.today.orders', 2);
});

test('monthly sales skip orders from previous months', function () {
    Order::factory()->create(['status' => 'paid', 'total' => 22000]);
    Order::factory()->create([
        'status' => 'paid',
        'total' => 33000,
        'created_at' => now()->startOfMonth()->subSecond(),
    ]);

    $this->withToken(adminToken())
        ->getJson('/api/reports/sales')
        ->assertOk()
        ->assertJsonPath('data.month.sales', 22000)
        ->assertJsonPath('data.month.orders', 1);
});

test('total counts every paid order regardless of date', function () {
    Order::factory()->count(2)->create(['status' => 'paid', 'total' => 10000]);
    Order::factory()->create(['status' => 'pending', 'total' => 99000]);

    $this->withToken(adminToken())
        ->getJson('/api/reports/sales')
        ->assertOk()
        ->assertJsonPath('data.total.sales', 20000)
        ->assertJsonPath('data.total.orders', 2);
});

test('best sellers are ranked by quantity across paid orders only', function () {
    $latteOrder = Order::factory()->create(['status' => 'paid']);
    OrderItem::factory()->create([
        'order_id' => $latteOrder->id,
        'product_name' => 'Cafe Latte',
        'quantity' => 5,
        'subtotal' => 110000,
    ]);

    $americanoOrder = Order::factory()->create(['status' => 'paid']);
    OrderItem::factory()->create([
        'order_id' => $americanoOrder->id,
        'product_name' => 'Americano',
        'quantity' => 2,
        'subtotal' => 36000,
    ]);

    $pendingOrder = Order::factory()->create(['status' => 'pending']);
    OrderItem::factory()->create([
        'order_id' => $pendingOrder->id,
        'product_name' => 'French Fries',
        'quantity' => 10,
        'subtotal' => 180000,
    ]);

    $response = $this->withToken(adminToken())
        ->getJson('/api/reports/sales')
        ->assertOk()
        ->assertJsonPath('data.best_sellers.0.product_name', 'Cafe Latte')
        ->assertJsonPath('data.best_sellers.0.quantity', 5)
        ->assertJsonPath('data.best_sellers.0.sales', 110000)
        ->assertJsonPath('data.best_sellers.1.product_name', 'Americano');

    expect($response->json('data.best_sellers'))->toHaveCount(2);
});
