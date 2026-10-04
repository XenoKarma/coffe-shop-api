<?php

use App\Models\User;

test('admin can access admin routes', function () {
    $this->withToken(adminToken())
        ->postJson('/api/categories', ['name' => 'Tea'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Tea');
});

test('cashier cannot access admin routes', function () {
    $this->withToken(cashierToken())
        ->postJson('/api/categories', ['name' => 'Tea'])
        ->assertForbidden()
        ->assertJson(['message' => 'Forbidden.']);
});

test('guest cannot access admin routes', function () {
    $this->postJson('/api/categories', ['name' => 'Tea'])->assertUnauthorized();
});

test('admin can access staff routes', function () {
    $this->withToken(adminToken())
        ->getJson('/api/orders')
        ->assertOk()
        ->assertJsonStructure(['data']);
});

test('cashier can access staff routes', function () {
    $this->withToken(cashierToken())
        ->getJson('/api/orders')
        ->assertOk()
        ->assertJsonStructure(['data']);
});

test('admin routes are unreachable with a forged role', function () {
    $user = User::factory()->cashier()->create();
    $user->forceFill(['role' => 'superuser'])->save();

    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/api/categories', ['name' => 'Tea'])
        ->assertForbidden()
        ->assertJson(['message' => 'Forbidden.']);
});
