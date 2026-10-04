<?php

test('guest cannot logout', function () {
    $this->postJson('/api/logout')->assertUnauthorized();
});

test('an invalid token is rejected with 401', function () {
    $this->withToken('garbage-token')->getJson('/api/me')->assertUnauthorized();
});

test('a malformed authorization header is rejected with 401', function () {
    $this->withHeaders(['Authorization' => 'Basic dXNlcjpwYXNz'])->getJson('/api/me')->assertUnauthorized();
});

test('an unknown api path returns a json 404', function () {
    $this->getJson('/api/does-not-exist')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message']);
});

test('an unsupported http method returns a json 405', function () {
    $this->deleteJson('/api/login')
        ->assertStatus(405)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message']);
});

test('validation errors return message and errors keys', function () {
    $this->postJson('/api/login', [])
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors']);
});

test('401 responses always carry a message', function () {
    $this->getJson('/api/me')
        ->assertUnauthorized()
        ->assertJsonStructure(['message']);
});
