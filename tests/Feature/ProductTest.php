<?php

use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('guest can list products with nested category', function () {
    Product::factory()->count(2)->create();

    $this->getJson('/api/products')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [['id', 'name', 'sku', 'price', 'is_active', 'category' => ['id', 'name']]],
            'meta' => ['current_page', 'total'],
        ]);
});

test('products are paginated', function () {
    Product::factory()->count(20)->create();

    $this->getJson('/api/products')
        ->assertOk()
        ->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.total', 20);
});

test('products can be filtered by category', function () {
    $coffee = Category::factory()->create();
    Product::factory()->count(2)->create(['category_id' => $coffee->id]);
    Product::factory()->create();

    $this->getJson("/api/products?category_id={$coffee->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('products can be filtered by is_active', function () {
    Product::factory()->count(2)->create(['is_active' => true]);
    Product::factory()->create(['is_active' => false]);

    $this->getJson('/api/products?is_active=0')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_active', false);
});

test('filtering by an unknown category returns an empty list', function () {
    Product::factory()->count(2)->create();

    $this->getJson('/api/products?category_id=999')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('products can be searched by name', function () {
    Product::factory()->create(['name' => 'Cafe Latte', 'sku' => 'COF-LAT']);
    Product::factory()->create(['name' => 'Americano', 'sku' => 'COF-AME']);

    $this->getJson('/api/products?search=latte')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Cafe Latte');
});

test('products can be searched by sku', function () {
    Product::factory()->create(['name' => 'Cafe Latte', 'sku' => 'COF-LAT']);
    Product::factory()->create(['name' => 'Americano', 'sku' => 'COF-AME']);

    $this->getJson('/api/products?search=COF-AME')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Americano');
});

test('guest can view a product with its category', function () {
    $product = Product::factory()->create();

    $this->getJson("/api/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.name', $product->name)
        ->assertJsonPath('data.price', (int) $product->price)
        ->assertJsonStructure(['data' => ['category' => ['id', 'name']]]);
});

test('viewing unknown product returns 404', function () {
    $this->getJson('/api/products/999')->assertNotFound();
});

test('guest cannot create product', function () {
    $this->postJson('/api/products', [])->assertUnauthorized();
});

test('cashier cannot create product', function () {
    $this->withToken(cashierToken())
        ->postJson('/api/products', [])
        ->assertForbidden()
        ->assertJson(['message' => 'Forbidden.']);
});

test('admin can create product', function () {
    $this->withToken(adminToken())
        ->postJson('/api/products', productPayload())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Cafe Latte')
        ->assertJsonPath('data.price', 22000)
        ->assertJsonPath('data.is_active', true);

    $this->assertDatabaseHas('products', ['sku' => 'COF-LAT', 'price' => 22000]);
});

test('creating product requires core fields', function () {
    $this->withToken(adminToken())
        ->postJson('/api/products', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category_id', 'name', 'sku', 'price']);
});

test('creating product rejects unknown category', function () {
    $this->withToken(adminToken())
        ->postJson('/api/products', productPayload(['category_id' => 99999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category_id']);
});

test('creating product rejects duplicate sku', function () {
    Product::factory()->create(['sku' => 'COF-LAT']);

    $this->withToken(adminToken())
        ->postJson('/api/products', productPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sku']);
});

test('admin can upload product image', function () {
    Storage::fake('public');

    $response = $this->withToken(adminToken())->post('/api/products', productPayload([
        'image' => UploadedFile::fake()->image('latte.jpg'),
    ]));

    $response->assertCreated();

    $path = Product::sole()->image;

    Storage::disk('public')->assertExists($path);
    expect($path)->toStartWith('products/')
        ->and($response->json('data.image'))->toContain($path);
});

test('upload rejects non image file', function () {
    Storage::fake('public');

    $this->withToken(adminToken())->post('/api/products', productPayload([
        'image' => UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf'),
    ]))->assertUnprocessable()->assertJsonValidationErrors(['image']);
});

test('upload rejects oversized image', function () {
    Storage::fake('public');

    $this->withToken(adminToken())->post('/api/products', productPayload([
        'image' => UploadedFile::fake()->image('huge.jpg')->size(3000),
    ]))->assertUnprocessable()->assertJsonValidationErrors(['image']);
});

test('admin can update product', function () {
    $product = Product::factory()->create(['price' => 18000]);

    $this->withToken(adminToken())
        ->putJson("/api/products/{$product->id}", productPayload([
            'category_id' => $product->category_id,
            'sku' => $product->sku,
            'name' => 'Iced Cafe Latte',
            'price' => 24000,
            'is_active' => false,
        ]))
        ->assertOk()
        ->assertJsonPath('data.name', 'Iced Cafe Latte')
        ->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'name' => 'Iced Cafe Latte',
        'price' => 24000,
        'is_active' => false,
    ]);
});

test('updating product replaces image file', function () {
    Storage::fake('public');
    $token = adminToken();

    $this->withToken($token)->post('/api/products', productPayload([
        'image' => UploadedFile::fake()->image('old.jpg'),
    ]))->assertCreated();

    $product = Product::sole();
    $oldPath = $product->image;

    $this->withToken($token)->put("/api/products/{$product->id}", productPayload([
        'category_id' => $product->category_id,
        'image' => UploadedFile::fake()->image('new.jpg'),
    ]))->assertOk();

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists(Product::sole()->image);
    expect(Product::sole()->image)->not->toBe($oldPath);
});

test('cashier cannot update product', function () {
    $product = Product::factory()->create();

    $this->withToken(cashierToken())
        ->putJson("/api/products/{$product->id}", productPayload())
        ->assertForbidden();
});

test('cashier cannot delete product', function () {
    $product = Product::factory()->create();

    $this->withToken(cashierToken())
        ->deleteJson("/api/products/{$product->id}")
        ->assertForbidden();
});

test('guest cannot update product', function () {
    $product = Product::factory()->create();

    $this->putJson("/api/products/{$product->id}", productPayload())
        ->assertUnauthorized();

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => $product->name]);
});

test('guest cannot delete product', function () {
    $product = Product::factory()->create();

    $this->deleteJson("/api/products/{$product->id}")->assertUnauthorized();

    $this->assertDatabaseHas('products', ['id' => $product->id]);
});

test('admin can delete product without orders', function () {
    $product = Product::factory()->create();

    $this->withToken(adminToken())
        ->deleteJson("/api/products/{$product->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('products', ['id' => $product->id]);
});

test('deleting a product that has been ordered is rejected', function () {
    $product = Product::factory()->create();
    OrderItem::factory()->create(['product_id' => $product->id]);

    $this->withToken(adminToken())
        ->deleteJson("/api/products/{$product->id}")
        ->assertStatus(409)
        ->assertJson(['message' => 'Cannot delete a product that has been ordered. Set is_active to false instead.']);

    $this->assertDatabaseHas('products', ['id' => $product->id]);
});
