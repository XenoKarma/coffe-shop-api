<?php

use App\Models\User;

test('login returns token and user without password', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'message',
            'token',
            'user' => ['id', 'name', 'email', 'role'],
        ])
        ->assertJsonPath('user.email', $user->email);

    expect($response->json('user'))->not->toHaveKey('password');
});

test('login fails with wrong password', function () {
    $user = User::factory()->create();

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(401)->assertJson(['message' => 'Invalid Credentials.']);
});

test('login fails with unknown email', function () {
    User::factory()->create();

    $this->postJson('/api/login', [
        'email' => 'nobody@nara.test',
        'password' => 'password',
    ])->assertStatus(401)->assertJson(['message' => 'Invalid Credentials.']);
});

test('login requires email and password', function () {
    $this->postJson('/api/login', [])->assertStatus(422);
});

test('me requires authentication', function () {
    $this->getJson('/api/me')->assertStatus(401);
});

test('me returns the authenticated user', function () {
    $user = User::factory()->admin()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.role', 'admin');
});

test('me never exposes sensitive fields', function () {
    $user = User::factory()->admin()->create();
    $token = $user->createToken('test')->plainTextToken;

    $data = $this->withToken($token)->getJson('/api/me')
        ->assertOk()
        ->assertJsonStructure(['data' => ['id', 'name', 'email', 'role']])
        ->json('data');

    expect($data)
        ->not->toHaveKey('password')
        ->not->toHaveKey('remember_token');
});

test('login is rate limited after five attempts', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(429);
});

test('logout revokes the token', function () {
    $user = User::factory()->cashier()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)->postJson('/api/logout')->assertOk();

    $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);

    // Sanctum's RequestGuard caches the resolved user. In production each HTTP
    // request boots a fresh app, but in a test the app instance is shared
    // across requests, so we must clear the guard to simulate the next request.
    $this->app->make('auth')->forgetGuards();

    $this->withToken($token)->getJson('/api/me')->assertStatus(401);
});
