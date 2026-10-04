<?php

use App\Models\Category;
use App\Models\Product;

test('guest can list categories', function () {
    Category::factory()->count(3)->create();

    $this->getJson('/api/categories')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['data' => [['id', 'name', 'description']]]);
});

test('guest can view a category', function () {
    $category = Category::factory()->create();

    $this->getJson("/api/categories/{$category->id}")
        ->assertOk()
        ->assertJsonPath('data.name', $category->name);
});

test('viewing unknown category returns 404', function () {
    $this->getJson('/api/categories/999')->assertNotFound();
});

test('guest cannot create category', function () {
    $this->postJson('/api/categories', ['name' => 'Tea'])->assertUnauthorized();
});

test('cashier cannot create category', function () {
    $this->withToken(cashierToken())
        ->postJson('/api/categories', ['name' => 'Tea'])
        ->assertForbidden()
        ->assertJson(['message' => 'Forbidden.']);
});

test('admin can create category', function () {
    $this->withToken(adminToken())
        ->postJson('/api/categories', [
            'name' => 'Tea',
            'description' => 'Tea based beverages',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Tea');

    $this->assertDatabaseHas('categories', ['name' => 'Tea']);
});

test('creating category requires name', function () {
    $this->withToken(adminToken())
        ->postJson('/api/categories', ['description' => 'missing name'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('extra fields sent by the client are ignored', function () {
    $this->withToken(adminToken())
        ->postJson('/api/categories', [
            'name' => 'Tea',
            'id' => 999,
            'created_at' => '2000-01-01 00:00:00',
        ])
        ->assertCreated();

    $category = Category::sole();

    expect($category->id)->not->toBe(999)
        ->and($category->created_at->year)->not->toBe(2000);
});

test('admin can update category', function () {
    $category = Category::factory()->create();

    $this->withToken(adminToken())
        ->putJson("/api/categories/{$category->id}", ['name' => 'Cold Brew'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Cold Brew');

    $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Cold Brew']);
});

test('updating category requires name', function () {
    $category = Category::factory()->create();

    $this->withToken(adminToken())
        ->putJson("/api/categories/{$category->id}", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('cashier cannot update category', function () {
    $category = Category::factory()->create();

    $this->withToken(cashierToken())
        ->putJson("/api/categories/{$category->id}", ['name' => 'Hacked'])
        ->assertForbidden();

    $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => $category->name]);
});

test('guest cannot update category', function () {
    $category = Category::factory()->create();

    $this->putJson("/api/categories/{$category->id}", ['name' => 'Hacked'])->assertUnauthorized();

    $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => $category->name]);
});

test('guest cannot delete category', function () {
    $category = Category::factory()->create();

    $this->deleteJson("/api/categories/{$category->id}")->assertUnauthorized();

    $this->assertDatabaseHas('categories', ['id' => $category->id]);
});

test('cashier cannot delete category', function () {
    $category = Category::factory()->create();

    $this->withToken(cashierToken())
        ->deleteJson("/api/categories/{$category->id}")
        ->assertForbidden()
        ->assertJson(['message' => 'Forbidden.']);

    $this->assertDatabaseHas('categories', ['id' => $category->id]);
});

test('admin can delete a category without products', function () {
    $category = Category::factory()->create();

    $this->withToken(adminToken())
        ->deleteJson("/api/categories/{$category->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
});

test('deleting a category with products is rejected', function () {
    $category = Category::factory()->create();
    Product::factory()->create(['category_id' => $category->id]);

    $this->withToken(adminToken())
        ->deleteJson("/api/categories/{$category->id}")
        ->assertStatus(409)
        ->assertJson(['message' => 'Cannot delete category with existing products.']);

    $this->assertDatabaseHas('categories', ['id' => $category->id]);
});
