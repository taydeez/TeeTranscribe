<?php

use App\Infrastructure\Persistence\Eloquent\Models\GuestSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('creates a guest session and returns its identifier without exposing its token hash', function () {
    $response = $this->postJson('/api/v1/guest-sessions')->assertOk();

    $id = $response->json('id');
    expect($id)->toBeString();
    $this->assertDatabaseHas('guest_sessions', ['id' => $id]);
    expect($response->json())->toHaveKeys(['id']);
    expect($response->json())->not->toHaveKey('token_hash');
    expect(GuestSession::query()->find($id)?->token_hash)->not->toBeEmpty();
});
