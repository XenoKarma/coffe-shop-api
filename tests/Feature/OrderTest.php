<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;

test('guest cannot create order', function () {
    $this->postJson('/api/orders', [])->assertUnauthorized();
});

test('cashier can create a pending order', function () {
    $cashier = User::factory()->cashier()->create();
    $token = $cashier->createToken('test')->plainTextToken;
    $americano = Product::factory()->create(['name' => 'Americano', 'price' => 18000]);

    $response = $this->withToken($token)->postJson('/api/orders', [
        'items' => [
            ['product_id' => $americano->id, 'quantity' => 2],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'id',
                'order_number',
                'status',
                'subtotal',
                'discount',
                'tax',
                'total',
                'cashier' => ['id', 'name', 'email', 'role'],
                'items' => [['id', 'product_id', 'product_name', 'price', 'quantity', 'subtotal']],
                'created_at',
            ],
        ])
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.cashier.id', $cashier->id)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.product_name', 'Americano');

    expect($response->json('data.order_number'))->toMatch('/^ORD-\d{8}-[A-Z0-9]{5}$/');

    $this->assertDatabaseHas('orders', [
        'user_id' => $cashier->id,
        'status' => 'pending',
        'subtotal' => 36000,
        'total' => 36000,
    ]);
});

test('admin can create order', function () {
    $product = Product::factory()->create(['price' => 18000]);

    $this->withToken(adminToken())
        ->postJson('/api/orders', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');
});

test('total is calculated from database prices for multiple items', function () {
    $token = adminToken();
    $americano = Product::factory()->create(['name' => 'Americano', 'price' => 18000]);
    $fries = Product::factory()->create(['name' => 'French Fries', 'price' => 18000]);

    $this->withToken($token)->postJson('/api/orders', [
        'items' => [
            ['product_id' => $americano->id, 'quantity' => 2],
            ['product_id' => $fries->id, 'quantity' => 1],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.subtotal', 54000)
        ->assertJsonPath('data.total', 54000)
        ->assertJsonCount(2, 'data.items');

    $this->assertDatabaseCount('order_items', 2);
    $this->assertDatabaseHas('order_items', [
        'product_name' => 'Americano',
        'price' => 18000,
        'quantity' => 2,
        'subtotal' => 36000,
    ]);
    $this->assertDatabaseHas('order_items', [
        'product_name' => 'French Fries',
        'price' => 18000,
        'quantity' => 1,
        'subtotal' => 18000,
    ]);
});

test('order item keeps its price snapshot after the product price changes', function () {
    $product = Product::factory()->create(['price' => 18000]);

    $this->withToken(adminToken())
        ->postJson('/api/orders', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated();

    $product->update(['price' => 99000]);

    $this->assertDatabaseHas('order_items', ['price' => 18000, 'subtotal' => 18000]);
    $this->assertDatabaseHas('orders', ['subtotal' => 18000, 'total' => 18000]);
});

test('inactive product is rejected and no order is created', function () {
    Product::factory()->create(['name' => 'Americano', 'is_active' => true]);
    $inactive = Product::factory()->create(['name' => 'Toasted Bread', 'is_active' => false]);

    $this->withToken(adminToken())
        ->postJson('/api/orders', [
            'items' => [
                ['product_id' => $inactive->id, 'quantity' => 1],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items.0.product_id']);

    $this->assertDatabaseCount('orders', 0);
});

test('unknown product is rejected', function () {
    $this->withToken(adminToken())
        ->postJson('/api/orders', [
            'items' => [
                ['product_id' => 99999, 'quantity' => 1],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items.0.product_id']);

    $this->assertDatabaseCount('orders', 0);
});

test('quantity must be at least one', function () {
    $product = Product::factory()->create();

    $this->withToken(adminToken())
        ->postJson('/api/orders', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 0],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items.0.quantity']);
});

test('order requires items', function () {
    $this->withToken(adminToken())
        ->postJson('/api/orders', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items']);
});

test('order requires at least one item', function () {
    $this->withToken(adminToken())
        ->postJson('/api/orders', ['items' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items']);
});

test('duplicate products in one order are rejected', function () {
    $product = Product::factory()->create();

    $this->withToken(adminToken())
        ->postJson('/api/orders', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items']);

    $this->assertDatabaseCount('orders', 0);
});

test('order numbers are unique', function () {
    $token = adminToken();
    $product = Product::factory()->create();
    $payload = ['items' => [['product_id' => $product->id, 'quantity' => 1]]];

    $first = $this->withToken($token)->postJson('/api/orders', $payload);
    $second = $this->withToken($token)->postJson('/api/orders', $payload);

    $first->assertCreated();
    $second->assertCreated();

    expect($second->json('data.order_number'))->not->toBe($first->json('data.order_number'));
});

test('order creation rolls back when saving items fails', function () {
    $product = Product::factory()->create();

    $listener = function () {
        throw new RuntimeException('Simulated failure while saving order items.');
    };

    OrderItem::creating($listener);

    try {
        $this->withToken(adminToken())
            ->postJson('/api/orders', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ])
            ->assertStatus(500);
    } finally {
        OrderItem::getEventDispatcher()->forget('eloquent.creating: '.OrderItem::class);
    }

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
});

test('guest cannot list orders', function () {
    $this->getJson('/api/orders')->assertUnauthorized();
});

test('guest cannot view an order', function () {
    $order = pendingOrder();

    $this->getJson("/api/orders/{$order->id}")->assertUnauthorized();
});

test('cashier can list orders with items and payment', function () {
    $order = Order::factory()->create(['status' => 'paid', 'total' => 54000]);
    OrderItem::factory()->create(['order_id' => $order->id]);
    Payment::factory()->create([
        'order_id' => $order->id,
        'amount' => 54000,
        'received_amount' => 100000,
        'change_amount' => 46000,
    ]);

    $this->withToken(cashierToken())
        ->getJson('/api/orders')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonStructure([
            'data' => [[
                'id',
                'order_number',
                'status',
                'total',
                'cashier' => ['id', 'name', 'role'],
                'items' => [['product_name', 'price', 'quantity', 'subtotal']],
                'payment' => ['id', 'method', 'paid_amount', 'change', 'paid_at'],
                'created_at',
            ]],
            'meta' => ['current_page', 'total'],
        ])
        ->assertJsonPath('data.0.payment.paid_amount', 100000)
        ->assertJsonPath('data.0.payment.change', 46000);
});

test('order list can be filtered by status', function () {
    $pending = Order::factory()->create(['status' => 'pending']);
    Order::factory()->create(['status' => 'paid']);

    $this->withToken(cashierToken())
        ->getJson('/api/orders?status=pending')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pending->id);
});

test('order list can be filtered by date', function () {
    $today = Order::factory()->create();
    Order::factory()->create(['created_at' => now()->subDays(3)]);

    $this->withToken(cashierToken())
        ->getJson('/api/orders?date='.now()->format('Y-m-d'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $today->id);
});

test('order list can be filtered by cashier', function () {
    $first = User::factory()->cashier()->create();
    $second = User::factory()->cashier()->create();
    $order = Order::factory()->create(['user_id' => $first->id]);
    Order::factory()->create(['user_id' => $second->id]);

    $this->withToken(cashierToken())
        ->getJson("/api/orders?cashier_id={$first->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $order->id);
});

test('order list rejects an invalid date filter', function () {
    $this->withToken(cashierToken())
        ->getJson('/api/orders?date=not-a-date')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);
});

test('order detail shows items, cashier and payment', function () {
    $order = Order::factory()->create(['status' => 'paid', 'total' => 54000]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_name' => 'Americano',
        'price' => 18000,
        'quantity' => 2,
        'subtotal' => 36000,
    ]);
    Payment::factory()->create([
        'order_id' => $order->id,
        'amount' => 54000,
        'received_amount' => 100000,
        'change_amount' => 46000,
    ]);

    $this->withToken(cashierToken())
        ->getJson("/api/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.total', 54000)
        ->assertJsonPath('data.items.0.product_name', 'Americano')
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.payment.paid_amount', 100000)
        ->assertJsonPath('data.payment.change', 46000)
        ->assertJsonPath('data.cashier.id', $order->user_id);
});

test('pending order detail has no payment yet', function () {
    $order = pendingOrder();

    $response = $this->withToken(cashierToken())
        ->getJson("/api/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    expect($response->json('data'))->toHaveKey('payment')
        ->and($response->json('data.payment'))->toBeNull();
});

test('viewing unknown order returns 404', function () {
    $this->withToken(cashierToken())
        ->getJson('/api/orders/999')
        ->assertNotFound();
});

test('admin can view an order detail', function () {
    $order = pendingOrder();

    $this->withToken(adminToken())
        ->getJson("/api/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $order->id)
        ->assertJsonPath('data.status', 'pending');
});
